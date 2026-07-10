**NOTA: Esta guía es solo para pruebas locales. Para uso en producción, se recomienda un stack LAMP/LEMP adecuado.**

**OSPOS requiere PHP 8.1 o superior. Asegúrate de que tu versión de XAMPP incluya PHP 8.1+.**

## Pasos de Instalación

1. Descarga e instala XAMPP con PHP 8.1+ desde https://www.apachefriends.org/download.html

2. Abre el Panel de Control de XAMPP e inicia Apache y MySQL.

3. Desde Apache selecciona Config y edita el archivo Php.ini para habilitar las siguientes extensiones:
   
   - `extension=gd` - para procesamiento de imágenes
   - `extension=intl` - para internacionalización
   - `extension=bcmath` - para matemáticas de precisión arbitraria
   - `extension=mbstring` - para cadenas multibyte
   - `extension=curl` - para solicitudes HTTP
   - `extension=xml` - para procesamiento de XML
   - `extension=mysqli` - para conexiones a base de datos MySQL

4. Descarga la última versión de OSPOS desde https://github.com/opensourcepos/opensourcepos/releases

5. Si usas Windows, haz clic derecho en el archivo zip descargado, selecciona Propiedades, y haz clic en Desbloquear si el botón está presente. Luego extrae el archivo.

6. Coloca la carpeta extraída dentro del directorio `htdocs`.

7. En el Panel de Control de XAMPP ve a MySQL - Admin para abrir phpMyAdmin.

8. Crea una nueva base de datos llamada `ospos` (o el nombre que prefieras).

9. **(Solo versiones antiguas)** Importa el esquema de la base de datos:
   
   > **Nota:** Para las versiones actuales (estables e inestables), la base de datos se crea automáticamente en la primera ejecución. Este paso solo es necesario para versiones antiguas.
   
   Selecciona la base de datos, ve a Importar, y navega para ubicar `app/Database/database.sql`. Selecciónalo para importar.

10. Configura la conexión a la base de datos copiando `.env.example` a `.env` y editando las siguientes líneas:
    
    ```
    database.default.hostname = 'localhost'
    database.default.database = 'ospos'
    database.default.username = 'root'
    database.default.password = ''    # default XAMPP has no password
    ```

11. Establece los permisos adecuados en el directorio `writable`. En Windows, esto usualmente no es necesario.

12. Ve a `http://localhost/opensourcepos/public` (reemplaza `opensourcepos` con el nombre de tu carpeta) para ver la pantalla de inicio de sesión.

13. Credenciales por defecto:
    - Usuario: `admin`
    - Contraseña: `pointofsale`

## Solución de Problemas

- Si obtienes un error de "falta la carpeta system", asegúrate de haber descargado una versión compilada (release build), no el código fuente. O ejecuta `npm install` y compila el proyecto.
- Si las extensiones no cargan, reinicia Apache después de modificar php.ini.
- Para conocer en detalle los requisitos de extensiones, consulta el [issue 1607](https://github.com/opensourcepos/opensourcepos/issues/1607).

## Usuarios de WAMP

Si estás usando WAMP en lugar de XAMPP, sigue pasos similares pero asegúrate de que los archivos de extensión ICU estén copiados en las ubicaciones correctas para el soporte de intl.
