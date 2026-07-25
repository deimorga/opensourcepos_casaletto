<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Migration_Initial_Schema extends Migration
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Perform a migration step.
     * Only runs on fresh installs - skips if database already has tables.
     *
     * For testing: CI4's DatabaseTestTrait with $refresh=true handles table
     * cleanup/creation automatically. This migration only loads initial schema
     * on fresh databases where no application tables exist.
     */
    public function up(): void
    {
        // listTables()/tableExists() cache their result, and that cache can be
        // stale by the time this runs (e.g. left over from a prior migration
        // cycle earlier in the same process/connection -- see down() below).
        // Force a fresh read so this "already populated?" check reflects the
        // database as it actually is right now.
        $this->db->resetDataCache();

        // Check if core application tables exist (existing install)
        // Note: migrations table may exist even on fresh DB due to migration tracking
        $tables = $this->db->listTables();

        // Check for a core application table, not just migrations table
        foreach ($tables as $table) {
            // Strip prefix if present for comparison
            $tableName = str_replace($this->db->getPrefix(), '', $table);
            if (in_array($tableName, ['app_config', 'items', 'employees', 'people'])) {
                // Database already populated - skip initial schema
                // This is an existing installation upgrading from older version
                return;
            }
        }

        // Fresh install - load initial schema
        helper('migration');
        execute_script(APPPATH . 'Database/Migrations/sqlscripts/initial_schema.sql');
    }

    /**
     * Revert a migration step.
     * Cannot revert initial schema - would lose all data.
     */
    public function down(): void
    {
        // Cannot safely revert initial schema
        // Would require dropping all tables which would lose all data
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');

        // Never drop the migrations tracking table itself: MigrationRunner::regress()
        // writes to it immediately after this down() returns, to record that this
        // migration was rolled back. Dropping it here breaks that write (and any
        // later regress()/migrate() call in the same process, e.g. a second
        // DatabaseTestTrait test class run back-to-back with $refresh=true).
        $migrationsTable = $this->db->getPrefix() . config('Migrations')->table;

        // Force a fresh table list before deciding what to drop -- listTables()
        // caches its result, and that cache can already be stale here (e.g. left
        // over from an earlier migration cycle in this same process/connection
        // that only ever saw the migrations table). Reading the stale list would
        // make this loop silently drop nothing, leaving the real tables behind
        // for up() to find and wrongly treat as "already populated".
        $this->db->resetDataCache();

        foreach ($this->db->listTables() as $table) {
            if ($table === $migrationsTable) {
                continue;
            }

            $this->db->query('DROP TABLE IF EXISTS `' . $table . '`');
        }

        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');

        // Reset again so the DROPs above (raw queries, not invalidated
        // automatically) don't leave a stale list behind for whatever runs
        // next -- most importantly this migration's own up() (see above).
        $this->db->resetDataCache();
    }
}
