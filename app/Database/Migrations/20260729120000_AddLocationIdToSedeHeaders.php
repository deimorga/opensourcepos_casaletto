<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds location_id to the header tables that were missing it (sales,
 * cash_up, expenses, receivings, dinner_tables), backfilling existing
 * rows to the lowest stock_locations.location_id.
 */
class AddLocationIdToSedeHeaders extends Migration
{
    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        helper('migration');
        executeScriptWithTransaction(APPPATH . 'Database/Migrations/sqlscripts/add_location_id_to_sede_headers.sql');
    }

    /**
     * Revert a migration step.
     */
    public function down(): void
    {
        $tables = ['sales', 'cash_up', 'expenses', 'receivings', 'dinner_tables'];

        foreach ($tables as $table) {
            $this->db->query("ALTER TABLE `ospos_$table` DROP FOREIGN KEY `ospos_{$table}_ibfk_location`");
            $this->db->query("ALTER TABLE `ospos_$table` DROP COLUMN `location_id`");
        }
    }
}
