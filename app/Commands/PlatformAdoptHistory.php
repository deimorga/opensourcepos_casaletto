<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * One-time, per-environment step: copies the Platform namespace's migration
 * history out of whichever tenant schema it was recorded in and into
 * `platform_control`, so `platform:migrate` can take over.
 *
 * See app/Commands/PlatformMigrate.php for why the history ended up in a
 * client's database in the first place. Short version: the built-in `migrate`
 * command always writes its history on the DEFAULT connection, whatever `-g`
 * says.
 *
 * WHAT IT DOES NOT DO
 *
 * It does not delete the rows from the tenant schema. Two reasons, and both
 * matter more than tidiness:
 *
 *  1. Writing to a client's database is exactly what this whole module is
 *     trying to stop doing. Reading from it is enough.
 *  2. Those leftover rows are a safety net: if somebody later runs the built-in
 *     `php spark migrate -n Platform -g platform` out of habit, it reads that
 *     same table, sees the migrations as applied, and does nothing -- instead of
 *     trying to re-create `tenants`.
 *
 * Runs three read-only checks and refuses rather than working around them,
 * following TenantProvisioner::adopt().
 */
class PlatformAdoptHistory extends BaseCommand
{
    protected $group       = 'Platform';
    protected $name        = 'platform:adopt-history';
    protected $description = 'One-time: import the Platform migration history into platform_control so platform:migrate owns it.';

    public function run(array $params)
    {
        try {
            $platformDb = db_connect('platform');
            $sourceDb   = db_connect();

            // Both connections cache their table list. Adoption decides what to
            // do based on which tables exist, so it has to ask the server, not
            // a snapshot taken before this command created anything.
            $platformDb->resetDataCache();
            $sourceDb->resetDataCache();

            if (! $platformDb->tableExists('tenants')) {
                CLI::error('platform_control has no `tenants` table, so there is nothing to adopt.');
                CLI::write('This looks like a fresh environment: run `php spark platform:migrate` instead.');

                return 1;
            }

            if ($platformDb->tableExists('migrations')
                && $platformDb->table('migrations')->where('namespace', 'Platform')->countAllResults() > 0) {
                CLI::error('platform_control already owns its Platform history. Nothing to do.');

                return 1;
            }

            $rows = $this->readSourceHistory($sourceDb);

            if ($rows === []) {
                CLI::error('No Platform rows found in ' . $sourceDb->getDatabase() . '.' . config('Migrations')->table . '.');
                CLI::write('Point the default connection at the schema where the platform was first migrated,');
                CLI::write('or, if this environment never ran them, use `php spark platform:migrate`.');

                return 1;
            }

            // ensureTable() is public and builds exactly the schema the runner
            // expects; duplicating that definition here would drift from it.
            $runner = service('migrations', null, $platformDb, false);
            $runner->ensureTable();

            $platformDb->transStart();

            foreach ($rows as $row) {
                $platformDb->table('migrations')->insert([
                    'version'   => $row->version,
                    'class'     => $row->class,
                    'group'     => $row->group,
                    'namespace' => $row->namespace,
                    'time'      => $row->time,
                    'batch'     => $row->batch,
                ]);
            }
            $platformDb->transComplete();

            if ($platformDb->transStatus() === false) {
                CLI::error('The import failed and was rolled back. platform_control is unchanged.');

                return 1;
            }

            CLI::write('Imported ' . count($rows) . ' Platform migration(s) into platform_control.', 'green');
            CLI::write('The rows in ' . $sourceDb->getDatabase() . ' were left untouched, on purpose.');
            CLI::write('From now on use `php spark platform:migrate`.');
        } catch (Throwable $e) {
            CLI::error('platform:adopt-history: ' . $e->getMessage());

            return 1;
        }

        return 0;
    }

    /**
     * @return list<object>
     */
    private function readSourceHistory(BaseConnection $sourceDb): array
    {
        $table = config('Migrations')->table;

        if (! $sourceDb->tableExists($table)) {
            return [];
        }

        return $sourceDb->table($table)
            ->where('namespace', 'Platform')
            ->orderBy('version', 'ASC')
            ->get()
            ->getResult();
    }
}
