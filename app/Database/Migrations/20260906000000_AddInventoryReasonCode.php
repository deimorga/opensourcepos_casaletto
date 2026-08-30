<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;

/**
 * Classifies an inventory movement with a stable, language-independent reason.
 *
 * Why a column on `inventory` and not a table of its own: `inventory` already *is* the audit trail
 * of every stock movement -- who, when, where, how much and a free-text comment -- and a write-off
 * is a stock movement, not a different kind of event. A side table would mean a second row to keep
 * in step with the first, a join on every read, and two places where a movement can exist without
 * the other half. What was actually missing is one classifier on the row that already exists.
 * See docs/Tecnico/venta-por-peso-y-hardware-de-caja.md section 6.1, which says exactly that: "no
 * se inventa un rastro nuevo, se clasifica el que ya hay".
 *
 * NULL is not a missing value. Sales, receivings and plain manual adjustments are movements that
 * have no reason to classify, and every movement recorded before this column existed predates the
 * classification -- it is not incomplete data, so nothing is backfilled and nothing is guessed.
 * Same contract as `cash_source` on expenses (20260823040000).
 *
 * The code is a code, not a label: 'damaged' | 'shrinkage' | 'theft' | 'data_entry'. Storing the
 * translated word is what made the expenses payment filter unable to match a single row, because
 * the label depends on the locale the row was written under. See 20260823010000_AddPaymentTypeCode.
 * The list of accepted codes lives in App\Models\Inventory::WRITE_OFF_REASON_CODES.
 */
class Migration_AddInventoryReasonCode extends Migration
{
    private const TABLE = 'inventory';
    private const COLUMN = 'reason_code';
    private const INDEX = 'idx_inventory_reason_code';

    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        $this->addColumn();
        $this->addIndex();

        CLI::write('AddInventoryReasonCode: inventory.reason_code is available. Existing movements keep NULL on purpose -- they predate the classification.');
    }

    /**
     * Revert a migration step.
     *
     * MySQL drops an index with the column it covers, so the index is not dropped separately.
     */
    public function down(): void
    {
        $this->db->resetDataCache();

        if ($this->db->fieldExists(self::COLUMN, self::TABLE)) {
            $this->forge->dropColumn(self::TABLE, self::COLUMN);
        }
    }

    /**
     * resetDataCache() before fieldExists(): the field list is cached per connection, and a
     * migration earlier in this same process can have added a column after that cache was filled.
     * Answering from the stale list is how a backfill silently did nothing in production once
     * already (see 20260904000000_RerunUnitOfMeasureBackfill).
     */
    private function addColumn(): void
    {
        $this->db->resetDataCache();

        if ($this->db->fieldExists(self::COLUMN, self::TABLE)) {
            return;
        }

        $this->forge->addColumn(self::TABLE, [
            self::COLUMN => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
                'after'      => 'trans_comment',
            ],
        ]);
    }

    /**
     * Checked separately from the column so a half-applied run -- column added, index not -- still
     * ends up with both.
     *
     * (reason_code, trans_date) in that order because every read is "the write-offs between these
     * two dates": the reason narrows the table from every movement ever recorded down to the
     * write-offs, and the date range then walks a contiguous slice of that.
     */
    private function addIndex(): void
    {
        foreach ($this->db->getIndexData(self::TABLE) as $index) {
            if ($index->name === self::INDEX) {
                return;
            }
        }

        $this->db->query('CREATE INDEX ' . self::INDEX . ' ON ' . $this->db->prefixTable(self::TABLE) . ' (' . self::COLUMN . ', trans_date)');
    }
}
