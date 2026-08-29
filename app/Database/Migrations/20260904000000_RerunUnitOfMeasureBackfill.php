<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

require_once __DIR__ . '/20260903000000_BackfillUnitOfMeasureFromDescription.php';

/**
 * Runs the unit-of-measure backfill again, because its first run did nothing.
 *
 * 20260903000000 guarded itself with `$this->db->fieldExists('unit_of_measure', 'items')`. In a
 * migration run that guard is a trap: fieldExists() answers from a schema list the connection
 * cached the first time it touched the table, which is BEFORE 20260901000000 -- in the same process,
 * milliseconds earlier -- added the column. So on the deploy that created the column the guard said
 * "no such column", the backfill returned early, and CodeIgniter still recorded the version as
 * applied. It reported success and converted nothing.
 *
 * It went unnoticed in staging for the most ordinary reason: there the column already existed from
 * an earlier deploy, so the stale cache happened to be right.
 *
 * 20260903000000 now calls resetDataCache() first and is correct for any tenant that has not run it
 * yet. This migration exists for the one that has: its version row is already written, so it will
 * never run again on its own, and 78 items are still sitting on 'unit' while their description says
 * kilogramo.
 *
 * It delegates instead of copying the logic -- one definition of what counts as a kilogram, one
 * place to fix. The backfill only touches rows still holding the default, so running it a second
 * time on a tenant where it already worked changes nothing.
 */
class Migration_RerunUnitOfMeasureBackfill extends Migration
{
    public function up(): void
    {
        (new Migration_BackfillUnitOfMeasureFromDescription($this->forge))->up();
    }

    /**
     * Deliberately empty. Reverting is 20260903000000's job; undoing it twice would only risk
     * reverting a unit somebody set by hand in between.
     */
    public function down(): void
    {
    }
}
