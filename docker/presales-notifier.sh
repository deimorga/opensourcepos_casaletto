#!/bin/sh
# El worker de los correos de preventas (docs/Tecnico/preventas-cartera-y-avisos.md §4.5).
#
# Corre en su propio contenedor, con la misma imagen que la aplicación y el servicio
# `presales-notifier` de cada docker-compose: nada vive en el servidor fuera de los contenedores,
# para que el sistema se pueda llevar a otra nube con solo el repositorio y el .env de cada ambiente.
#
# Cada minuto: `php spark presales:notify`, que envía lo que espera en la bandeja de salida de cada
# negocio y, desde las 9:00 hora del negocio, encola los recordatorios del día. Un fallo de una corrida
# no detiene el worker: la siguiente lo reintenta, y los correos esperan en la bandeja.
#
# Al detenerlo (despliegue, reinicio), la corrida que esté enviando termina antes de salir: docker stop
# manda SIGTERM al proceso 1, que es este shell, y el shell atiende la señal cuando `php` termina.
# docker-compose le da 90 segundos (stop_grace_period). Cortar un envío a la mitad podría mandar un
# correo dos veces.
set -u

stop=0
trap 'stop=1' TERM INT

echo "[presales-notifier] arrancado; corre presales:notify cada minuto."

while [ "$stop" = 0 ]; do
    php /app/spark presales:notify --no-header || echo "[presales-notifier] la corrida terminó con error; se reintenta en un minuto."

    # La espera va de a un segundo para que una parada no tenga que aguardar el minuto entero.
    waited=0
    while [ "$stop" = 0 ] && [ "$waited" -lt 60 ]; do
        sleep 1
        waited=$((waited + 1))
    done
done

echo "[presales-notifier] detenido."
