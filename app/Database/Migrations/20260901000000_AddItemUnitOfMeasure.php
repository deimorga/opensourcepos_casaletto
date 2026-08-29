<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;

/**
 * Records whether an item is sold by the unit or by weight.
 *
 * Nothing in the schema could tell a kilo of tomatoes from a bottle of oil, which stopped being a
 * cosmetic gap the moment a scale entered the till: this column is what tells the register which
 * item to ask the scale for a weight. See docs/Tecnico/venta-por-peso-y-hardware-de-caja.md
 * sections 2.3 and 3.
 *
 * The value is a stable, language-independent code -- 'unit' or 'kg' -- exactly like
 * payment_type_code and cash_source. Labels are resolved at display time from the Items.* language
 * files, so a tenant that switches locale does not silently change what its data means.
 *
 * NOT NULL DEFAULT 'unit' is the whole safety argument for the tenant already selling with this
 * code. MariaDB fills the column in for every existing row as part of the ALTER, so every article
 * that exists today lands on 'unit', which is precisely the behaviour it has today. There is no
 * window in which an item has no unit, and no read path has to cope with a NULL.
 *
 * Deliberately not indexed. Two possible values over the whole table give an index no selectivity
 * worth the write cost, and this column is read per-item by primary key on the sale path, never
 * used as a grid filter.
 */
class Migration_AddItemUnitOfMeasure extends Migration
{
    private const TABLE = 'items';
    private const COLUMN = 'unit_of_measure';
    private const DEFAULT_UNIT = 'unit';

    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        if ($this->db->fieldExists(self::COLUMN, self::TABLE)) {
            CLI::write('AddItemUnitOfMeasure: items.' . self::COLUMN . ' already exists, nothing to do.');

            return;
        }

        // unit_price is part of the original schema, so it is present on every installation this
        // could ever run against, however old.
        $this->forge->addColumn(self::TABLE, [
            self::COLUMN => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
                'null'       => false,
                'default'    => self::DEFAULT_UNIT,
                'after'      => 'unit_price',
            ],
        ]);

        $total = $this->db->table(self::TABLE)->countAllResults();

        CLI::write('AddItemUnitOfMeasure: ' . $total . " existing item(s) default to '" . self::DEFAULT_UNIT . "', which is what they already did.");
    }

    /**
     * Revert a migration step.
     */
    public function down(): void
    {
        if ($this->db->fieldExists(self::COLUMN, self::TABLE)) {
            $this->forge->dropColumn(self::TABLE, self::COLUMN);
        }
    }
}
