
1. Lee la sección de [Requisitos en el Índice de Desarrollo](Development-Index#server-requirements).
2. Lee primero la [sección de Arquitectura](Development-Index#architecture).
3. Después de leer este documento, lee la guía de [Registro de Errores](Error-Logging).

Para los impacientes: lee la página wiki del [Índice de Desarrollo](Development-Index).

## Configuración usando docker

Se recomienda Docker y docker-compose para hacer una primera compilación completa. Si no quieres usar docker, lee la sección de configuración básica de herramientas más adelante aquí.

```
docker run --rm -v $(pwd):/app jekkos/composer composer install
docker run --rm -v $(pwd):/app -w /app jekkos/composer php bin/install.php translations develop
npm install
docker-compose -f docker-compose.dev.yml build
docker-compose -f docker-compose.dev.yml up
```

## Flujo de trabajo

Obviamente, como hace github, mediante pull request, no hay un proceso extendido de "revisiones"; el pull se acepta una vez que algún desarrollador lo revisó y confirmó que funciona, pero intenta apegarte al estándar de codificación y documentación como se describe en este documento.

Por favor lee la sección de la página wiki [consejos de código y ayuda del índice de desarrollo](Development-Index#development-code-tips-and-help) para información más detallada.

## Estilo de código

La aplicación intenta apegarse al [estilo de codificación de CodeIgniter 4](https://codeigniter4.github.io/userguide4/concepts/structure.html), hay que leerlo con atención.

**IMPORTANTE**: como se discutió en el #389, pero debido a que la aplicación fue una migración desde CI3, algunas porciones del código podrían no cumplir aún. Por favor no hagas pull requests únicamente para dar formato a código antiguo, hazlo solo si están involucradas nuevas características.

Siempre revisa los [consejos de código y ayuda del desarrollo](Development-Index#development-code-tips-and-help) sobre cómo codificar los controladores y vistas respecto a los modelos.

## Documentación del código

La aplicación genera documentación de código usando PHPDoc. Para más detalles ver el [issue 1278](https://github.com/jekkos/opensourcepos/issues/1278).
La documentación del código se puede leer usando herramientas de IDE que soporten PHPDoc.
Ver la [guía de usuario de CodeIgniter 4](https://codeigniter4.github.io/userguide4/) para la documentación del framework.

## Instalación básica de herramientas

Las herramientas de compilación se pueden instalar de forma permanente si se prefiere no usar docker. Se usan Node.js, npm y composer y necesitan estar instalados:

    sudo apt-get install nodejs
    sudo apt-get install npm

También composer necesita estar instalado; por ejemplo, en distribuciones derivadas de Debian se puede ver el tutorial: https://getcomposer.org/download/

Una vez instaladas las herramientas básicas, ejecuta `npm install`, lo cual instalará todas las dependencias de npm.

## Ejecutando las compilaciones de minificación de js

El proyecto usa gulp y npm para minificar el javascript incluido final.
Como primer paso necesitas instalar npm; una vez hecho, debes ejecutar el siguiente comando:

    npm install

Esto instalará todas las dependencias de npm necesarias para compilar el proyecto.

## Ejecutando las compilaciones de minificación de CSS

El proceso de compilación de gulp minificará los archivos CSS y JavaScript según sea necesario. Ejecuta:

    npm run build

## Forma correcta de ver los cambios de minificación de js

JS y CSS se almacenan en caché, solo necesitas recargar tu página manteniendo presionada la tecla shift en tu navegador web.

Este procedimiento también se recomienda si realizas una actualización.

## Dotfiles para una configuración fácil del entorno

La adición de la librería composer dotenv facilita configurar diferentes entornos de staging con credenciales de base de datos y variables ligadas al entorno. Copia el archivo `.env.example` en la raíz del proyecto a `.env` y completa los valores de las variables según sea necesario. PHP display_errors se establecerá en 1 en modo desarrollo, lo cual facilitará la resolución de errores y la depuración cuando algo salga mal.

## Configuración de minificación usando Docker

Un entorno de desarrollo completo se puede configurar fácilmente usando docker. Para este propósito existe el archivo `Dockerfile.test`. Necesitarás montar la ruta del directorio del proyecto en el host dentro del contenedor docker. Primero compila y etiqueta la imagen docker descrita en el archivo `Dockerfile.test`.

`docker build -f Dockerfile.test -t opensourcepos:test .`

Como paso final puedes ejecutar npm desde la imagen recién creada. Abre una terminal en el directorio ospos y luego ejecuta el siguiente comando

`docker run -v $(pwd):/data -f Dockerfile.test opensourcepos:test npm install`

Ahora, en este punto puedes seguir los [consejos de código y ayuda del desarrollo](Development-Index#development-code-tips-and-help) para empezar a crear nuevas características para opensourcepos.

## Depurando PHP usando XDebug y Docker

El lado de PHP de esta aplicación también se puede depurar usando un contenedor Docker preconstruido. Este contenedor agregará el módulo PHP XDebug que se puede usar desde IntelliJ o Eclipse. Primero revisa la dirección ip en la interfaz docker de tu host, ya que debe configurarse en el archivo `docker-compose.dev.yml`. Luego puedes usar docker-compose para iniciar la aplicación con xdebug habilitado ingresando el siguiente comando desde la CLI (primero dirígete al directorio ospos)

`docker-compose -f docker-compose.dev.yml up`

Después de que el contenedor se inicie, la conexión de xdebug debería estar disponible en el puerto 9000 de la dirección ip de docker. Agrega [esto a la configuración en IntelliJ o Eclipse](https://gist.github.com/chadrien/c90927ec2d160ffea9c4) y deberías estar listo.

# Ver también: 

* [Índice de desarrollo](Development-Index#tech-architecture)
* [Consejos de código y ayuda del desarrollo](Development-Index#development-code-tips-and-help)
* [Registro de Errores](Error-Logging)