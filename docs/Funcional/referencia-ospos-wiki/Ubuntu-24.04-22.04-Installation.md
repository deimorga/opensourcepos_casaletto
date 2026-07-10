
[← Volver a Guías de Instalación](Getting-Started-installations) | [Inicio](Home)

---

Esta guía cubre la instalación en:
- Ubuntu 24.04 LTS
- Ubuntu 22.04 LTS
- Linux Mint 21.3

⚠️ **Se requiere PHP 8.1 a 8.4.** PHP 7.4 e inferior NO es compatible.

---

## Paso 1: Instalar el Stack LAMP

Agrega el repositorio de PHP e instala los paquetes requeridos:

```bash
sudo add-apt-repository ppa:ondrej/php
sudo apt update
sudo apt-get install -y apache2 mariadb-server php php-curl php-mysql php-gd php-intl php-bcmath php-mbstring php-xml php-mysqli
```

Habilita los módulos requeridos:

```bash
sudo a2enmod rewrite
sudo phpenmod intl gd bcmath curl
```

---

## Paso 2: Configurar Apache

Edita la configuración de Apache:

```bash
sudo nano /etc/apache2/apache2.conf
```

Busca la sección `<Directory /var/www/>` y cambia `AllowOverride` a `All`:

```apache
<Directory /var/www/>
    Options Indexes FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

Reinicia Apache:

```bash
sudo systemctl restart apache2
```

Elimina la página por defecto:

```bash
sudo rm /var/www/html/index.html
```

---

## Paso 3: Crear la Base de Datos

Crea la base de datos y el usuario:

```bash
sudo mysql -u root -e "CREATE SCHEMA ospos;"
sudo mysql -u root -e "CREATE USER 'admin'@'%' IDENTIFIED BY 'pointofsale';"
sudo mysql -u root -e "GRANT ALL PRIVILEGES ON ospos.* TO 'admin'@'%';"
sudo mysql -u root -e "FLUSH PRIVILEGES;"
```

---

## Paso 4: Descargar e Instalar

1. Descarga la última versión desde [GitHub Releases](https://github.com/opensourcepos/opensourcepos/releases)
2. Renombra el archivo descargado a `ospos.zip`
3. Extráelo en el directorio web:

```bash
sudo unzip ~/Downloads/ospos.zip -d /var/www/html/
```

4. Establece la propiedad:

```bash
sudo chown -R www-data:www-data /var/www/html
```

---

## Paso 5: Importar la Base de Datos (Solo Versiones Antiguas)

⚠️ **Importante:** Para las versiones actuales (estables e inestables), la base de datos se crea automáticamente en la primera ejecución. **NO necesitas importar database.sql manualmente.**

Este paso solo es necesario para versiones antiguas:

```bash
mysql -u admin -ppointofsale -D ospos < /var/www/html/app/Database/database.sql
```

---

## Paso 6: Establecer Permisos

Asegura los directorios editables (writable):

```bash
sudo chmod 750 /var/www/html/writable/logs
sudo chmod 750 /var/www/html/writable/uploads
sudo chmod 750 /var/www/html/writable/cache
sudo chmod 750 /var/www/html/public/uploads/item_pics
sudo chown -R www-data:www-data /var/www/html/writable
```

---

## Paso 7: Acceder a la Aplicación

Abre tu navegador y ve a:

```
http://localhost/public
```

**Credenciales por defecto:**
- Usuario: `admin`
- Contraseña: `pointofsale`

La aplicación actualizará la base de datos en el primer inicio de sesión, y luego te redirigirá para iniciar sesión de nuevo.

---

## Opcional: Configuración de Correo

Para la funcionalidad de correo electrónico, consulta [Configuración de Correo](Email-Configuration).

---

## Solución de Problemas

| Problema | Solución |
|-------|----------|
| Página en blanco | Revisa `writable/logs/` en busca de errores, asegúrate de que los permisos sean correctos |
| Error de base de datos | Verifica las credenciales de la base de datos en el archivo `.env` |
| Permiso denegado | Ejecuta `sudo chown -R www-data:www-data /var/www/html` |
| Faltan extensiones de PHP | Ejecuta `sudo apt-get install php-curl php-gd php-intl php-bcmath php-mbstring php-xml` |

---

## Ver También

- [Requisitos Mínimos del Servidor](Minimum-Server-Requirements)
- [Primeros Pasos - Uso](Getting-Started-usage)
- [Guía de Solución de Problemas](Error-Logging)
