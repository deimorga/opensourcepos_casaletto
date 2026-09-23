<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;

/**
 * Creates the order ticket -- what the business calls a "comanda".
 *
 * WHY THREE TABLES AND NOT A COLUMN ON sales_items
 *
 * This is the whole design, and it is forced by the code rather than chosen:
 *
 *   Sale::save_value() calls clear_suspended_sale_detail(), which DELETEs every sales_items row of
 *   the sale and reinserts them from the session cart. Sales::_autosave_open_tab() fires that on
 *   every add, edit and delete of an item in an open account -- four call sites.
 *
 * So a `sent_to_kitchen` column on sales_items is destroyed by the next dish the cashier adds, and
 * the symptom appears in the kitchen, receiving the same order twice. The order ticket therefore
 * owns its lines, and sales_items is the billing projection of them, not the source.
 *
 * The line identity is order_ticket_line_id and never sales_items.line: the latter is reassigned
 * from scratch by Sale_lib::copy_entire_sale() every time the cashier switches tab, so "I already
 * sent line 3" is a statement that can stop being true.
 *
 * NO FOREIGN KEYS
 *
 * Same reasoning as item_price_history and platform_activity_log. A ticket is a record of what
 * somebody ordered; it has to survive the sale being deleted, and an FK failure inside a write path
 * would turn a record into an outage.
 *
 * DATETIME AND NOT TIMESTAMP
 *
 * Deliberate, and the opposite of what item_price_history chose. Where explicit_defaults_for_timestamp
 * is off, the first TIMESTAMP column declared without a default silently acquires
 * "DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP" -- and then editing a kitchen note would
 * rewrite when the order was taken. DATETIME is not subject to that rule at all, so the trap cannot
 * be reintroduced by a later column reorder. It also does no timezone conversion, and this
 * application resolves its timezone from ospos_app_config rather than from the server.
 *
 * See docs/Tecnico/comandas-y-cuenta-abierta.md sections 3.1, 3.2 and 4.
 */
class Migration_AddOrderTickets extends Migration
{
    public const TABLE_TICKETS = 'order_tickets';
    public const TABLE_LINES   = 'order_ticket_lines';
    public const TABLE_ROUNDS  = 'order_ticket_rounds';

    /**
     * Every column of each table except its auto-increment primary key.
     *
     * Exposed so each model's $allowedFields can be checked against it: CodeIgniter drops any field
     * missing from $allowedFields without raising anything, and this project has already lost data
     * to that twice. There is a test that compares the two.
     */
    public const WRITABLE_COLUMNS_TICKETS = [
        'sale_id',
        'name',
        'status',
        'location_id',
        'note',
        'opened_by',
        'opened_at',
        'delivered_by',
        'delivered_at',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
        'charged_at',
    ];

    public const WRITABLE_COLUMNS_LINES = [
        'order_ticket_id',
        'item_id',
        'item_name',
        'quantity',
        'unit_price',
        'kitchen_note',
        'round_id',
        'status',
        'changed_after_send',
        'captured_by',
        'captured_at',
    ];
    public const WRITABLE_COLUMNS_ROUNDS = [
        'order_ticket_id',
        'number',
        'sent_at',
        'sent_by',
        'printed_at',
    ];

    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        // Before any tableExists(). The driver answers from a schema list built when the process
        // started, so a guard asked before this reset can say "not there" in the very deploy that
        // created the table. That is not hypothetical: it happened in production with the
        // unit-of-measure backfill.
        $this->db->resetDataCache();

        $this->createTickets();
        $this->createLines();
        $this->createRounds();
    }

    /**
     * Revert a migration step.
     *
     * Children first: nothing enforces it without foreign keys, but a reader who adds them later
     * should find the order already correct.
     */
    public function down(): void
    {
        $this->forge->dropTable(self::TABLE_LINES, true);
        $this->forge->dropTable(self::TABLE_ROUNDS, true);
        $this->forge->dropTable(self::TABLE_TICKETS, true);
    }

    /**
     * The account itself: one row per order somebody is taking.
     */
    private function createTickets(): void
    {
        if ($this->db->tableExists(self::TABLE_TICKETS)) {
            $this->report('AddOrderTickets: ' . self::TABLE_TICKETS . ' already exists, nothing to do.');

            return;
        }

        $this->forge->addField([
            'order_ticket_id' => [
                'type'           => 'INT',
                'constraint'     => 10,
                'null'           => false,
                'auto_increment' => true,
            ],
            // The OPENED sale that will bill this ticket. Nullable only for the instant between
            // inserting the ticket and inserting its sale, inside one transaction.
            //
            // The disposable dinner_tables row that makes the ticket show up in the register's tab
            // bar is deliberately NOT stored here: the sale already carries dinner_table_id, and
            // recording the same fact twice leaves room for the two copies to disagree. Same
            // reasoning that kept cashup_id out of cash_collections.
            'sale_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'null'       => true,
            ],
            // The free name of D5 -- "ANDREA", "mesa 4", "domicilio Juan". This column is the truth.
            // dinner_tables.name is varchar(30) and only carries the tab label, truncated the same
            // way Sales::postCreateTable() already truncates it.
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            // open | delivered | cancelled | charged (D14).
            //
            // A stable code and never a label, the same criterion as payment_type_code, cash_source,
            // unit_of_measure and item_price_history.source: the wording lives in the language files
            // and is resolved at display time, so switching locale cannot change what the data
            // means. Text and not a tinyint because sale_status already taught what it costs to have
            // a number whose meaning lives in another file.
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => false,
            ],
            // Sale::get_all_opened() does not filter by location, so today every till of every site
            // of the same business sees the same open accounts. That is a separate fix, but a table
            // born without the column could never be repaired.
            'location_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'null'       => false,
            ],
            // The order-level comment. NOT sales.comment: _autosave_open_tab() is not invoked from
            // postSetComment(), so a comment on an open account is silently not saved.
            'note' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
                'default'    => '',
            ],
            'opened_by' => [
                'type'       => 'INT',
                'constraint' => 10,
                'null'       => false,
            ],
            'opened_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            // Confirming the order reached the table or went out for delivery. This is the "gestionar
            // orden" of D14: without it nobody can say what is still waiting to be served.
            'delivered_by' => [
                'type'       => 'INT',
                'constraint' => 10,
                'null'       => true,
            ],
            'delivered_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'cancelled_by' => [
                'type'       => 'INT',
                'constraint' => 10,
                'null'       => true,
            ],
            'cancelled_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            // Required when cancelling. A ticket that falls through after it was printed has already
            // cost paper and possibly a cooked dish, and the reason is exactly what the operation
            // wants to read afterwards.
            'cancel_reason' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
                'default'    => '',
            ],
            'charged_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('order_ticket_id', true);

        // Composite and not two separate keys: every screen asks "the live tickets of this site",
        // and one composite index serves that where two single-column ones serve neither well.
        $this->forge->addKey(['status', 'location_id'], false, false, 'idx_status_location');
        $this->forge->addKey('sale_id', false, false, 'idx_sale_id');
        // The end-of-day question of D14/P3: which accounts were opened today and never charged.
        $this->forge->addKey('opened_at', false, false, 'idx_opened_at');

        $this->forge->createTable(self::TABLE_TICKETS, true);

        $this->report('AddOrderTickets: created ' . $this->db->prefixTable(self::TABLE_TICKETS) . '.');
    }

    /**
     * What was ordered. This is the source of truth, not sales_items.
     */
    private function createLines(): void
    {
        if ($this->db->tableExists(self::TABLE_LINES)) {
            $this->report('AddOrderTickets: ' . self::TABLE_LINES . ' already exists, nothing to do.');

            return;
        }

        $this->forge->addField([
            // The stable line identity that sales_items.line cannot provide (see the class docblock).
            'order_ticket_line_id' => [
                'type'           => 'INT',
                'constraint'     => 10,
                'null'           => false,
                'auto_increment' => true,
            ],
            'order_ticket_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'null'       => false,
            ],
            'item_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'null'       => false,
            ],
            // Copied at capture time, along with unit_price below. If the item is renamed or
            // repriced tomorrow -- and repricing from the till is a shipped feature, see
            // item_price_history -- the printed ticket and the screen still say what the waiter
            // actually ordered. A document that is reinterpreted every time it is read is not a
            // document.
            'item_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
            ],
            'quantity' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,3',
                'null'       => false,
            ],
            'unit_price' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
                'null'       => false,
            ],
            // D7, and it needs a column of its own for two reasons rather than one:
            // sales_items.description is varchar(30) -- not 255, verified against initial_schema.sql
            // and every migration since -- so a kitchen instruction does not fit even if the field
            // were free; and it is not free, it carries "Unidad: kilogramo" on 90% of lines from the
            // Siigo import and prints on the customer's receipt.
            'kitchen_note' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
                'default'    => '',
            ],
            // NULL means ordered but NOT yet sent to the kitchen. This is half of D8: the second
            // ticket prints only what was added, and "what was added" is exactly the rows where this
            // is still null.
            'round_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'null'       => true,
            ],
            // pending | sent | voided. Voiding is logical: a line that already went to the kitchen
            // is never deleted, because the kitchen already acted on it.
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => false,
            ],
            // D9: something already in the kitchen was touched. It is allowed, and it is announced.
            'changed_after_send' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 0,
            ],
            'captured_by' => [
                'type'       => 'INT',
                'constraint' => 10,
                'null'       => false,
            ],
            'captured_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addKey('order_ticket_line_id', true);
        $this->forge->addKey(['order_ticket_id', 'status'], false, false, 'idx_ticket_status');
        $this->forge->addKey('round_id', false, false, 'idx_round_id');

        $this->forge->createTable(self::TABLE_LINES, true);

        $this->report('AddOrderTickets: created ' . $this->db->prefixTable(self::TABLE_LINES) . '.');
    }

    /**
     * Each send to the kitchen.
     */
    private function createRounds(): void
    {
        if ($this->db->tableExists(self::TABLE_ROUNDS)) {
            $this->report('AddOrderTickets: ' . self::TABLE_ROUNDS . ' already exists, nothing to do.');

            return;
        }

        $this->forge->addField([
            'round_id' => [
                'type'           => 'INT',
                'constraint'     => 10,
                'null'           => false,
                'auto_increment' => true,
            ],
            'order_ticket_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'null'       => false,
            ],
            // 1, 2, 3... within the ticket. This is what prints as "RONDA 2".
            'number' => [
                'type'       => 'INT',
                'constraint' => 10,
                'null'       => false,
            ],
            'sent_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'sent_by' => [
                'type'       => 'INT',
                'constraint' => 10,
                'null'       => false,
            ],
            // NULL means sent but not yet printed, and that is a real state rather than an omission:
            // the waiter sends from a phone that cannot reach the till's printer, so the paper comes
            // out when somebody opens the round at the register. Sending and printing are two acts.
            'printed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('round_id', true);
        // UNIQUE: two rounds numbered the same inside one ticket is a defect, and it is worth having
        // the database say so rather than discovering it on a printed sheet.
        $this->forge->addUniqueKey(['order_ticket_id', 'number'], 'idx_ticket_number');

        $this->forge->createTable(self::TABLE_ROUNDS, true);

        $this->report('AddOrderTickets: created ' . $this->db->prefixTable(self::TABLE_ROUNDS) . ' with a unique key on (order_ticket_id, number).');
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
