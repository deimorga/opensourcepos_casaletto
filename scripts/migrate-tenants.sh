#!/usr/bin/env bash
#
# Multi-tenant migration orchestrator.
#
# Runs the App-namespace migrations against every active tenant's own
# schema, one at a time, by overriding MYSQL_DB_NAME per invocation --
# the same env var app/Config/Database.php already reads for the
# `default` group, so no connection-swap mechanism is needed here.
#
# Must run from the app root (where `spark` lives), inside the app
# container/image so `php` and the App migrations are available. This is
# also WHY it is invoked from docker/entrypoint.sh and not from the
# deploy workflow: the migration files ship inside the image, so they do
# not exist anywhere until that image is on the host, and the workflow
# only fires a webhook at the VPS -- it has no shell there.
#
# Exit code: 0 only if every schema migrated cleanly. Non-zero if any
# failed -- the entrypoint must then refuse to start Apache, because a
# schema behind the running code makes Load_config destroy the session on
# every request (app/Events/Load_config.php) and nobody can stay logged in.

set -uo pipefail

failures=()
migrated=0

# spark writes its own banner line to stdout regardless of which command
# runs, so only lines carrying our own TENANT_DB: marker
# (app/Commands/TenantList.php) are trusted as real tenant names.
#
# The exit status is captured separately and on purpose. Grepping the
# output alone cannot tell "the registry answered and has no tenants"
# apart from "the registry was unreachable and printed a stack trace" --
# both yield zero matching lines. Treating the second as the first is how
# you migrate nothing, report success, and boot a container whose tenants
# are all behind.
tenant_list_output="$(php spark tenant:list 2>&1)"
tenant_list_status=$?

if [ $tenant_list_status -ne 0 ]; then
    echo "FAILED: could not read the tenant registry (exit $tenant_list_status)."
    echo "$tenant_list_output"
    exit 1
fi

# A stack trace with a zero exit status is still a failure. spark is not
# consistent about propagating exceptions as exit codes, so the output is
# checked too.
if echo "$tenant_list_output" | grep -qiE '^(ERROR|.*Exception|.*Fatal error)'; then
    echo "FAILED: the tenant registry reported an error."
    echo "$tenant_list_output"
    exit 1
fi

tenants="$(echo "$tenant_list_output" | grep '^TENANT_DB:' | sed 's/^TENANT_DB://')"

if [ -z "$tenants" ]; then
    # The registry answered and has no active tenants: this is a
    # single-tenant install (local development, or an environment from
    # before the multi-tenant rollout). Migrate the default schema, which
    # is what MYSQL_DB_NAME already points at.
    echo "No active tenants registered -- migrating the default schema instead."
    if php spark tenant:migrate-one; then
        echo "  OK: default schema"
        exit 0
    fi
    echo "  FAILED: default schema"
    exit 1
fi

while IFS= read -r db_name; do
    [ -z "$db_name" ] && continue

    echo "Migrating tenant schema: $db_name"

    # tenant:migrate-one (not the built-in `migrate`) -- `migrate`
    # swallows every exception internally and always exits 0, which would
    # make this loop blind to real failures. MYSQL_DB_NAME (read by
    # Config\Database's constructor, not mutated at runtime) is what
    # actually selects the schema -- see the long comment in
    # TenantMigrateOne.php for why the in-process mutation approach that
    # works for TenantResolver does not reliably work here.
    if MYSQL_DB_NAME="$db_name" php spark tenant:migrate-one; then
        echo "  OK: $db_name"
        migrated=$((migrated + 1))
    else
        echo "  FAILED: $db_name"
        failures+=("$db_name")
    fi
done <<< "$tenants"

echo ""
echo "Migrated $migrated tenant(s) successfully."

if [ ${#failures[@]} -gt 0 ]; then
    echo "FAILED tenants (${#failures[@]}): ${failures[*]}"
    echo "Deploy must not proceed until these are fixed and re-run."
    exit 1
fi

echo "All active tenants migrated cleanly."
exit 0
