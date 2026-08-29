#!/usr/bin/env bash
#
# Container entrypoint: bring every tenant schema up to date BEFORE Apache
# accepts a single request, then hand off to the base image's command.
#
# Why this exists
# ---------------
# app/Config/Events.php registers Load_config::load_config() on
# post_controller_constructor -- it runs on EVERY request. Inside it:
#
#     if (!$migration->is_latest()) { $this->session->destroy(); }
#
# So the window between "new image is serving" and "schemas are migrated"
# is not a degraded state where one screen errors out: every request
# destroys the session and nobody can stay logged in. The till does not
# open. With more than one tenant that window used to be guaranteed,
# because migrations were a manual step somebody had to remember.
#
# Running them here closes it: the schemas are current before the first
# request is served, so is_latest() is true from the start.
#
# The order cannot be inverted. Migration FILES ship inside this image,
# so "migrate everything, then deploy the image" is impossible -- the
# files do not exist until the image is here. Nor can the deploy workflow
# do it: it fires a webhook at the VPS and exits, with no shell on the host.
#
# Failure policy: if any schema fails to migrate we exit non-zero and
# Apache never starts. That is deliberate. A container that refuses to
# serve is loud and the previous image can be redeployed; a container
# serving traffic against a half-migrated schema is silent and corrupts
# data. Prefer the loud failure.

set -euo pipefail

log() { echo "[entrypoint] $*"; }

# ---------------------------------------------------------------- wait for db
# depends_on only orders container start, it does not wait for MySQL to
# accept connections. Without this the first migration attempt races the
# database on a cold boot and fails for no real reason.
DB_HOST="${MYSQL_HOST_NAME:-mysql}"
DB_WAIT_SECONDS="${DB_WAIT_SECONDS:-60}"

log "Waiting up to ${DB_WAIT_SECONDS}s for database at ${DB_HOST}..."
waited=0
until php -r '
    $h = getenv("MYSQL_HOST_NAME") ?: "mysql";
    $u = getenv("MYSQL_USERNAME") ?: "";
    $p = getenv("MYSQL_PASSWORD") ?: "";
    $c = @mysqli_connect($h, $u, $p);
    exit($c ? 0 : 1);
' 2>/dev/null; do
    if [ "$waited" -ge "$DB_WAIT_SECONDS" ]; then
        log "FATAL: database at ${DB_HOST} unreachable after ${DB_WAIT_SECONDS}s."
        exit 1
    fi
    sleep 2
    waited=$((waited + 2))
done
log "Database is up (after ${waited}s)."

# -------------------------------------------------------------- run migrations
# SKIP_MIGRATIONS is an escape hatch for the rare case where an operator
# needs the container up to inspect a broken schema by hand. It is not
# part of the normal deploy path and it logs loudly.
if [ "${SKIP_MIGRATIONS:-0}" = "1" ]; then
    log "WARNING: SKIP_MIGRATIONS=1 -- schemas may be behind this image."
    log "WARNING: expect sessions to be destroyed on every request."
else
    log "Migrating tenant schemas..."
    if ! /app/scripts/migrate-tenants.sh; then
        log "FATAL: migrations failed. Apache will NOT start."
        log "Fix the failing schema and redeploy, or redeploy the previous image."
        exit 1
    fi
    log "All schemas current."
fi

exec "$@"
