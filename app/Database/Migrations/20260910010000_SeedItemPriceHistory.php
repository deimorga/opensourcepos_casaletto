<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;

/**
 * Gives every item that already exists one starting row in its price history.
 *
 * WHY THE STARTING ROW IS NOT OPTIONAL
 *
 * Without it the history begins halfway through the story. The first time somebody edits a price,
 * the table gets a row whose previous_price has no predecessor, and "what did this cost in March?"
 * answers "I don't know" for every item nobody has touched -- while looking, to the reader, exactly
 * like a complete history. A history with unmarked gaps is worse than no history: it invites
 * confident wrong answers.
 *
 * For Paraiso de la Canasta this row is also what makes the interesting sentence expressible. Its
 * 1.184 items are all at zero, and the seeded row is what lets the trail say "went from 0 to 4.500"
 * rather than "a 4.500 appeared out of nowhere" -- and what lets a report say "these 1.184 have
 * never been priced".
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 *
 * It does not reconstruct history from sales_items.item_unit_price. That is tempting -- Casaletto
 * has two months of lines -- and it is false: a line's price is not the catalogue price. Kit
 * ingredients are forced to '0.00', discounts moved some, and one-off overrides are precisely the
 * thing that cannot be told apart from a real price change. It would manufacture a past that never
 * happened.
 *
 * See docs/Tecnico/repreciar-articulo-desde-venta.md.
 */
class Migration_SeedItemPriceHistory extends Migration
{
    private const HISTORY = 'item_price_history';
    private const ITEMS   = 'items';

    /**
     * Recorded on every seeded row so that whoever reads one knows the timestamp is the moment the
     * history was switched on and not the moment the price was set. items has no created_at, and
     * guessing a date would be the worst of the available options.
     */
    private const NOTE = 'precio del catálogo al activar el historial';

    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        // Before any tableExists(). The driver answers from a schema list built when the process
        // started, so in the same run that created the table one migration earlier this guard would
        // say "not there" and the seed would quietly do nothing. That exact failure has already
        // happened in production once, with the unit-of-measure backfill.
        $this->db->resetDataCache();

        if (!$this->db->tableExists(self::HISTORY)) {
            $this->report('SeedItemPriceHistory: ' . self::HISTORY . ' does not exist, nothing to do.');

            return;
        }

        $history = $this->db->prefixTable(self::HISTORY);
        $items   = $this->db->prefixTable(self::ITEMS);

        $alreadyCovered = (int) $this->db->query(
            "SELECT COUNT(DISTINCT h.item_id) AS n FROM $history h JOIN $items i ON i.item_id = h.item_id"
        )->getRow()->n;

        // The idempotence lives in the WHERE NOT EXISTS rather than in a flag. Running this twice is
        // a no-op, and running it late -- after some items already have real history -- is still
        // correct for every item it has not reached yet.
        //
        // Items priced at zero are included: zero is a fact about the catalogue, not a missing
        // value. So are deleted items: Sale::save_value() undeletes an item when one comes back on a
        // return, and it would reappear with a history that starts in mid-air.
        $this->db->query(
            "INSERT INTO $history (item_id, previous_price, new_price, changed_at, employee_id, source, sale_id, note)
             SELECT i.item_id, NULL, i.unit_price, ?, NULL, 'seed', NULL, ?
               FROM $items i
              WHERE NOT EXISTS (SELECT 1 FROM $history h WHERE h.item_id = i.item_id)",
            [date('Y-m-d H:i:s'), self::NOTE]
        );

        $seeded = $this->db->affectedRows();
        $total  = (int) $this->db->query("SELECT COUNT(*) AS n FROM $items")->getRow()->n;

        $this->report("SeedItemPriceHistory: seeded $seeded of $total item(s).");

        // Reporting what was left alone, not only what was done: an operator reading a bare "seeded
        // 0" cannot tell success from a no-op that should have been a success.
        if ($alreadyCovered > 0) {
            $this->report("  $alreadyCovered item(s) already had history and were left untouched.");
        }
    }

    /**
     * Revert a migration step.
     *
     * Only the seeded rows go, and only while they are still exactly as this migration wrote them.
     * A row whose source has been changed, or any row of any other source, is somebody's real
     * record and is not this migration's to delete.
     */
    public function down(): void
    {
        $this->db->resetDataCache();

        if (!$this->db->tableExists(self::HISTORY)) {
            return;
        }

        $this->db->table(self::HISTORY)
            ->where('source', 'seed')
            ->where('note', self::NOTE)
            ->delete();

        $this->report('SeedItemPriceHistory: removed ' . $this->db->affectedRows() . ' seeded row(s).');
    }

    /**
     * Migrations are also run from the web installer, where CLI output has nowhere to go.
     *
     * CLI::write and not log_message: the production log threshold is 4, which throws away anything
     * below "critical", and this is progress rather than an error.
     */
    private function report(string $message): void
    {
        if (is_cli()) {
            CLI::write($message);
        }
    }
}
