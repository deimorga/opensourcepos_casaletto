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

WORKDIR /app
COPY --chown=www-data:www-data . /app

# De la etapa de compilacion salen TRES cosas: los assets y las dos vistas que
# gulp reescribe con las etiquetas inyectadas. Copiar solo los assets dejaria
# las paginas sin cargarlos.
COPY --from=assets --chown=www-data:www-data /src/public/resources /app/public/resources
COPY --from=assets --chown=www-data:www-data /src/app/Views/partial/header.php /app/app/Views/partial/header.php
COPY --from=assets --chown=www-data:www-data /src/app/Views/login.php /app/app/Views/login.php

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
