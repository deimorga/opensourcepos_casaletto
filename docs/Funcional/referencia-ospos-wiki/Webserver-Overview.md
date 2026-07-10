
[← Volver a Configuración](Configuration) | [Inicio](Home)

---

Esta página ofrece información adicional sobre servidores web, PHP y MySQL para los usuarios que la necesiten.

## Descripción General

PHP y otros scripts necesitan un servidor web y, en la mayoría de los casos, una base de datos MySQL para ejecutarse. Si no tienes un servidor web alojado en un proveedor de hosting, necesitarás un servidor web ejecutándose en tu computadora local (como XAMPP, WAMP o [Uniform Server](http://www.uniformserver.com/)).

## ¿Qué versión de PHP estoy ejecutando?
Carga este archivo (info.php) en tu carpeta public_html, luego ejecuta el script, mywebsite.com/info.php
copia este código

`<?php
// Show all information, defaults to INFO_ALL
phpinfo();
?>`

en el bloc de notas y guárdalo como Todos los Archivos (*.*) con el nombre info.php

puedes encontrar más información aquí: http://php.net/manual/en/function.phpinfo.php

## ¿Mudando tus archivos y sistema a una nueva computadora local?

Si usas Uniform Server, todo lo que necesitas para mudar tu sistema es simplemente respaldar y mover tus archivos. Es la razón principal por la que uso aplicaciones portables (http://portableapps.com/apps/) en primer lugar. Tendré que investigar más sobre cómo mudar otros servidores.

## Usando Uniform Server

Las versiones ZeroXIII de Uniform Server (http://www.uniformserver.com/) son versiones con
 módulos ZeroXIII preinstalados que producen un paquete de servidor WAMP estándar (Windows, Apache, MySQL
 y PHP). Después de extraerlos, los servidores están listos para
 ejecutarse ya sea desde una memoria USB o desde una PC. Aparte de cambiar
 la contraseña de MySQL (opcional) no se requiere ninguna configuración.

Uniform Server utiliza archivos autoextraíbles 7z. Los archivos están
 comprimidos usando 7z e incluyen el extractor 7-Zip. Para descomprimir,
 haz doble clic en el archivo exe, esto descomprime todas las carpetas y archivos
 contenidos en el archivo comprimido; nada se agrega al registro ni se
 instala. Si deseas ver y extraer carpetas/archivos
 individuales, descarga 7-z portable desde:
 http://portableapps.com/apps/utilities/7-zip_portable
