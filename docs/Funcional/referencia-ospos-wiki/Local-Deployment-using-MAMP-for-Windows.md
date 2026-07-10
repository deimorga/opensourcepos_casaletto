
Descarga e instala [MAMP](https://tracker.iplocation.net/jaxn/), luego ve a C:\MAMP\htdocs y coloca ahí la carpeta extraída de masterpos. Ahora ve a localhost/MAMP y luego a phpMyAdmin y crea una base de datos con cualquier nombre y crea un usuario con todos los privilegios para la base de datos creada. Ahora importa el archivo database.sql desde la carpeta database de tu directorio pos y haz clic en Ir. La base de datos se crea. Ahora ve a la carpeta config y cambia database.php.tmpl a database.php y establece el nombre de la base de datos, el usuario y la contraseña de la base de datos que creaste a través de phpMyAdmin.

Ve a tu navegador y escribe localhost y selecciona tu proyecto de la lista. OSPOS se cargará. Ingresa el usuario y la contraseña, que son "admin" y "pointofsale" respectivamente.

# PROBLEMAS

Ve al Módulo de Ventas y si tu interfaz se queda bloqueada, tendrás que hacer un trabajo adicional. Ejecuta tu aplicación MAMP, que probablemente ya esté corriendo. Haz clic en "Preferences", ahora ve a la pestaña php y verifica qué versión de php está cargada en tu MAMP haciendo clic en la lista desplegable. Selecciona una versión de php 5.6.** (por ejemplo, 5.6.13 o 5.6.24).

si ninguna versión 5.6.** está disponible en la lista, sigue las instrucciones a continuación
Ve a C:\MAMP\bin\php y renombra todas las carpetas php no requeridas con otro nombre (por ejemplo, renombra php5.4.1 a x_php5.4.1). Solo 2 carpetas de aquí aparecerán en tu lista desplegable de php en MAMP.

Ahora ve a C:\MAMP\bin\php\php5.6.24\ y copia todos los archivos icu**53.dll de aquí y pégalos en C:\MAMP\bin\apache\bin.

Ahora ve a C:\MAMP\conf\php5.6.24 y abre el archivo php.ini y busca ;extension=php_intl.dll y elimina el punto y coma al inicio, y asegúrate también de que bcmath no esté comentado. Si no funciona, por favor lee el [issue 1607](https://github.com/opensourcepos/opensourcepos/issues/1607) para ver una lista detallada de extensiones.

Reinicia tu MAMP y ejecuta ospos de nuevo. Debería funcionar ahora. Disfruta. :)
