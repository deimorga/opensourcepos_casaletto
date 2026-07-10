
[← Volver a Inicio](Home) | [Requisitos Mínimos](Minimum-Server-Requirements) | [Guía de Uso](Getting-Started-usage)

---

## Tabla de Contenidos

- [Inicio Rápido](#quick-start)
- [Requisitos del Servidor](#server-requirements)
- [Métodos de Instalación](#installation-methods)
  - [Instalación Local (Unix/Linux)](#local-deploy-install-for-unixlinux-environments)
  - [Instalación con Docker](#local-docker-install)
  - [Instalación en la Nube (DigitalOcean)](#cloud-deploy-installation)
- [Otras Guías de Instalación](#other-installation-guides)
- [Post-Instalación](#post-installation)

---

## Inicio Rápido

**La forma más rápida de probarlo:**

1. Descarga la última versión desde [GitHub Releases](https://github.com/opensourcepos/opensourcepos/releases)
2. Configura un stack LAMP/LEMP con PHP 8.1+
3. Configura `.env` con las credenciales de tu base de datos
4. Navega a `http://localhost/public`
5. Inicia sesión con `admin` / `pointofsale`

> **Nota:** Para las versiones actuales (estables e inestables), la base de datos se crea automáticamente en la primera ejecución. NO necesitas importar database.sql manualmente.

---

## Requisitos del Servidor

Antes de instalar, asegúrate de que tu servidor cumpla con los [Requisitos Mínimos del Servidor](Minimum-Server-Requirements).

### Resumen

| Componente | Mínimo | Recomendado |
|-----------|---------|-------------|
| PHP | 8.1 | 8.3 - 8.4 |
| MySQL/MariaDB | 5.7 / 10.x | 8.0 / 10.5+ |
| Apache | 2.4 | 2.4 |
| Navegador Web | Firefox 34+, Chrome 40+ | Navegador moderno |

⚠️ **PHP ≤ 7.4 NO es compatible**

---

## Métodos de Instalación

### Instalación Local para Entornos Unix/Linux

#### Paso 1: Abrir Terminal

Abre una ventana de terminal. En macOS, la encuentras en `Finder → Accesorios → Terminal`. En Linux, la encuentras en `Menú → Herramientas del Sistema → Terminal`. Para comandos que requieran acceso root, usa `sudo su`.

#### Paso 2: Instalar Dependencias

Instala Apache2, MariaDB/MySQL, PHP y las extensiones requeridas:

**Debian/Ubuntu:**
```bash
sudo apt-get install apache2 mariadb-server
sudo apt-get install php-curl php-mysql php-gd php-intl php-mbstring php-xml php-bcmath
sudo a2enmod rewrite
```

**RHEL/CentOS/Fedora:**
```bash
sudo yum install httpd mysql-server php php-bcmath php-dba php-gd php-mbstring php-xml
```

> **Nota:** Se requiere PHP 8.1 a 8.4. PHP 7.4 e inferior NO es compatible.

#### Paso 3: Preparar el Directorio Web

Navega a la raíz de tu servidor web:
```bash
cd /var/www/html
```

#### Paso 4: Descargar

Descarga la última versión desde [GitHub Releases](https://github.com/opensourcepos/opensourcepos/releases) y extráela en tu directorio web.

#### Paso 5: Crear la Base de Datos

```bash
mysql -u root -e "CREATE SCHEMA ospos;"
mysql -u root -e "CREATE USER 'admin'@'%' IDENTIFIED BY 'pointofsale';"
mysql -u root -e "GRANT ALL PRIVILEGES ON ospos.* TO 'admin'@'%' IDENTIFIED BY 'pointofsale' WITH GRANT OPTION;"
mysql -u root -e "FLUSH PRIVILEGES;"
```

#### Paso 6: Importar el Esquema de la Base de Datos (Solo Versiones Antiguas)

> **Importante:** Para las versiones actuales (estables e inestables), la base de datos se crea automáticamente en la primera ejecución. **Este paso solo es necesario para versiones antiguas.**

```bash
mysql -u admin -ppointofsale -D ospos < /var/www/html/app/Database/database.sql
```

#### Paso 7: Configurar la Conexión a la Base de Datos

Copia `.env.example` a `.env` y configura:
```ini
database.default.hostname = 'localhost'
database.default.database = 'ospos'
database.default.username = 'admin'
database.default.password = 'pointofsale'
```

#### Paso 8: Establecer Permisos

```bash
sudo chown -R www-data:www-data /var/www/html
sudo chmod -R 750 /var/www/html/writable
```

#### Paso 9: Acceder a la Aplicación

Abre tu navegador y ve a:
- `http://localhost/public` o
- `http://127.0.0.1/public`

#### Paso 10: Iniciar Sesión

- **Usuario:** `admin`
- **Contraseña:** `pointofsale`

---

### Instalación Local con Docker

#### Paso 1: Instalar Docker

Instala Docker siguiendo la [documentación oficial de Docker](https://docs.docker.com/get-docker/) para tu plataforma.

#### Paso 2: Preparar el Directorio

```bash
mkdir ~/osposdocker
cd ~/osposdocker
```

#### Paso 3: Descargar y Extraer

Descarga la última versión desde [GitHub Releases](https://github.com/opensourcepos/opensourcepos/releases) y extráela en tu directorio.

#### Paso 4: Construir y Ejecutar

```bash
docker-compose build
docker-compose up
```

> **Advertencia:** La configuración de Docker por defecto NO es adecuada para producción. Cambia las contraseñas por defecto antes de exponerla públicamente.

---

### Instalación en la Nube

Para hosting en la nube, recomendamos [**DigitalOcean**](https://m.do.co/c/ac38c262507b), donde puedes obtener **$100 de crédito gratis** mientras apoyas el proyecto.

#### Paso 1: Crear una Cuenta

Crea una [cuenta en DigitalOcean](https://m.do.co/c/ac38c262507b).

#### Paso 2: Crear un Droplet

Elige un Droplet de Debian/Ubuntu con LAMP o una aplicación de un clic.

#### Paso 3: Conectarse al Servidor

```bash
ssh root@<your-droplet-ip>
```

#### Paso 4: Instalar Dependencias

```bash
a2enmod rewrite
apt-get install php-intl php-mbstring php-xml php-bcmath php-curl php-gd
service apache2 restart
```

> **Nota:** Asegúrate de tener PHP 8.1+ instalado. PHP 7.4 e inferior NO es compatible.

#### Paso 5: Descargar

Descarga la última versión desde [GitHub Releases](https://github.com/opensourcepos/opensourcepos/releases).

#### Paso 6: Crear la Base de Datos

```bash
mysql -u root -e "CREATE SCHEMA ospos;"
mysql -u root -e "CREATE USER 'admin'@'%' IDENTIFIED BY 'pointofsale';"
mysql -u root -e "GRANT ALL PRIVILEGES ON ospos.* TO 'admin'@'%';"
mysql -u root -e "FLUSH PRIVILEGES;"
```

#### Paso 7: Importar la Base de Datos (Solo Versiones Antiguas)

> **Importante:** Para las versiones actuales (estables e inestables), la base de datos se crea automáticamente en la primera ejecución. **Este paso solo es necesario para versiones antiguas.**

```bash
mysql -u admin -ppointofsale -D ospos < /var/www/html/app/Database/database.sql
```

#### Paso 8: Acceder a la Aplicación

Abre tu navegador y ve a: `http://<your-droplet-ip>/public`

#### Paso 9: Iniciar Sesión

- **Usuario:** `admin`
- **Contraseña:** `pointofsale`

---

## Post-Instalación

Después de una instalación exitosa:

1. Cambia la contraseña de administrador de inmediato
2. Configura los ajustes de tu tienda en **Configuración de Tienda**
3. Configura empleados y permisos
4. Agrega tus artículos de inventario
5. Configura impuestos y métodos de pago

Consulta [Guía de Uso - Primeros Pasos](Getting-Started-usage) para una configuración detallada.

---

## Otras Guías de Instalación

- [Despliegue Local usando LEMP (Nginx)](Local-Deployment-using-LEMP)
- [Despliegue Local usando MAMP para Windows](Local-Deployment-using-MAMP-for-Windows)
- [Despliegue Local usando XAMPP](XAMPP-Installation)
- [Guía de Raspberry Pi](Raspberry-Pi-Installation)
- [Instalación en Ubuntu 24.04/22.04](Ubuntu-24.04-22.04-Installation)

---

## Solución de Problemas

### Problemas Comunes

| Problema | Solución |
|-------|----------|
| Falta la carpeta "system" | Descarga una versión compilada (release build), no el código fuente. O ejecuta `npm install` y compila. |
| Error al iniciar sesión | Verifica las credenciales de la base de datos en el archivo `.env` |
| Página en blanco | Revisa los logs de error de PHP, habilita `display_errors` en desarrollo |
| Errores de permisos | Ejecuta `chmod -R 750 writable` y `chown -R www-data:www-data writable` |

Para más ayuda, consulta las [preguntas frecuentes en el README](https://github.com/opensourcepos/opensourcepos#faq) o [crea un issue](https://github.com/opensourcepos/opensourcepos/issues/new).

---

## Ver También

- [Requisitos Mínimos del Servidor](Minimum-Server-Requirements)
- [Guía de Uso - Primeros Pasos](Getting-Started-usage)
- [Índice de Desarrollo](Development-Index)
- [Soporte de Hardware](Supported-hardware-datasheet)

---

_Si te gusta el proyecto y estás generando dinero con él, considera [invitarnos un café](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=MUN6AEG7NY6H8)._
