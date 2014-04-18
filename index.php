<?php

/*
 * @author: rmorenp
 */

require 'vendor/autoload.php';
require_once 'q2a/question2answer/qa-include/qa-base.php';
require_once 'q2a/question2answer/qa-include/qa-db-users.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Exception\ParseException;

try {
    $config = Yaml::parse(file_get_contents('app/config/config.yml'));
    $parameters = Yaml::parse(file_get_contents('app/config/parameters.yml'));
} catch (ParseException $e) {
    printf("Unable to parse the YAML string: %s", $e->getMessage());
}

if ($config['q2a-loader']) {
    foreach ($config['q2a-loader'] as $q2aFile) {
        require_once QA_INCLUDE_DIR . $q2aFile . '.php';
    }
}

// Display Errors Debug
error_reporting(E_ALL);
ini_set('display_errors', 1);

function s2qa_vote_set($post, $userid, $vote)
    /*
        Actually set (application level) the $vote (-1/0/1) by $userid (with $handle and $cookieid) on $postid.
        Handles user points, recounting and event reports as appropriate.
    */
{
    require_once QA_INCLUDE_DIR . 'qa-db-points.php';
    require_once QA_INCLUDE_DIR . 'qa-db-hotness.php';
    require_once QA_INCLUDE_DIR . 'qa-db-votes.php';
    require_once QA_INCLUDE_DIR . 'qa-db-post-create.php';
    require_once QA_INCLUDE_DIR . 'qa-app-limits.php';

    $vote = (int)min(1, max(-1, $vote));
    $oldvote = (int)qa_db_uservote_get($post['postid'], $userid);

    qa_db_uservote_set($post['postid'], $userid, $vote);
    qa_db_post_recount_votes($post['postid']);

    $postisanswer = ($post['basetype'] == 'A');

    if ($postisanswer) {
        qa_db_post_acount_update($post['parentid']);
        qa_db_unupaqcount_update();
    }

    $columns = array();

    if (($vote > 0) || ($oldvote > 0))
        $columns[] = $postisanswer ? 'aupvotes' : 'qupvotes';

    if (($vote < 0) || ($oldvote < 0))
        $columns[] = $postisanswer ? 'adownvotes' : 'qdownvotes';

    qa_db_points_update_ifuser($userid, $columns);

    qa_db_points_update_ifuser($post['userid'], array($postisanswer ? 'avoteds' : 'qvoteds', 'upvoteds', 'downvoteds'));

    if ($post['basetype'] == 'Q')
        qa_db_hotness_update($post['postid']);
}

// Connection in MongoDB
$conn = new MongoClient($parameters['shapado-database']['connection']);
$dbname = $parameters['shapado-database']['database'];
$db = $conn->{$dbname};

// Bloque de generación de relación key-usuario para equivalencia de nombre
$collection = $db->users;

// Transform document object to array
$regs = $collection->find();
$array = iterator_to_array($regs);

echo "<pre>";

$usuarios = array();
$i = 0;
$countTwitter = 0;
$countOpenID = 0;

foreach ($array as $user) {
    if (!$user['email']) {
        $i++;
        if ($user['identity_url']) {
            $countOpenID++;
            $usuarios[$user['_id']] = array(
                'email' => $user['login'] . '@openid.migrate',
                'password' => $user['login'] . '@openid.migrate',
                'login' => $user['login'],
            );
        } else if (preg_match('/(_twitter)/i', $user['login'])) {
            $countTwitter++;
            $username = str_replace('_twitter', '', $user['login']);
            $usuarios[$user['_id']] = array(
                'email' => $username . '@twitter.migrate',
                'password' => $username . '@twitter.migrate',
                'login' => $username,
            );
        } else {
            $usuarios[$user['_id']] = array(
                'email' => $user['login'] . '@shapado.migrate',
                'password' => $user['login'] . '@shapado.migrate',
                'login' => $user['login'],
            );
        }
    } else {
        $usuarios[$user['_id']] = array(
            'email' => $user['email'],
            'password' => $user['email'],
            'login' => empty($user['login']) ? $user['email'] : $user['login'],
        );
    }
    qa_db_user_create($usuarios[$user['_id']]['email'], $usuarios[$user['_id']]['password'], $usuarios[$user['_id']]['login'], 'QA_USER_LEVEL_BASIC', '127.0.0.1');
}

echo 'Existen ' . count($usuarios) . ' usuarios con correos' . PHP_EOL
    . $countOpenID . ' usuarios con OpenID' . PHP_EOL
    . $countTwitter . ' usuarios con twitter' . PHP_EOL
    . ($i - $countOpenID - $countTwitter) . ' usuarios sin correo' . PHP_EOL;

// Get Question Document
$collection = $db->questions;

// Transform document object to array
$regs = $collection->find();
$array = iterator_to_array($regs);

// Debug
echo "<pre>";

// foreach para cada pregunta
foreach ($array as $question) {
    echo $question['title'];
    echo "<br/>";
    $type = 'Q'; // question
    $parentid = null; // does not follow another answer
    $title = $question['title'];
    $content = $question['body'];
    $format = ''; // plain text
    $categoryid = null; // assume no category
    $tags = $question['tags'];
    $userid = qa_db_user_find_by_email($usuarios[$question['user_id']]['email'])[0];

    // Crea Pregunta
    $post_id = qa_post_create($type, $parentid, $title, $content, $format, $categoryid, $tags, $userid);

    // Obtiene Document de votos
    $collection3 = $db->votes;

    $busqueda3 = array('voteable_id' => $question['_id']);

    $regs3 = $collection3->find($busqueda3);
    $array3 = iterator_to_array($regs3);
    foreach ($array3 as $questionVote) {

        s2qa_vote_set(array('basetype' => 'Q', 'userid' => $userid, 'postid' => $post_id, 'parentid' => 0), qa_db_user_find_by_email($usuarios[$questionVote['user_id']]['email'])[0], $questionVote['value']);

    }

    // Obtiene Document de comentarios (Respuestas que se usaron en Shapado)
    $collection2 = $db->comments;

    $busqueda2 = array('question_id' => $question['_id']);

    $regs2 = $collection2->find($busqueda2);
    $array2 = iterator_to_array($regs2);

    foreach ($array2 as $answer) {
        echo $answer['body'];
        echo "<br/>";
        $type = 'A'; // Awnwer
        $parentid = $post_id; // Question
        $title = $answer['title'];
        $content = $answer['body'];
        $format = ''; // plain text
        $categoryid = null; // assume no category
        $tags = $answer['tags'];
        $userid = qa_db_user_find_by_email($usuarios[$question['user_id']]['email'])[0];

        $id_ans = qa_post_create($type, $parentid, $title, $content, $format, $categoryid, $tags, $userid);

        // Obtiene Document de votos
        $collection4 = $db->votes;

        $busqueda4 = array('voteable_id' => $question['_id']);

        $regs4 = $collection4->find($busqueda4);
        $array4 = iterator_to_array($regs4);
        foreach ($array4 as $answerVote) {

            s2qa_vote_set(array('basetype' => 'A', 'userid' => $userid, 'postid' => $id_ans, 'parentid' => $post_id), qa_db_user_find_by_email($usuarios[$answerVote['user_id']]['email'])[0], $answerVote['value']);

        }
    }
    echo "<hr/>";
}

/*

foreach($array as $question) {

 $type = 'Q'; // question
 $parentid = null; // does not follow another answer
 $title = $question['title'];
 $content = $question['body'];
 $format = ''; // plain text
 $categoryid = null; // assume no category
 $tags = $question['tags'];
 $userid = qa_get_logged_in_userid();

 qa_post_create($type, $parentid, $title, $content, $format, $categoryid, $tags, $userid);
}

//echo "<pre>";
//print_r($array);

*/