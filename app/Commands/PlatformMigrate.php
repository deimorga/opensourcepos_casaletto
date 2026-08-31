<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Runs the Platform namespace's migrations with their history stored in
 * `platform_control` itself, instead of in whichever tenant schema the
 * `default` connection happens to point at.
 *
 * WHY THIS COMMAND EXISTS
 *
 * `php spark migrate -n Platform -g platform` does NOT put the history where
 * the name suggests. MigrationRunner::latest() calls ensureTable() *before*
 * setGroup(), and $this->db was already resolved in the constructor from the
 * DEFAULT group (MigrationRunner.php:156). The -g flag only picks which
 * migrations run and which connection their DDL uses; the `migrations` table is
 * always written on the default connection.
 *
 * The consequence was found in production on 2026-08-31: the four Platform
 * migrations were recorded in `ospos.ospos_migrations` -- Casaletto's database
 * -- and `platform_control` had no history table at all. That is a hidden
 * dependency of the platform on one client's schema, and it is the opposite of
 * what the multi-tenant split exists for. Worse, because TenantResolver
 * repoints the default connection per host, running the built-in command from a
 * different context makes the runner miss the history entirely and try to
 * re-create `tenants` and `platform_accounts`.
 *
 * Passing the platform connection into the runner puts $this->db -- and
 * therefore ensureTable() and the whole history -- on `platform_control`.
 *
 * FIRST RUN ON AN EXISTING INSTALL
 *
 * An environment provisioned before this command exists already has the tables
 * but no history in `platform_control`, so the runner would consider every
 * migration pending and fail on CREATE TABLE. This command refuses that case
 * rather than attempting it, and points at `platform:adopt-history`, which
 * imports the existing rows as a deliberate, reviewable step.
 *
 * Deliberately NOT a wrapper around the built-in `migrate` command, for the
 * same reason as TenantMigrateOne: that command swallows every Throwable and
 * always exits 0, so a failed migration reports success.
 */
class PlatformMigrate extends BaseCommand
{
    protected $group       = 'Platform';
    protected $name        = 'platform:migrate';
    protected $description = 'Migrate the Platform namespace, keeping its history in platform_control. Exits non-zero on failure.';

    public function run(array $params)
    {
        try {
            $platformDb = db_connect('platform');

            // The connection caches its table list, and this command may run
            // right after something created a table on the same connection.
            // Trusting a stale list here would skip the guard below.
            $platformDb->resetDataCache();

            if ($platformDb->tableExists('tenants') && ! $this->hasPlatformHistory($platformDb)) {
                CLI::error('platform_control has the platform tables but no migration history of its own.');
                CLI::write('Running now would treat every migration as pending and fail on CREATE TABLE.');
                CLI::write('Run `php spark platform:adopt-history` first -- it imports the rows that already exist.');

                return 1;
            }

            // false: a fresh runner, never the shared instance, so the platform
            // connection cannot leak into anything else resolving 'migrations'.
            $runner = service('migrations', null, $platformDb, false);
            $runner->setNamespace('Platform');

            if (! $runner->latest('platform')) {
                CLI::error('Migration reported failure for the Platform namespace.');

                return 1;
            }

            foreach ($runner->getCliMessages() as $message) {
                CLI::write($message);
            }

            CLI::write('Platform schema is current.', 'green');
        } catch (Throwable $e) {
            CLI::error('platform:migrate: ' . $e->getMessage());

            return 1;
        }

        return 0;
    }

    private function hasPlatformHistory(BaseConnection $platformDb): bool
    {
        if (! $platformDb->tableExists('migrations')) {
            return false;
        }

        return $platformDb->table('migrations')
            ->where('namespace', 'Platform')
            ->countAllResults() > 0;
    }
}
