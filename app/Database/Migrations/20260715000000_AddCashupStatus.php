<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Migration_AddCashupStatus extends Migration
{
    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        helper('migration');
        executeScriptWithTransaction(APPPATH . 'Database/Migrations/sqlscripts/add_cashup_status.sql');
    }

    /**
     * Revert a migration step.
     */
    public function down(): void
    {
    }
}
