<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;

/**
 * Lets the register know which ordered dishes it has already brought into the sale.
 *
 * WHY THE REGISTER PULLS, AND THE PHONE NEVER WRITES sales_items
 *
 * The obvious design -- the waiter's phone writes each dish into sales_items -- loses money. The
 * register keeps its cart in the session, and every edit of an open tab rewrites sales_items from
 * that cart (Sale::save_value() -> clear_suspended_sale_detail() deletes them all first). A dish the
 * waiter wrote while the cashier had that same tab open would be deleted by the cashier's next
 * keystroke: served, and never charged.
 *
 * So the phone writes only order_ticket_lines, and the register PULLS: while a ticket's tab is the
 * active sale, the lines it has not brought in yet are added to the cart with the register's own
 * add logic (prices, kits, taxes -- the same path as typing the item), the tab is autosaved, and only
 * then are those lines stamped billed_at. If the save fails, nothing is stamped and the next request
 * tries again. The cart is always the merge target; nothing ever overwrites it from outside.
 *
 * billed_at        NULL = not in the sale yet. Set by the register after a successful save.
 * changed_after_billed  The waiter edited or voided a dish the register already has. The cart is
 *                  NOT changed behind the cashier's back -- the cashier may have adjusted it already
 *                  -- so the register shows it instead (D9), and the cashier decides.
 *
 * Kits need no column: every kit has its representative item in `items` (item_kits.item_id,
 * item_type ITEM_KIT), the line stores that item_id, and the register resolves the kit from it the
 * way nested kits already do (Item_kit::get_item_kit_id_for_item_id()).
 *
 * A NEW migration and not an edit of 20260923000000_AddOrderTickets: that one is applied on every
 * staging schema already, and an applied migration is never edited.
 *
 * See docs/Tecnico/comandas-y-cuenta-abierta.md.
 */
class Migration_AddOrderTicketLineBilling extends Migration
{
    private const TABLE = 'order_ticket_lines';

    /**
     * The columns this migration adds to order_ticket_lines. Together with
     * Migration_AddOrderTickets::WRITABLE_COLUMNS_LINES they are every writable column of the table,
     * and Order_ticket_line::$allowedFields is tested against the union.
     */
    public const ADDED_COLUMNS = [
        'billed_at',
        'changed_after_billed',
    ];

    public function up(): void
    {
        $this->db->resetDataCache();

        if (!$this->db->tableExists(self::TABLE)) {
            $this->report('AddOrderTicketLineBilling: ' . self::TABLE . ' does not exist; run AddOrderTickets first.');

            return;
        }

        $fields = [];

        if (!$this->db->fieldExists('billed_at', self::TABLE)) {
            $fields['billed_at'] = [
                'type' => 'DATETIME',
                'null' => true,
            ];
        }

        if (!$this->db->fieldExists('changed_after_billed', self::TABLE)) {
            $fields['changed_after_billed'] = [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 0,
            ];
        }

        if ($fields === []) {
            $this->report('AddOrderTicketLineBilling: columns already present, nothing to do.');

            return;
        }

        $this->forge->addColumn(self::TABLE, $fields);

        $this->report('AddOrderTicketLineBilling: added ' . implode(', ', array_keys($fields)) . ' to ' . $this->db->prefixTable(self::TABLE) . '.');
    }

    public function down(): void
    {
        $this->db->resetDataCache();

        foreach (self::ADDED_COLUMNS as $column) {
            if ($this->db->fieldExists($column, self::TABLE)) {
                $this->forge->dropColumn(self::TABLE, $column);
            }
        }
    }

    /**
     * CLI::write and not log_message: the production log threshold throws away anything below
     * "critical", and this is progress, not an error. Guarded for the web installer.
     */
    private function report(string $message): void
    {
        if (is_cli()) {
            CLI::write($message);
        }
    }
}
