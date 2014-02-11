Shapado2Q2A
===========

Migrate Script Shapado to Question2Answer

## Instalación 
* Requiere ejecutar `composer install` o `composer.phar install` para instalar las dependencias que tiene el script. 
* Se debe crear la carpeta `q2a` (Se esta viendo porque no quedo agregada al repositorio git) y a su interior debe descomprimirse la carpeta `question2answer` quedando como ruta a la instalación `<BASE_PATH>/q2a/question2answer`
* Debe copiarse el archivo de su instalación de question2answer a `<BASE_PATH>/q2a/question2answer` (solo se requiere que tenga acceso a la base de datos.
* Debe copiar el archivo `<BASE_PATH>/app/config/parameters.yml.dist` a `<BASE_PATH>/app/config/parameters.yml` y completar los datos solicitados
* 

## Contribuciones
* Via Issues del proyecto Github

## TODO
* Generar definiciones abstactas de las rutas 
* Integrar la recuperación de usuarios a la recuperación de usuario y su respectiva nueva asociación
* Documentar metodos de migración
* Soportar actualizaciones parciales
* Finalizar implementación con monolog para generar bitacora o log de migración
* `<Propuestas que lleguen por medio de issues>`
