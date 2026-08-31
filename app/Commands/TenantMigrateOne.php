<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
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
            $this->useTenantCredentials((string) getenv('MYSQL_DB_NAME'));

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

    /**
     * Connect as the schema's OWN MySQL user, when it has one.
     *
     * Only MYSQL_DB_NAME is set by the caller -- scripts/migrate-tenants.sh loops over the registry
     * setting exactly that. Everything else stays at the application's shared credentials, which
     * hold privileges on the legacy schema and platform_control and NOTHING else. That worked for as
     * long as the only tenant was the adopted one, whose schema those credentials already owned.
     *
     * The first provisioned tenant broke it, and broke it at the worst moment: the entrypoint
     * migrates every tenant before Apache starts, so a schema it cannot reach means the container
     * refuses to serve and the site is down. That is what happened on 2026-08-31 -- the deploy after
     * the first business was provisioned.
     *
     * The credentials are read here rather than passed in by the caller so they never reach a
     * command line, an environment dump, or the loop's stdout. A tenant row without them (the
     * adopted one) keeps the shared credentials, which is exactly right for it.
     */
    private function useTenantCredentials(string $dbName): void
    {
        if ($dbName === '') {
            return;
        }

        $tenant = db_connect('platform')
            ->table('tenants')
            ->where('db_name', $dbName)
            ->get()
            ->getRow();

        if ($tenant === null || empty($tenant->db_user) || empty($tenant->db_password)) {
            return;
        }

        $dbConfig = config(Database::class);
        $group = &$dbConfig->{$dbConfig->defaultGroup};

        $group['database'] = $dbName;
        $group['username'] = $tenant->db_user;
        $group['password'] = service('encrypter')->decrypt($tenant->db_password);

        // The connection is cached by group name, so one opened before this point would keep the
        // old credentials. Closing it forces the migration runner to open a fresh one.
        Database::connect($dbConfig->defaultGroup, false)->close();
    }
}
