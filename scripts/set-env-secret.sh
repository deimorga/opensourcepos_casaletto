#!/usr/bin/env bash
# Escribe un secreto en el .env de la carpeta actual SIN que pase por la pantalla, el historial de la
# terminal, un argumento visible en `ps` ni un registro.
#
# Uso, en el VPS, dentro de la carpeta del ambiente:
#
#     cd /root/POS_Casaletto          &&  bash scripts/set-env-secret.sh PLATFORM_SMTP_PASS
#     cd /root/POS_Casaletto_staging  &&  bash scripts/set-env-secret.sh MAILPIT_UI_AUTH
#
# Pide el valor dos veces sin mostrarlo. Si la variable ya estaba, la reemplaza; si no, la agrega. Deja
# el .env con permisos 600. No reinicia nada: el contenedor toma el valor en el siguiente despliegue
# (scripts/deploy.sh recrea el servicio cuando su configuración cambia).
#
# Por qué un script y no «editar el .env a mano»: con un editor el secreto queda en la pantalla y a
# veces en un archivo temporal o de respaldo del editor; con `echo ... >> .env` queda en el historial
# del shell. Y un .env con una línea repetida o mal escrita no avisa: compose toma una de las dos.
set -euo pipefail

NAME="${1:-}"

if [[ ! "$NAME" =~ ^[A-Z][A-Z0-9_]*$ ]]; then
    echo "uso: bash scripts/set-env-secret.sh NOMBRE_DE_VARIABLE" >&2
    exit 1
fi

if [ ! -f .env ]; then
    echo "No hay .env en $(pwd). Hay que correrlo dentro de la carpeta del ambiente." >&2
    exit 1
fi

read -r -s -p "Valor de $NAME (no se muestra): " VALUE
echo
read -r -s -p "Repítalo: " AGAIN
echo

if [ -z "$VALUE" ]; then
    echo "Vacío: no se cambió nada." >&2
    exit 1
fi

if [ "$VALUE" != "$AGAIN" ]; then
    echo "No coinciden: no se cambió nada." >&2
    exit 1
fi

# compose lee el .env línea a línea y sin comillas: un espacio, una comilla, un # o un $ cambian el
# valor en silencio. Mejor rechazarlo aquí que descubrirlo cuando el correo no salga.
if [[ "$VALUE" =~ [[:space:]\'\"#\$\`\\] ]]; then
    echo "El valor tiene espacios, comillas, #, \$ o \\, que el .env no guarda bien. No se cambió nada." >&2
    exit 1
fi

umask 077
TMP="$(mktemp .env.XXXXXX)"
trap 'rm -f "$TMP"' EXIT

grep -v "^${NAME}=" .env > "$TMP" || true
printf '%s=%s\n' "$NAME" "$VALUE" >> "$TMP"
chmod 600 "$TMP"
mv "$TMP" .env
trap - EXIT

echo "$NAME guardada en $(pwd)/.env. Se aplica en el siguiente despliegue."
