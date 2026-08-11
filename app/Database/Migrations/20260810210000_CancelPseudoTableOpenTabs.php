<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Migration_CancelPseudoTableOpenTabs extends Migration
{
    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        helper('migration');
        executeScriptWithTransaction(APPPATH . 'Database/Migrations/sqlscripts/cancel_pseudo_table_open_tabs.sql');
    }

    /**
     * Revert a migration step.
     */
    public function down(): void
    {
    }
}
