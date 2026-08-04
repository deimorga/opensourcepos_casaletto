#!/usr/bin/env bash
#
# Multi-tenant migration orchestrator (Fase 5 of the multi-tenant plan).
#
# Runs `php spark migrate` (App namespace, `default` connection group)
# against every active tenant's own schema, one at a time, by
# overriding MYSQL_DB_NAME per invocation -- the same env var
# app/Config/Database.php already reads for the `default` group, so no
# new connection-swap mechanism is needed here beyond what Fase 4 built.
#
# Must run from the app root (where `spark` lives), inside the app
# container/image so `php` and the App migrations are available.
#
# Exit code: 0 only if every active tenant migrated cleanly (including
# the "zero tenants" case). Non-zero if any tenant failed -- the
# caller (deploy pipeline) must not promote the app container in that
# case, or tenants left mid-migration would serve traffic against a
# schema the new code doesn't fully match.
#
# NOT wired into deploy-staging.yml/deploy-production.yml yet: those
# environments don't have a platform_control schema until the rest of
# the multi-tenant rollout (Fase 10) actually happens there.

set -uo pipefail

failures=()
migrated=0

# spark writes its own banner line to stdout regardless of which
# command runs, so only lines carrying our own TENANT_DB: marker
# (app/Commands/TenantList.php) are trusted as real tenant names.
tenants="$(php spark tenant:list | grep '^TENANT_DB:' | sed 's/^TENANT_DB://')"

if [ -z "$tenants" ]; then
    echo "No active tenants to migrate."
    exit 0
fi

while IFS= read -r db_name; do
    [ -z "$db_name" ] && continue

    echo "Migrating tenant schema: $db_name"

    # tenant:migrate-one (not the built-in `migrate`) -- `migrate`
    # swallows every exception internally and always exits 0, which
    # would make this loop blind to real failures. MYSQL_DB_NAME (read
    # by Config\Database's constructor, not mutated at runtime) is what
    # actually selects the schema -- see the long comment in
    # TenantMigrateOne.php for why the in-process mutation approach
    # that works for TenantResolver doesn't reliably work here.
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
