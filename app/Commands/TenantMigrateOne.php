<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Runs the App namespace's migrations against whichever schema the
 * `default` connection currently points at -- used by
 * scripts/migrate-tenants.sh, which sets MYSQL_DB_NAME per tenant
 * before invoking this (one child process per tenant), the same
 * env-var mechanism app/Config/Database.php's constructor already
 * reads at boot. Mutating config(Database::class)->default at runtime
 * inside this command's own run() was tried first and confirmed
 * unreliable here -- unlike TenantResolver (a filter, which runs
 * before anything else touches `default`), by the time a spark
 * command's run() executes something has already resolved the
 * `default` connection, so a same-process mutation doesn't reliably
 * take effect for every code path (execute_script()'s migrations in
 * particular kept hitting whatever schema was live before the
 * mutation). Env var, read at Config\Database construction, does not
 * have that race.
 *
 * Deliberately NOT a thin wrapper around the built-in `migrate`
 * command: that command catches every Throwable internally and always
 * returns null, which Boot::runCommand() then turns into exit code 0
 * regardless of whether the migration actually succeeded -- confirmed
 * empirically while building the orchestrator (a deliberately
 * unreachable schema still reported "OK" and exit 0). This command
 * returns a real 0/1 so the shell loop in migrate-tenants.sh can trust
 * `$?`.
 */
class TenantMigrateOne extends BaseCommand
{
    protected $group       = 'Platform';
    protected $name        = 'tenant:migrate-one';
    protected $description = 'Migrate the schema MYSQL_DB_NAME points at (App namespace). Exits non-zero on failure, unlike `migrate`.';

    public function run(array $params)
    {
        try {
            $runner = service('migrations');
            $runner->setNamespace('App');

            if (!$runner->latest('default')) {
                CLI::error('Migration reported failure for schema: ' . getenv('MYSQL_DB_NAME'));

                return 1;
            }

            foreach ($runner->getCliMessages() as $message) {
                CLI::write($message);
            }
        } catch (Throwable $e) {
            CLI::error(getenv('MYSQL_DB_NAME') . ': ' . $e->getMessage());

            return 1;
        }

        CLI::write('Migrated schema: ' . getenv('MYSQL_DB_NAME'), 'green');

        return 0;
    }
}
