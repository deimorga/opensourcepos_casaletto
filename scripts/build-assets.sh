#!/usr/bin/env bash
#
# Construye los assets (CSS/JS) y los inyecta en las vistas.
#
# HAY QUE CORRERLO EN CADA DESPLIEGUE, aunque "solo haya cambiado PHP". El
# motivo no es la minificacion: es que `git reset --hard` restaura
# app/Views/partial/header.php con los bloques <!-- inject --> VACIOS, y una
# pagina sin ese bloque relleno se sirve sin una sola linea de CSS ni de JS.
# Responde 200, se ve rota, y nada en los registros lo delata.
#
# Este script existe porque el procedimiento tiene tres trampas que ya costaron
# un despliegue cada una:
#
#  1. NO EXISTE la tarea `gulp build`. La que agrupa todo es `default`.
#  2. `default` empieza por `clean` --que BORRA public/resources-- y sigue por
#     `update-licenses`, que invoca `composer`. En un contenedor sin composer, o
#     sin la extension `intl` que exige CodeIgniter, esa tarea revienta DESPUES
#     del clean: el sitio queda sin assets y con los inject vacios. Por eso aqui
#     se corren las tareas necesarias una por una y se omite `update-licenses`,
#     que solo regenera un archivo de licencias y no hace falta para desplegar.
#  3. Lanzarlas todas en una sola invocacion las corre EN PARALELO, y
#     `prod-login-js` falla con ENOENT porque public/resources todavia no
#     existe. Van en orden, y las de copia primero.
#
# Uso:  scripts/build-assets.sh [ruta-del-proyecto]
set -euo pipefail

RAIZ="${1:-$(pwd)}"
cd "$RAIZ"

echo "==> Construyendo assets en $RAIZ"
docker run --rm -v "$RAIZ":/app -w /app node:20 bash -lc '
  set -e
  npm ci --no-audit --no-fund >/dev/null 2>&1
  mkdir -p public/resources
  for tarea in copy-bootstrap copy-bootswatch copy-bootswatch5 copy-fonts \
               copy-menubar prod-css prod-js prod-login-js compress; do
    printf "    %-22s" "$tarea"
    if npx gulp "$tarea" 2>&1 | grep -qE "errored|^Error"; then
      echo "FALLO"; exit 1
    fi
    echo "ok"
  done
'

# La comprobacion que de verdad importa. Un bloque vacio aqui significa que la
# caja se va a servir sin JavaScript, y eso NO se nota hasta que un cajero
# intenta vender.
echo "==> Comprobando que los bloques inject quedaron con contenido"
if ! grep -A3 'inject:prod:js' app/Views/partial/header.php | grep -q '<script'; then
    echo "    ERROR: el bloque inject:prod:js quedo VACIO. No recrear el contenedor." >&2
    exit 1
fi
if ! grep -A3 'inject:prod:css' app/Views/partial/header.php | grep -q '<link'; then
    echo "    ERROR: el bloque inject:prod:css quedo VACIO. No recrear el contenedor." >&2
    exit 1
fi

grep -A3 'inject:prod:css' app/Views/partial/header.php | grep '<link' | sed 's/^/    /'
grep -A3 'inject:prod:js'  app/Views/partial/header.php | grep '<script' | sed 's/^/    /'
echo "==> Assets listos."
