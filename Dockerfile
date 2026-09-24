# Etapa 0: instalar las dependencias de PHP DENTRO de la imagen.
#
# Hasta el 2026-09-16 esto no ocurria: composer.json y composer.lock estaban en
# .dockerignore, de modo que la imagen no podia instalar nada y `vendor/` solo
# llegaba por el `COPY . /app`, es decir desde el directorio de trabajo del
# servidor, donde lo habia dejado un build manual antiguo. Consecuencia: un clon
# limpio --servidor nuevo, `git clean -fdx`, una recuperacion ante desastre--
# construia una imagen SIN framework, y la aplicacion no arrancaba siquiera.
#
# php:8.4-cli a proposito: la MISMA version que sirve la aplicacion abajo.
# Composer resuelve los requisitos de plataforma (`ext-intl`, `php ^8.2`) contra
# el PHP con el que corre, asi que instalar con otra version puede elegir
# paquetes que luego no encajen.
FROM php:8.4-cli AS vendor

RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev unzip \
    && docker-php-ext-install intl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /src

# El codigo de la aplicacion entra ANTES de instalar, y no es opcional:
# --optimize-autoloader construye el classmap recorriendo las rutas psr-4
# declaradas en composer.json (`App\` -> `app/`). Si `app/` no esta presente en
# ese momento, el classmap sale sin una sola clase de la aplicacion.
#
# Las rutas que composer escribe son relativas a la ubicacion de `vendor/` en
# tiempo de ejecucion (`__DIR__ . '/../..'`), no a donde se construyo, asi que
# construir en /src y copiar a /app/vendor es correcto.
COPY composer.json composer.lock ./
COPY app ./app

RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
    && composer clear-cache

# Etapa 1: compilar los assets DENTRO de la imagen.
#
# Sin esto, `public/resources` --el CSS y el JavaScript minificados-- no existia
# en ningun repositorio: estaba ignorado por git y el Dockerfile solo hacia
# `COPY . /app`. La imagen de produccion funcionaba porque se llevaba la salida
# de compilacion del directorio de trabajo del servidor, asi que **clonar y
# construir daba un POS sin estilos ni JavaScript**, y cada recuperacion
# dependia de que alguien recordara el paso manual.
#
# gulp no solo genera los assets: tambien REESCRIBE dos vistas, insertando las
# etiquetas con el nombre con hash de cada archivo. Por eso de esta etapa salen
# tres cosas y no una, y por eso un despliegue que se salte el build sirve las
# paginas con los bloques <!-- inject --> vacios: responde 200 y se ve rota.
FROM node:20-bookworm AS assets

WORKDIR /src

# Las dependencias primero, para que su capa se reutilice mientras no cambien.
COPY package.json package-lock.json gulpfile.js ./
RUN npm ci --no-audit --no-fund

COPY . .

# Las tareas van UNA POR UNA y en este orden a proposito.
#
#  - Lanzarlas juntas las corre en PARALELO, y `prod-login-js` falla con ENOENT
#    porque `public/resources` todavia no existe: las de copia lo crean.
#  - Se omite `update-licenses`, que la tarea `default` incluye: invoca a
#    `composer`, que no vive en esta etapa. Solo regenera un archivo de textos
#    legales, y su ausencia ya no rompe nada (ver Config::_licenses()).
#  - Tampoco se usa `default` porque empieza por `clean`, que borra
#    `public/license`.
RUN mkdir -p public/resources \
    && for tarea in copy-bootstrap copy-bootswatch copy-bootswatch5 copy-fonts \
                    copy-menubar debug-js prod-js debug-login-js prod-login-js \
                    debug-css prod-css; do \
           echo "gulp $tarea" && npx gulp "$tarea"; \
       done

# La comprobacion que convierte un fallo silencioso en un build roto.
#
# Si los bloques quedan vacios, la imagen se sirve igual y responde 200: el
# defecto solo se nota cuando un cajero abre la caja. Aqui todavia es barato.
RUN grep -A3 'inject:prod:js'  app/Views/partial/header.php | grep -q '<script' \
    && grep -A3 'inject:prod:css' app/Views/partial/header.php | grep -q '<link' \
    && ls public/resources/opensourcepos-*.min.css public/resources/opensourcepos-*.min.js \
    || ( echo "FALLO: los assets no se generaron o las vistas quedaron sin inyectar" && exit 1 )


FROM php:8.4-apache AS ospos
LABEL maintainer="jekkos"

RUN apt-get update && apt-get install -y --no-install-recommends \
    libicu-dev \
    libgd-dev \
    && docker-php-ext-install mysqli bcmath intl gd \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite

RUN echo "date.timezone = \"\${PHP_TIMEZONE}\"" > /usr/local/etc/php/conf.d/timezone.ini

# El sitio de configuracion de PHP del fork: los .ini versionados en
# docker/php/conf.d/ entran en la imagen. Hasta ahora la linea de arriba era el
# UNICO ajuste de PHP que existia; cualquier otro se aplicaba con `docker exec`
# dentro del contenedor del servidor y se perdia, en silencio, en el siguiente
# despliegue, porque el contenedor se recrea desde la imagen.
#
# COPY y no un montaje, a proposito, por tres razones:
#
#  - Montar ./docker/php/conf.d sobre /usr/local/etc/php/conf.d TAPARIA lo que la
#    imagen ya puso ahi: los docker-php-ext-{mysqli,bcmath,intl,gd}.ini que genera
#    el RUN de mas arriba y el timezone.ini de la linea anterior. El contenedor
#    arrancaria igual y Apache responderia 200 -- con el POS muerto por falta de
#    mysqli. Montar archivo por archivo evita ese efecto, pero entonces cada .ini
#    nuevo obliga a tocar los dos compose: deja de ser un sitio donde dejar
#    archivos y vuelve a ser un tramite que alguien se salta.
#  - La imagen es la unidad de despliegue Y de vuelta atras (etiquetas
#    casaletto-ospos:rollback-AAAAMMDD). Si la configuracion viniera del
#    directorio del servidor, volver a una imagen anterior la emparejaria con los
#    .ini de la revision actual. Dentro de la imagen, cada etiqueta lleva la suya.
#  - La imagen se construye EN EL SERVIDOR desde el repositorio
#    (`docker compose -f docker-compose.<env>.yml up -d --build`), asi que el
#    origen del COPY siempre esta en el contexto de build. Y es el mismo mecanismo
#    que ya usa timezone.ini: uno, no dos.
#
# COPY de un directorio FUSIONA con el destino, no lo reemplaza, asi que los .ini
# de las extensiones siguen en su sitio. Un archivo con el mismo nombre si los
# pisaria: no reutilizar los nombres docker-php-ext-*.ini ni timezone.ini.
#
# Requiere que docker/php/conf.d NO quede excluido en .dockerignore (hoy no lo
# esta: ningun patron lo alcanza). De eso se encarga la comprobacion de abajo.
COPY docker/php/conf.d/ /usr/local/etc/php/conf.d/

# La comprobacion que convierte un fallo silencioso en un build roto, igual que
# las de las otras etapas. Comprueba las tres cosas que pueden salir mal sin dar
# ni un error:
#
#  - que los .ini del repositorio LLEGARON. Si alguien excluye docker/php/ en
#    .dockerignore, el COPY no trae nada, la imagen se construye igual y la
#    configuracion simplemente no esta.
#  - que el directorio al que se copian es DE VERDAD el que PHP escanea (si la
#    imagen base lo cambiara, el COPY seguiria funcionando y no serviria de nada).
#  - que el COPY no se llevo por delante la configuracion de la imagen base:
#    timezone.ini y los .ini de las extensiones. Se comprueba que mysqli e intl
#    esten CARGADAS, no que exista un archivo con cierto nombre.
#
# Nombra el archivo del sitio: si se renombra, hay que actualizar esta linea.
RUN test -f /usr/local/etc/php/conf.d/zz-casaletto.ini \
    && php -r 'exit(rtrim(PHP_CONFIG_FILE_SCAN_DIR, "/") === "/usr/local/etc/php/conf.d" ? 0 : 1);' \
    && test -f /usr/local/etc/php/conf.d/timezone.ini \
    && php -m | grep -qx 'mysqli' \
    && php -m | grep -qx 'intl' \
    || ( echo "FALLO: docker/php/conf.d no llego a la imagen, o piso la configuracion de la imagen base" && exit 1 )

WORKDIR /app
COPY --chown=www-data:www-data . /app

# De la etapa de compilacion salen CUATRO cosas: los assets, las dos vistas que
# gulp reescribe con las etiquetas inyectadas, y los iconos del menu. Copiar
# solo los assets dejaria las paginas sin cargarlos.
#
# Los iconos son el caso menos evidente, y faltaban hasta el 2026-09-16.
# `copy-menubar` los genera en public/images/menubar, que esta gitignoreado: no
# viajan en el repositorio. Sin esta ultima linea llegaban solo por el
# `COPY . /app` de arriba -- o sea, desde el directorio del servidor, donde
# sobreviven a `git reset --hard` precisamente por estar ignorados. Funcionaba
# por inercia: un clon limpio (servidor nuevo, `git clean -fdx`, o una
# recuperacion ante desastre) construia la imagen sin un solo icono, y la
# servia respondiendo 200.
COPY --from=assets --chown=www-data:www-data /src/public/resources /app/public/resources
COPY --from=assets --chown=www-data:www-data /src/app/Views/partial/header.php /app/app/Views/partial/header.php
COPY --from=assets --chown=www-data:www-data /src/app/Views/login.php /app/app/Views/login.php
COPY --from=assets --chown=www-data:www-data /src/public/images/menubar /app/public/images/menubar

# Las dependencias de PHP, de la etapa `vendor`. `vendor/` esta excluido en
# .dockerignore, asi que el `COPY . /app` de arriba no trae nada aqui y esta es
# la unica fuente.
COPY --from=vendor --chown=www-data:www-data /src/vendor /app/vendor

# La comprobacion que convierte un fallo silencioso en un build roto, igual que
# la de los bloques inject en la etapa de assets.
#
# No basta con que exista `vendor/`: lo que importa es que el autoload resuelva
# de verdad las clases de la aplicacion con las rutas que tiene DENTRO de la
# imagen. Un classmap construido sin las fuentes presentes deja `App\` vacio, y
# eso no se nota hasta que alguien abre una pantalla.
RUN test -f /app/vendor/autoload.php \
    && test -f /app/vendor/codeigniter4/framework/system/CodeIgniter.php \
    && php -r 'require "/app/vendor/autoload.php"; \
               exit(class_exists("App\\Controllers\\Home") \
                 && class_exists("App\\Libraries\\MY_Migration") ? 0 : 1);' \
    || ( echo "FALLO: vendor incompleto, o el autoload no resuelve App\\" && exit 1 )

RUN chmod 750 /app/writable/logs /app/writable/uploads /app/writable/cache /app/public/uploads /app/public/uploads/item_pics \
    && chmod 640 /app/writable/uploads/importCustomers.csv \
    && ln -s /app/*[^public] /var/www \
    && rm -rf /var/www/html \
    && ln -nsf /app/public /var/www/html \
    && chmod +x /app/docker/entrypoint.sh /app/scripts/migrate-tenants.sh

# Migrate every tenant schema before Apache accepts a request. Chained in
# front of the base image's own entrypoint so its PHP setup still runs.
#
# This is not a convenience: Load_config runs on every request and destroys
# the session when the schema is behind the code (app/Events/Load_config.php),
# so a deploy whose migrations had not run yet left tenants unable to log in.
# The migration files ship inside this image, so here is the earliest point
# where they can possibly run.
ENTRYPOINT ["/app/docker/entrypoint.sh", "docker-php-entrypoint"]
CMD ["apache2-foreground"]

FROM ospos AS ospos_dev

ARG USERID
ARG GROUPID

RUN echo "Adding user uid $USERID with gid $GROUPID"
RUN ( addgroup --gid $GROUPID ospos || true ) && ( adduser --uid $USERID --gid $GROUPID ospos )

RUN yes | pecl install xdebug \
    && echo "zend_extension=$(find /usr/local/lib/php/extensions/ -name xdebug.so)" > /usr/local/etc/php/conf.d/xdebug.ini \
    && echo "xdebug.mode=debug" >> /usr/local/etc/php/conf.d/xdebug.ini \
    && echo "xdebug.remote_autostart=off" >> /usr/local/etc/php/conf.d/xdebug.ini
