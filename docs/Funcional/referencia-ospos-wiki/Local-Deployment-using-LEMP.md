**NOTA: Esta guía utiliza versiones de PHP desactualizadas. OSPOS requiere PHP 8.1 a 8.4. Por favor adapta los números de versión de PHP según corresponda.**

Stack estándar Linux, Nginx, MariaDB, PHP 8.1+ y Adminer sobre Ubuntu

# 1: Actualizar Ubuntu

`sudo apt-get update`

`sudo apt-get upgrade`

# 2: Instalar el Servidor Web Nginx

`sudo apt-get install nginx`

### Después de la instalación, habilita el inicio automático de Nginx cuando Ubuntu arranque

`sudo systemctl enable nginx`

### Inicia Nginx con este comando

`sudo systemctl start nginx`

### Verifica su estado

`systemctl status nginx`

Ahora en la barra de direcciones de tu navegador, escribe `http://localhost` o `http://127.0.0.1` y presiona enter

Verás "Welcome to nginx!",
eso significa que nginx se instaló y está funcionando correctamente.
### Ahora necesitamos hacer que www-data (usuario de Nginx) sea el propietario del directorio raíz web

`sudo chown www-data /var/www/html -R`

# 3: Instalar MariaDB

`sudo apt-get install mariadb-server mariadb-client`

### El servidor MariaDB debería iniciarse automáticamente. Usa systemctl para verificar su estado.

`systemctl status mysql`

### Habilita el inicio automático de MariaDB cuando Ubuntu se reinicie

`sudo systemctl enable mysql`

### Ejecuta el script de seguridad post-instalación

`sudo mysql_secure_installation`

 Cuando te pida ingresar la contraseña root de MariaDB, presiona `enter` porque aún no has configurado la contraseña root. Luego ingresa `y` para establecer la contraseña root del servidor MariaDB.

 Después simplemente presiona Enter para responder todas las preguntas restantes. Esto eliminará el usuario anónimo, deshabilitará el inicio de sesión root remoto y eliminará la base de datos de prueba. Este paso es un requisito básico para la seguridad de la base de datos MariaDB.

# 4: Instalar PHP

`sudo apt-get install php-fpm php-mbstring php-xml php-mysql php-common php-gd php-json php-cli php-curl php-intl php-bcmath`

### Ahora inicia php-fpm

`sudo systemctl start php8.1-fpm`

### Verifica el estado de php

`systemctl status php8.1-fpm`


# 5: Crear un Archivo de Bloque de Servidor Nginx por Defecto


### Elimina el enlace simbólico "default.conf" en el directorio "sites-enabled"

`sudo rm /etc/nginx/sites-enabled/default`

### crea un nuevo archivo de bloque de servidor por defecto bajo el directorio /etc/nginx/conf.d/

`sudo nano /etc/nginx/conf.d/default.conf`

### Pega el siguiente texto en el archivo, guarda y ciérralo




	server {
		server_name localhost;
		root /var/www/html/;
		index index.php index.html index.htm;

		location / {
			try_files $uri $uri/ /index.php;
		}
    
		location /opensourcepos {
			try_files $uri $uri/ /opensourcepos/public/index.php;
		}
	
		location ~* ^.+.(jpg|jpeg|gif|css|png|js|ico|xml)$ {
			expires  15d;
		}

		location ~ \.php$ {
			include /etc/nginx/fastcgi_params;
			fastcgi_index  index.php;
			fastcgi_param  SCRIPT_FILENAME  /var/www/html/$fastcgi_script_name;
			fastcgi_param  REQUEST_URI      $request_uri;
			fastcgi_param  QUERY_STRING     $query_string;
			fastcgi_param  REQUEST_METHOD   $request_method;
			fastcgi_param  CONTENT_TYPE     $content_type;
			fastcgi_param  CONTENT_LENGTH   $content_length;
			fastcgi_pass unix:/var/run/php/php-fpm.sock;
		}
	}

### Prueba la configuración de nginx y recárgala

`sudo nginx -t`

`sudo service nginx restart`


# 6: Probar PHP


`php --version`

### Para probar PHP-FPM, primero crea un archivo `php_info.php` en el directorio raíz web

`sudo nano /var/www/html/php_info.php`

### Pega el siguiente código PHP en el archivo y guárdalo

`<?php phpinfo(); ?>`

Ahora en la barra de direcciones del navegador, ingresa `localhost/php_info.php`. Deberías ver la información PHP de tu servidor. Esto significa que PHP está funcionando correctamente. Por la seguridad de tu servidor, deberías eliminar el archivo php_info.php ahora.

# 7: Instalar 'Adminer' (equivalente a 'phpmyadmin')

Adminer es una herramienta gratuita de código abierto para administración de bases de datos, similar a phpmyadmin. Es muy ligera y fácil de instalar, y también soporta varios temas. Descarga Adminer desde aquí:

 https://github.com/vrana/adminer/releases/download/v4.2.5/adminer-4.2.5.php

 Colócalo en la carpeta var/www/html.
 Para acceder a él, escribe en tu navegador `localhost/adminer-4.2.5.php`

# 8: Corregir un error de MariaDB en ubuntu 16.04

Tu sistema operativo Linux tiene un usuario root. MariaDB también tiene un usuario root. Así que a veces, cuando intentas iniciar sesión en el monitor de MariaDB como usuario root, MariaDB puede autenticarte a través del plugin `Unix_Socket`, pero este plugin no está instalado por defecto. Así que verás el error Plugin '`unix_socket`' is not loaded.

### Corregir este error

Primero detén MariaDB, usa este comando para detenerlo.

`sudo systemctl stop mysql`

### Luego inicia MariaDB con la opción --skip-grant-tables que evita la autenticación de usuario

`sudo mysqld_safe --skip-grant-tables &`

### Inicia sesión en el monitor de MariaDB como root

`mysql -u root`

### Ingresa la siguiente sentencia SQL para verificar qué plugin de autenticación se usa para root

`MariaDB [(none)]> select Host,User,plugin from mysql.user where User='root';`

Verás que está usando el plugin `unix_socket`. Para cambiarlo al plugin `mysql_native_password`, ejecuta este comando:

`MariaDB [(none)]> update mysql.user set plugin='mysql_native_password';`

### Salir del monitor de MariaDB

`flush privileges;`
`quit;`

### Detener mysqld_safe

`sudo kill -9 $(pgrep mysql)`

### Iniciar MariaDB nuevamente

`sudo systemctl start mysql `

### Ahora puedes usar la contraseña normal para iniciar sesión

`mysql -u root -p`


## Eso es todo, ya hiciste todo. ¡Disfruta!

# Preguntas Frecuentes

Si tienes algún problema, consulta el [Issue 546](https://github.com/opensourcepos/opensourcepos/issues/546).
