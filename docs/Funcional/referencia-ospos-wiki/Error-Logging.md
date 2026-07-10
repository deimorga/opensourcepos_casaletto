
[← Volver al Índice de Desarrollo](Development-Index)

---

El registro de logs no está activado por defecto. Hay dos lugares donde revisar errores: **lado del servidor** y **lado del cliente**.

## Tabla de Contenidos

- [Registro de Errores del Lado del Cliente](#error-log-on-client-side)
- [Registro de Errores del Lado del Servidor](#error-log-on-server-side)
  - [Registro de Errores de la Aplicación](#application-error-log)
  - [Registro de Errores del Servidor Web](#webserver-error-log)

## Registro de Errores del Lado del Cliente

Dependiendo del navegador, esto aplica a Firefox/Pale Moon y Chrome/Chromium.

1. Antes de cargar la página con el problema, presiona la tecla F12
2. La pantalla se dividirá en dos partes - la segunda parte es la herramienta de desarrollador del navegador web
3. Haz clic en la pestaña "Network" (Red) en la herramienta de desarrollador
4. Se mostrará todo el tráfico web, mostrando cada solicitud y respuesta
5. Cada solicitud tendrá una línea de entrada, lo mismo para cada respuesta

![presiona F12 en el navegador web y ve la nueva ventana dividida](https://github.com/venenux/osposos/raw/master/debianOspos/screenshot-ospos-devel-f12-client-log-error.png)

## Registro de Errores del Lado del Servidor

Aquí hay varios componentes en acción: el log de la aplicación (deshabilitado por defecto) y el log de componentes del sistema (log de Apache que incluye el log de errores de PHP, log de errores de la base de datos).

### Registro de Errores de la Aplicación

Está deshabilitado por defecto. En CodeIgniter 4, el registro se puede configurar en `app/Config/App.php` o mediante el archivo `.env`. Configura la variable `CI_ENVIRONMENT` a `development` para depuración o `production` para uso en producción.

El umbral del log se puede configurar mediante la opción `logger.threshold` en `.env` (0 = deshabilitado, hasta 9 = todos los mensajes). Para reportar y enviar issues, por favor configúralo en `4` o superior y adjunta solo las partes relevantes.

La ubicación por defecto para los archivos de log es `writable/logs/`. Asegúrate de que el directorio `writable` tenga los permisos de escritura adecuados para el usuario del servidor web (por ejemplo, `www-data`).

> **Importante:** Revisa los permisos del directorio. El usuario del servidor web debe tener acceso de escritura a `writable/logs/`. Si los permisos son incorrectos, los archivos de log no se crearán.

#### Registro SQL de Base de Datos

El registro de consultas a la base de datos se puede habilitar en `app/Config/Database.php` o mediante el archivo `.env`. Los logs se escribirán en `writable/logs/` junto con los logs de la aplicación.
  
### Registro de Errores del Servidor Web

En las distribuciones estándar, siempre está presente un archivo de log de errores general que registra los errores de procesamiento de scripts PHP.

- En instalaciones estándar de Linux: `/var/log/<webserver>/error.log`
- En instalaciones binarias de Apache2: `${INSTALL_DIR}/logs/error.log`
- En instalaciones binarias de Nginx/Lighttpd: `${INSTALL_DIR}/logs/error.log`

Por ejemplo, puedes personalizar esto en el archivo de configuración de Apache (`apache2.conf` o `http.conf`):

```
ErrorLog "${INSTALL_DIR}/logs/apache_error.log"
CustomLog "${INSTALL_DIR}/logs/access.log" common
```

Consulta la documentación de tu servidor web para opciones de personalización.

#### Errores de PHP

Si el error se debe a problemas del código de la aplicación, podrías necesitar activar el log de errores de PHP.

Hay dos aspectos: identificar dónde se crea el log, y establecer qué debe escribirse en el log. La aplicación también ofrece la opción de mostrar los errores directamente en la página web, lo cual es útil para depuración pero no se recomienda en producción (por razones de seguridad). Para mostrar los errores de PHP en tu página, configura `CI_ENVIRONMENT = development` en tu archivo `.env`.

El archivo de configuración de PHP (`php.ini`) controla el registro de errores. El archivo `php.ini` normalmente se encuentra en la carpeta de instalación de PHP (ver la [documentación de PHP](http://php.net/manual/en/configuration.file.php)).

Las instalaciones modernas de PHP configuran la ruta del log de errores como vacía, por lo que es gestionada por el servidor web. Consulta la documentación de PHP para tu distribución específica.