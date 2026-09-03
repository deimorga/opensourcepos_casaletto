<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Creates the record of every price an item has ever been sold at.
 *
 * WHY THIS TABLE EXISTS
 *
 * A cashier is about to be able to fix an item's price from the till, and that price sticks: it
 * becomes the catalogue price for every sale afterwards. That is a large power to hand to whoever
 * happens to be at the register, and the owner decided -- deliberately -- not to gate it behind a
 * new permission. **This table is the control that replaces the permission.** Without it, a price
 * that changed leaves no trace of who changed it, when, or what it was before.
 *
 * It is not a log of the till. Prices also move from the item form, the bulk edit and the CSV
 * import, and a history that only knew about the register would answer "what did this cost in
 * March?" confidently and wrongly. Capture therefore happens at Item::save_value(), the one place
 * every write to items goes through.
 *
 * WHAT IT IS NOT
 *
 * It is not sales_items. That table already records the price each line was *sold* at, which is a
 * different fact: a price that is set and never sold leaves no row there, and a line sold below
 * catalogue because of a discount is not a price change.
 *
 * Nothing reads or writes this table yet. The migration ships on its own so that the schema is in
 * place, and verifiable, before any code depends on it.
 *
 * See docs/Tecnico/repreciar-articulo-desde-venta.md.
 */
class Migration_AddItemPriceHistory extends Migration
{
    public const TABLE = 'item_price_history';

    /**
     * Every column of the table except the auto-increment primary key.
     *
     * Exposed so the model's $allowedFields can be checked against it: CodeIgniter drops any field
     * missing from $allowedFields without raising anything, and this project has already lost data
     * to that twice.
     */
    public const WRITABLE_COLUMNS = [
        'item_id',
        'previous_price',
        'new_price',
        'changed_at',
        'employee_id',
        'source',
        'sale_id',
        'note',
    ];

    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        // The schema list the driver answers from was built when the process started, so a guard
        // asked before this reset can say "the table is not there" in the very deploy that created
        // it. That is not hypothetical: it happened in production with the unit-of-measure backfill.
        $this->db->resetDataCache();

        if ($this->db->tableExists(self::TABLE)) {
            $this->report('AddItemPriceHistory: ' . self::TABLE . ' already exists, nothing to do.');

            return;
        }

        $this->forge->addField([
            'price_history_id' => [
                'type'           => 'INT',
                'constraint'     => 10,
                'null'           => false,
                'auto_increment' => true,
            ],
            // items.item_id and nothing else. item_number is what the business prints, but it is
            // not unique and 18 of Casaletto's items do not have one -- a history keyed on it would
            // merge two products' pasts into one story.
            'item_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'null'       => false,
            ],
            // NULL means "no earlier price is known", which is exactly what the seeded starting row
            // says and what a brand new item says. It is never filled with a guess: a history that
            // invents its own past is worse than one that admits a gap.
            'previous_price' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
                'null'       => true,
            ],
            // The same type as items.unit_price. A price that would not fit in the column is not a
            // price this system could ever have held.
            'new_price' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
                'null'       => false,
            ],
            // The explicit default is not decoration. Where explicit_defaults_for_timestamp is off,
            // the first TIMESTAMP column of a table declared with neither a default nor an ON UPDATE
            // clause silently gets "DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP" -- and
            // then touching any row would rewrite when the price changed, which is the one value
            // every question asked of this table rests on. Naming a default suppresses that rule.
            //
            // TIMESTAMP rather than DATETIME so it compares like sales.sale_time, which is what a
            // report joining the two will do.
            'changed_at' => [
                'type'    => 'TIMESTAMP',
                'null'    => false,
                'default' => new RawSql('CURRENT_TIMESTAMP'),
            ],
            // people.person_id. Nullable because there are writes with genuinely nobody behind them
            // -- a CSV import run from `php spark` has no session -- and recording a real person for
            // those would be a lie. An honest NULL beats an invented author.
            'employee_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'null'       => true,
            ],
            // A stable code, never a label: same reasoning as payment_type_code, cash_source and
            // unit_of_measure. Wording is resolved at display time, so switching locale cannot
            // change what the data means. See Item_price_history::ALLOWED_SOURCES.
            'source' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => false,
            ],
            // Only set when source is 'sale'. This is the column that answers "what did this sale
            // change?", which is the question an owner asks when a price surprises them.
            'sale_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'null'       => true,
            ],
            'note' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
                'default'    => '',
            ],
        ]);

        $this->forge->addKey('price_history_id', true);

        // Composite, not two separate keys. Every question about one item is either "this item
        // ordered by time" or "this item, the last row before T", and one composite index serves
        // both; two single-column indexes serve neither well.
        $this->forge->addKey(['item_id', 'changed_at'], false, false, 'idx_item_changed');
        // "What did this sale change?"
        $this->forge->addKey('sale_id', false, false, 'idx_sale_id');
        // "What was repriced yesterday?" -- the supervision report, which is the whole point of
        // choosing a trail over a permission.
        $this->forge->addKey('changed_at', false, false, 'idx_changed_at');

        $this->forge->createTable(self::TABLE, true);

        $this->report('AddItemPriceHistory: created ' . $this->db->prefixTable(self::TABLE) . ' with indexes on (item_id, changed_at), sale_id and changed_at.');
    }

    /**
     * Revert a migration step.
     *
     * Dropping this table throws away the only record of who changed a price and when. That is
     * acceptable for a rollback of a feature that has not shipped; it would not be once the
     * register starts writing here.
     */
    public function down(): void
    {
        $this->forge->dropTable(self::TABLE, true);
    }

    /**
     * Migrations are also run from the web installer, where CLI output has nowhere to go.
     *
     * Reporting goes through CLI::write and not log_message on purpose: the production log
     * threshold is 4, which throws away anything below "critical", and this is progress rather
     * than an error.
     */
    private function report(string $message): void
    {
        if (is_cli()) {
            CLI::write($message);
        }
    }
}
