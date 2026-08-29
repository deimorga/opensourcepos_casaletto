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
