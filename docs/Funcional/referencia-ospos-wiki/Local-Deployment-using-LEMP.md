**NOTE: This guide uses outdated PHP versions. OSPOS requires PHP 8.1 to 8.4. Please adapt the PHP version numbers accordingly.**

Default Linux, Nginx, MariaDB, PHP 8.1+ & Adminer stack on Ubuntu

# 1: Update Ubuntu

`sudo apt-get update`

`sudo apt-get upgrade`

# 2: Install Nginx Web Server

`sudo apt-get install nginx`

### After installation, enable Nginx to auto start when Ubuntu is booted

`sudo systemctl enable nginx`

### Start Nginx with this command

`sudo systemctl start nginx`

### Check out it's status

`systemctl status nginx`

Now in your browser’s address bar, type `http://localhost` or `http://127.0.0.1` and hit enter

You will see "Welcome to nginx!,
that means nginx installed and running successfully.
### Now, we need to make www-data (Nginx user) as the owner of web root directory

`sudo chown www-data /var/www/html -R`

# 3: Install MariaDB

`sudo apt-get install mariadb-server mariadb-client`

### MariaDB server should be automatically started. Use systemctl to check its status.

`systemctl status mysql`

### Enable MariaDB to automatically start when Ubuntu is rebooted

`sudo systemctl enable mysql`

### Run the post installation security script

`sudo mysql_secure_installation`

 When it asks you to enter MariaDB root password, press `enter` because you have not set the root password yet. Then enter `y` to set the root password for MariaDB server.

 Next just press Enter to answer all the remaining questions. This will remove anonymous user, disable remote root login and remove test database. This step is a basic requirement for MariaDB database security.

# 4: Install PHP

`sudo apt-get install php-fpm php-mbstring php-xml php-mysql php-common php-gd php-json php-cli php-curl php-intl php-bcmath`

### Now start php-fpm

`sudo systemctl start php8.1-fpm`

### Check php status

`systemctl status php8.1-fpm`


# 5: Create a Default Nginx Server Block File


### Remove the "default.conf" symlink in "sites-enabled" directory

`sudo rm /etc/nginx/sites-enabled/default`

### create a new default server block file under /etc/nginx/conf.d/ directory

`sudo nano /etc/nginx/conf.d/default.conf`

### Paste the following text into the file, save and close the file




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

### Test nginx configuration and reload it

`sudo nginx -t`

`sudo service nginx restart`


# 6: Test PHP


`php --version`

### Test PHP-FPM, first create a `php_info.php` file in the Web root directory

`sudo nano /var/www/html/php_info.php`

### Paste the following PHP code into the file and save it

`<?php phpinfo(); ?>`

Now in the browser address bar, enter `localhost/php_info.php`. You should see your server’s PHP information. This means PHP is workinging fine. For your server’s security, you should delete php_info.php file now.

# 7: Install 'Adminer' ('phpmyadmin' equivalent)

Adminer is a free opensource data base management tool like phpmyadmin. It is very ightweight and easy to install and also supports various themes. Download Adminer from here:

 https://github.com/vrana/adminer/releases/download/v4.2.5/adminer-4.2.5.php

 Place it in var/www/html folder.
 To access it, type in your browser `localhost/adminer-4.2.5.php`

# 8: Fix MariaDB bug in ubuntu 16.04

Your Linux OS has a root user. MariaDB also has a root user. So sometimes, when you try to log into MariaDB monitor as root user, MariaDB may authenticate you via the `Unix_Socket plugin` but this plugin is not installed by default. So you see Plugin '`unix_socket`' is not loaded Error.

### Fix this error

First stop MariaDB, use this command to stop it.

`sudo systemctl stop mysql`

### Then start MariaDB with --skip-grant-tables option which bypass user authentication

`sudo mysqld_safe --skip-grant-tables &`

### Log into MariaDB monitor as root

`mysql -u root`

### Enter the following SQL statement to check which authentication plugin is used for root

`MariaDB [(none)]> select Host,User,plugin from mysql.user where User='root';`

You will see it’s using `unix_socket` plugin. To change it to `mysql_native_password` plugin, execute this command:

`MariaDB [(none)]> update mysql.user set plugin='mysql_native_password';`

### Exit MariaDB monitor

`flush privileges;`
`quit;`

### Stop mysqld_safe

`sudo kill -9 $(pgrep mysql)`

### Start MariaDB again

`sudo systemctl start mysql `

### Now you can use normal password to login

`mysql -u root -p`


## That's all, you have done everything. Enjoy !

# FAQ

If you face any issue, please see [Issue 546](https://github.com/opensourcepos/opensourcepos/issues/546).













