<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;

/**
 * The discount the cashier gives on a whole presale, and whether the brand pays it back
 * (docs/Funcional/venta-anticipada.md §4.16, owner's request of 2026-10-27).
 *
 *   discount_percent       0 to 40, in steps of 5. Applied to every line of the presale as the line's
 *                          own percentage discount (presale_items.discount), on top of the campaign
 *                          price: the register reads that same column at delivery, so the delivery
 *                          charges exactly the discounted total.
 *   discount_reimbursable  1 when the brand pays the discount back to the business. Only meaningful
 *                          with a discount; the brand report adds these up.
 *   gross_total            what the presale would cost without that discount, worked out by the
 *                          register's own rule. gross_total - total is the discount in money, taxes
 *                          included: what the customer did not pay.
 *
 * Presales registered before this migration have no discount: gross_total is filled with their total.
 */
class Migration_AddPresaleDiscount extends Migration
{
    public const ADDED_COLUMNS = ['discount_percent', 'discount_reimbursable', 'gross_total'];

    public function up(): void
    {
        $this->db->resetDataCache();

        $fields = [];

        if (! $this->db->fieldExists('discount_percent', 'presales')) {
            $fields['discount_percent'] = ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => false, 'default' => 0];
        }

        if (! $this->db->fieldExists('discount_reimbursable', 'presales')) {
            $fields['discount_reimbursable'] = ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0];
        }

        if (! $this->db->fieldExists('gross_total', 'presales')) {
            $fields['gross_total'] = ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true];
        }

        if ($fields !== []) {
            $this->forge->addColumn('presales', $fields);
        }

        $this->db->table('presales')->set('gross_total', 'total', false)->where('gross_total', null)->update();

        if (is_cli()) {
            CLI::write('AddPresaleDiscount: ' . count($fields) . ' column(s) added to ' . $this->db->prefixTable('presales') . '.');
        }
    }

    public function down(): void
    {
        foreach (self::ADDED_COLUMNS as $column) {
            if ($this->db->fieldExists($column, 'presales')) {
                $this->forge->dropColumn('presales', $column);
            }
        }
    }
}
