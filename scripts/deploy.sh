#!/usr/bin/env bash
#
# Despliegue de OSPOS Casaletto en el VPS. Lo ejecutan los workflows
# deploy-staging.yml y deploy-production.yml; a mano solo en emergencia, y
# entonces con este mismo script, nunca con un `docker compose` suelto.
#
#   scripts/deploy.sh staging <sha>
#   scripts/deploy.sh prod    <sha>
#
# Variables opcionales:
#   ALLOW_BUSINESS_HOURS=1  prod: desplegar antes de las 22:00 (el dueño lo autorizó en el momento)
#   IGNORE_ACTIVITY=1       prod: desplegar aunque haya usuarios trabajando
#   SELFTEST_FAIL_VERIFY=1  staging: forzar el fallo de la verificación para probar la vuelta atrás
#
# POR QUÉ EXISTE (2026-09-29)
#
# Esa noche producción estuvo caída de 22:59 a 23:08. Un despliegue a mano corrió
# `docker compose up -d --build ospos` sin `-f docker-compose.prod.yml`: el
# docker-compose.yml por defecto publica el puerto 80 (que es de Traefik) y monta el
# volumen viejo de la base. Reemplazó los contenedores de producción y el nuevo no
# arrancó. Además el commit venía de develop, sin pasar por staging.
#
# Cada paso de abajo cierra uno de esos huecos, o uno que ya nos costó antes:
#
#   1. El archivo de compose lo fija el script (COMPOSE_FILE), y lo deja escrito en el
#      .env de la carpeta para que un `docker compose` a mano tampoco pueda equivocarse.
#   2. Producción solo acepta un commit que staging ya corre (certificado allí), en
#      horario sin ventas y sin usuarios activos.
#   3. Antes de tocar nada: respaldo de todos los esquemas, comprobado restaurándolo en
#      una base temporal, y una etiqueta de imagen para volver atrás.
#   4. La imagen se construye ANTES de parar nada: si el build falla, lo que corre sigue
#      corriendo.
#   5. Después: el arranque tiene que decir «All schemas current.» y cada negocio tiene
#      que responder su propia página, con su <base href> y con CSS (un 200 sin estilos
#      ya pasó una vez).
#   6. Si la verificación falla y el despliegue no traía migraciones, vuelve solo a la
#      imagen anterior. Si traía migraciones NO vuelve solo: una imagen más vieja que el
#      esquema deja a todos sin poder entrar (MY_Migration::is_latest()); hay que
#      restaurar imagen y respaldo juntos, y el script dice exactamente cómo.

set -Eeuo pipefail

ENV_NAME="${1:-}"
SHA="${2:-}"

case "$ENV_NAME" in
    prod)
        DIR=/root/POS_Casaletto
        COMPOSE=docker-compose.prod.yml
        BRANCH=master
        IMAGE=casaletto-ospos:prod
        LEGACY_HOST=pos-casaletto.micronuba.net
        SAAS_DOMAIN=ospos-saas.micronuba.net
        ;;
    staging)
        DIR=/root/POS_Casaletto_staging
        COMPOSE=docker-compose.staging.yml
        BRANCH=develop
        IMAGE=casaletto-ospos:staging
        LEGACY_HOST=staging.pos-casaletto.micronuba.net
        SAAS_DOMAIN=staging.ospos-saas.micronuba.net
        ;;
    *)
        echo "uso: $0 prod|staging <sha>" >&2
        exit 2
        ;;
esac

if ! [[ "$SHA" =~ ^[0-9a-f]{7,40}$ ]]; then
    echo "uso: $0 $ENV_NAME <sha>   (falta el commit a desplegar)" >&2
    exit 2
fi

STAGING_DIR=/root/POS_Casaletto_staging
BACKUP_ROOT=/root/backups
DEPLOY_LOG=/root/deploys.log
KEEP_ROLLBACK_TAGS=8
KEEP_BACKUPS=15
STAMP="$(TZ=America/Bogota date +%Y%m%d-%H%M%S)"

export COMPOSE_FILE="$COMPOSE"

say()  { echo "[deploy $ENV_NAME $(TZ=America/Bogota date +%H:%M:%S)] $*"; }
fail() { say "ALTO: $*"; exit 1; }

# Un despliegue a la vez por ambiente, venga de GitHub o de una terminal.
exec 9>"/tmp/ospos-deploy-$ENV_NAME.lock"
flock -n 9 || fail "ya hay otro despliegue de $ENV_NAME en curso."

cd "$DIR"

# `docker compose exec -T` lee stdin: sin esto se comería lo que venga detrás.
dc()   { docker compose "$@" </dev/null; }
sql()  { dc exec -T mysql sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" -N -B -e "$1"' sh "$1"; }

# ---------------------------------------------------------------------------
# 1. El compose correcto, también para quien entre a mano después
# ---------------------------------------------------------------------------
if [ ! -f .env ]; then
    fail "no existe $DIR/.env (secretos del ambiente)."
fi
if grep -q '^COMPOSE_FILE=' .env; then
    if ! grep -qx "COMPOSE_FILE=$COMPOSE" .env; then
        fail ".env tiene un COMPOSE_FILE distinto de $COMPOSE. Revisarlo a mano."
    fi
else
    printf '\n# Lo fija scripts/deploy.sh: un `docker compose` suelto en esta carpeta usa el archivo correcto.\nCOMPOSE_FILE=%s\n' "$COMPOSE" >> .env
    say "COMPOSE_FILE=$COMPOSE agregado al .env de la carpeta."
fi

# ---------------------------------------------------------------------------
# 2. Comprobaciones previas: nada se toca todavía
# ---------------------------------------------------------------------------
git fetch --quiet origin "$BRANCH"
SHA="$(git rev-parse --verify "$SHA^{commit}")" || fail "el commit $SHA no existe en origin."
git merge-base --is-ancestor "$SHA" "origin/$BRANCH" || fail "$SHA no está en $BRANCH."

OLD_SHA="$(git rev-parse HEAD)"
say "corre ${OLD_SHA:0:9}; se despliega ${SHA:0:9} ($(git log -1 --format=%s "$SHA"))"

if [ "$ENV_NAME" = prod ]; then
    # Certificado en staging: staging corre este commit o uno posterior que lo contiene.
    STAGING_HEAD="$(git -C "$STAGING_DIR" rev-parse HEAD)"
    if ! git -C "$STAGING_DIR" merge-base --is-ancestor "$SHA" "$STAGING_HEAD" 2>/dev/null; then
        fail "${SHA:0:9} no ha pasado por staging (staging corre ${STAGING_HEAD:0:9}). Desplegar primero a staging y certificar."
    fi
    say "certificado: staging corre ${STAGING_HEAD:0:9}, que contiene este commit."

    HOUR="$(TZ=America/Bogota date +%-H)"
    if [ "$HOUR" -ge 6 ] && [ "$HOUR" -lt 22 ]; then
        if [ "${ALLOW_BUSINESS_HOURS:-0}" != 1 ]; then
            fail "son las $(TZ=America/Bogota date +%H:%M) en Colombia: producción solo después de las 22:00, salvo autorización del dueño (ALLOW_BUSINESS_HOURS=1)."
        fi
        say "AVISO: horario de venta, autorizado por el dueño."
    fi

    # Peticiones que escriben, de los últimos 15 minutos. El ingreso y el monitor no cuentan.
    ACTIVE="$(dc logs --since 15m ospos 2>/dev/null | grep '"POST ' | grep -v '"POST /login' || true)"
    if [ -n "$ACTIVE" ]; then
        echo "$ACTIVE" | tail -5
        if [ "${IGNORE_ACTIVITY:-0}" != 1 ]; then
            fail "hay $(echo "$ACTIVE" | wc -l) acciones de usuarios en los últimos 15 minutos (arriba las últimas). Esperar, o IGNORE_ACTIVITY=1 si el dueño lo autoriza."
        fi
        say "AVISO: hay actividad, se despliega por autorización explícita."
    else
        say "sin acciones de usuarios en los últimos 15 minutos."
    fi
fi

FREE_GB="$(df -BG --output=avail / | tail -1 | tr -dc 0-9)"
[ "$FREE_GB" -ge 5 ] || fail "quedan ${FREE_GB} GB libres en disco; hacen falta 5."

MIGRATIONS="$(git diff --name-only --diff-filter=AM "$OLD_SHA" "$SHA" -- app/Database/Migrations/ || true)"
if [ -n "$MIGRATIONS" ]; then
    say "trae migraciones (el arranque las aplica en todos los negocios):"
    echo "$MIGRATIONS" | sed 's/^/    /'
fi

# ---------------------------------------------------------------------------
# 3. Respaldo comprobado y punto de vuelta atrás
# ---------------------------------------------------------------------------
BACKUP_DIR="$BACKUP_ROOT/$ENV_NAME-$STAMP-${OLD_SHA:0:9}"
mkdir -p "$BACKUP_DIR"

SCHEMAS="$(sql "SELECT db_name FROM platform_control.tenants ORDER BY db_name")"
[ -n "$SCHEMAS" ] || fail "el registro de negocios (platform_control.tenants) no devolvió esquemas."
SCHEMAS="$SCHEMAS platform_control"

for db in $SCHEMAS; do
    dc exec -T mysql sh -c 'mariadb-dump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers "$1"' sh "$db" > "$BACKUP_DIR/$db.sql"
    tail -1 "$BACKUP_DIR/$db.sql" | grep -q 'Dump completed' || fail "el respaldo de $db quedó incompleto ($BACKUP_DIR/$db.sql)."
done

# Un respaldo que no se ha restaurado es una esperanza. Se restaura cada uno en una base
# temporal y se comparan los conteos de las tablas que importan.
VERIF="deploy_verif_$$"
for db in $SCHEMAS; do
    [ "$db" = platform_control ] && continue
    sql "DROP DATABASE IF EXISTS $VERIF; CREATE DATABASE $VERIF"
    # Aquí sí va stdin (el respaldo), así que no se usa dc(), que lo cierra.
    docker compose exec -T mysql sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" "$1"' sh "$VERIF" < "$BACKUP_DIR/$db.sql" \
        || { sql "DROP DATABASE IF EXISTS $VERIF"; fail "el respaldo de $db no se pudo restaurar."; }
    for t in ospos_sales ospos_items ospos_people ospos_expenses; do
        live="$(sql "SELECT COUNT(*) FROM \`$db\`.$t")"
        restored="$(sql "SELECT COUNT(*) FROM $VERIF.$t")"
        if [ "$live" != "$restored" ]; then
            # Entre el respaldo y el conteo pudo entrar una venta; se reintenta una vez.
            live="$(sql "SELECT COUNT(*) FROM \`$db\`.$t")"
            [ "$live" -ge "$restored" ] && [ $((live - restored)) -le 2 ] \
                || { sql "DROP DATABASE IF EXISTS $VERIF"; fail "respaldo de $db: $t tiene $restored filas y la base viva $live."; }
        fi
    done
    sql "DROP DATABASE IF EXISTS $VERIF"
done
say "respaldo comprobado de $(echo "$SCHEMAS" | wc -w) esquemas en $BACKUP_DIR ($(du -sh "$BACKUP_DIR" | cut -f1))."

ROLLBACK_TAG=""
if docker image inspect "$IMAGE" >/dev/null 2>&1; then
    ROLLBACK_TAG="casaletto-ospos:rollback-$ENV_NAME-$STAMP-${OLD_SHA:0:9}"
    docker tag "$IMAGE" "$ROLLBACK_TAG"
    say "imagen de vuelta atrás: $ROLLBACK_TAG"
fi

# ---------------------------------------------------------------------------
# 4. Construir sin parar nada
# ---------------------------------------------------------------------------
git reset --quiet --hard "$SHA"
git clean -fdq

if ! dc build ospos; then
    git reset --quiet --hard "$OLD_SHA"
    fail "la imagen no se pudo construir. No se tocó lo que corre (${OLD_SHA:0:9})."
fi

# ---------------------------------------------------------------------------
# 5. Cambiar y verificar
# ---------------------------------------------------------------------------
verify() {
    local since="$1" i status

    for i in $(seq 1 60); do
        status="$(docker inspect -f '{{.State.Status}}' "$(dc ps -q ospos)" 2>/dev/null || echo ausente)"
        if [ "$status" = exited ] || [ "$status" = dead ]; then
            say "el contenedor de la aplicación se detuvo:"
            dc logs --since "$since" ospos | tail -20
            return 1
        fi
        if dc logs --since "$since" ospos 2>/dev/null | grep -q '\[entrypoint\] All schemas current\.'; then
            break
        fi
        if [ "$i" = 60 ]; then
            say "tras 3 minutos el arranque no dijo «All schemas current.»:"
            dc logs --since "$since" ospos | grep entrypoint | tail -10
            return 1
        fi
        sleep 3
    done
    say "arranque: All schemas current."

    if [ "${SELFTEST_FAIL_VERIFY:-0}" = 1 ] && [ "$ENV_NAME" = staging ] && [ "${VERIFY_PASS:-1}" = 1 ]; then
        say "PRUEBA: se fuerza el fallo de la verificación (SELFTEST_FAIL_VERIFY=1)."
        return 1
    fi

    # Cada negocio, por Traefik, como lo ve un cliente. Se da unos segundos a que Traefik
    # descubra el contenedor nuevo.
    local hosts host body code ok=1
    hosts="$LEGACY_HOST"
    for slug in $(sql "SELECT slug FROM platform_control.tenants WHERE status = 'active' ORDER BY slug"); do
        hosts="$hosts $slug.$SAAS_DOMAIN"
    done

    for host in $hosts; do
        for i in $(seq 1 10); do
            body="$(curl -sk --max-time 15 --resolve "$host:443:127.0.0.1" -w '\n%{http_code}' "https://$host/login" || true)"
            code="$(printf '%s' "$body" | tail -1)"
            if [ "$code" = 200 ] \
                && printf '%s' "$body" | grep -q "<base href=\"https://$host/\">" \
                && printf '%s' "$body" | grep -q '\.css'; then
                break
            fi
            sleep 3
        done
        if [ "$code" = 200 ] && printf '%s' "$body" | grep -q "<base href=\"https://$host/\">" && printf '%s' "$body" | grep -q '\.css'; then
            say "  ok  $host"
        else
            say "  MAL $host (HTTP $code$(printf '%s' "$body" | grep -q '\.css' || echo ', sin CSS'))"
            ok=0
        fi
    done

    [ "$ok" = 1 ]
}

SWITCHED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
dc up -d --no-build

if verify "$SWITCHED_AT"; then
    RESULT=ok
else
    RESULT=fallo
    if [ -n "$MIGRATIONS" ]; then
        say "VERIFICACIÓN FALLIDA con migraciones: NO se vuelve atrás solo."
        say "Una imagen más vieja que el esquema deja a todos sin poder entrar. Para volver:"
        say "  cd $DIR"
        say "  restaurar cada esquema de $BACKUP_DIR (mariadb <esquema> < archivo.sql)"
        say "  docker tag $ROLLBACK_TAG $IMAGE && git reset --hard $OLD_SHA && docker compose up -d --no-build"
    elif [ -n "$ROLLBACK_TAG" ]; then
        say "VERIFICACIÓN FALLIDA: vuelta atrás a ${OLD_SHA:0:9} ($ROLLBACK_TAG)."
        docker tag "$ROLLBACK_TAG" "$IMAGE"
        git reset --quiet --hard "$OLD_SHA"
        ROLLED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        dc up -d --no-build
        if VERIFY_PASS=2 verify "$ROLLED_AT"; then
            RESULT=vuelta-atras
            say "vuelta atrás verificada: corre otra vez ${OLD_SHA:0:9}."
        else
            RESULT=vuelta-atras-fallida
            say "LA VUELTA ATRÁS TAMBIÉN FALLÓ. Revisar a mano ya. Respaldo: $BACKUP_DIR"
        fi
    else
        say "VERIFICACIÓN FALLIDA y no había imagen anterior que etiquetar. Revisar a mano. Respaldo: $BACKUP_DIR"
    fi
fi

echo "$STAMP $ENV_NAME ${OLD_SHA:0:9} -> ${SHA:0:9} $RESULT backup=$BACKUP_DIR rollback=${ROLLBACK_TAG:-ninguna}" >> "$DEPLOY_LOG"
[ "$RESULT" = ok ] || exit 1

# ---------------------------------------------------------------------------
# 6. Después de un despliegue sano
# ---------------------------------------------------------------------------
# Un permiso nuevo deja atrás al empleado de soporte (AGENTS.md). No va en el arranque a
# propósito: un fallo aquí no debe tumbar la caja. Por eso tampoco tumba el despliegue.
if ! dc exec -T ospos php spark platform:support-employee; then
    say "AVISO: platform:support-employee falló; correrlo a mano."
fi

# Limpieza: imágenes sin etiqueta, y solo las últimas vueltas atrás y respaldos de este ambiente.
docker image prune -f >/dev/null
docker images --format '{{.Repository}}:{{.Tag}}' | grep "^casaletto-ospos:rollback-$ENV_NAME-" | sort -r \
    | tail -n +$((KEEP_ROLLBACK_TAGS + 1)) | xargs -r docker rmi >/dev/null || true
{ ls -1d "$BACKUP_ROOT/$ENV_NAME"-2* 2>/dev/null || true; } | sort -r | tail -n +$((KEEP_BACKUPS + 1)) | xargs -r rm -rf

say "listo: $ENV_NAME corre ${SHA:0:9}. Respaldo $BACKUP_DIR, vuelta atrás ${ROLLBACK_TAG:-ninguna}."
