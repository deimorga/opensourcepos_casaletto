<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;

/**
 * Records which pocket a cash expense was paid from.
 *
 * "Cash" alone cannot answer the only question a cash-up needs answered: how much money should be
 * in the till right now. Money paid out of the till lowers that figure, money paid out of cash that
 * was already collected does not, and until now both were stored identically.
 *
 * NULL is not a missing value here. An expense paid by bank transfer came out of no cash pocket at
 * all, so the question does not apply and the column stays empty on purpose.
 *
 * The backfill marks the existing cash expenses as 'register'. In production all 55 of them were
 * entered by the cashier, who only ever reaches the till, and the arithmetic agrees: the daily
 * cash-up balances precisely because that money left the drawer. The 2 bank transfers keep NULL.
 *
 * See docs/Tecnico/cuadre-de-caja-y-origen-del-efectivo.md sections 3 and 7.
 */
class Migration_AddExpenseCashSource extends Migration
{
    private const TABLE = 'expenses';
    private const COLUMN = 'cash_source';
    private const INDEX = 'idx_cash_source';

    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        $this->addColumn();
        $this->addIndex();

        $backfilled = $this->backfill();

        CLI::write('AddExpenseCashSource: ' . $backfilled . " cash expense(s) marked as 'register'.");

        $this->reportRemainingNulls();
    }

    /**
     * Revert a migration step.
     *
     * Dropping the column takes its index with it on MySQL, so the index is not dropped separately.
     */
    public function down(): void
    {
        if ($this->db->fieldExists(self::COLUMN, self::TABLE)) {
            $this->forge->dropColumn(self::TABLE, self::COLUMN);
        }
    }

    private function addColumn(): void
    {
        if ($this->db->fieldExists(self::COLUMN, self::TABLE)) {
            return;
        }

        // Sits next to the payment method it qualifies. Falls back to payment_type on an
        // installation where AddPaymentTypeCode has not run, rather than failing the ALTER.
        $after = $this->db->fieldExists('payment_type_code', self::TABLE) ? 'payment_type_code' : 'payment_type';

        $this->forge->addColumn(self::TABLE, [
            self::COLUMN => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
                'after'      => $after,
            ],
        ]);
    }

    /**
     * Checked separately from the column so a half-applied run -- column added, index not -- still
     * ends up with both.
     */
    private function addIndex(): void
    {
        foreach ($this->db->getIndexData(self::TABLE) as $index) {
            if ($index->name === self::INDEX) {
                return;
            }
        }

        $this->db->query('CREATE INDEX ' . self::INDEX . ' ON ' . $this->db->prefixTable(self::TABLE) . ' (' . self::COLUMN . ')');
    }

    /**
     * Only rows that are still undecided are touched, so re-running the migration cannot overwrite
     * a source somebody has since corrected by hand.
     *
     * @return int rows marked as 'register'
     */
    private function backfill(): int
    {
        $this->db->table(self::TABLE)
            ->where('payment_type_code', 'cash')
            ->where(self::COLUMN . ' IS NULL')
            ->update([self::COLUMN => 'register']);

        return $this->db->affectedRows();
    }

    /**
     * Says what was left empty and why, broken down by payment method, instead of leaving the
     * operator to work out whether an empty column means "does not apply" or "we lost track".
     */
    private function reportRemainingNulls(): void
    {
        $rows = $this->db->table(self::TABLE)
            ->select('payment_type_code, COUNT(*) AS total')
            ->where(self::COLUMN . ' IS NULL')
            ->groupBy('payment_type_code')
            ->get()
            ->getResultArray();

        if ($rows === []) {
            CLI::write('  Every expense now has a cash source.');

            return;
        }

        foreach ($rows as $row) {
            if ($row['payment_type_code'] === null) {
                // A row whose payment method could not be coded cannot be classified either, and
                // it will not be counted against the till. That is a gap in the cash-up, not a
                // tidy "does not apply", so it is logged at a level production keeps.
                $message = 'AddExpenseCashSource: ' . $row['total'] . ' expense(s) have no payment type code, so no cash source could be decided. They will not count towards the expected cash in the till -- review them before relying on the cash-up.';

                CLI::write('  ! ' . $message);
                log_message('critical', $message);

                continue;
            }

            CLI::write('  ' . $row['total'] . ' ' . $row['payment_type_code'] . ' expense(s) left null: not paid in cash, so the question does not apply.');
        }
    }
}
