<?php

namespace App\Database\Migrations;

use App\Models\Item;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;

/**
 * Takes every item off 'lb' and puts it back on a unit that still exists.
 *
 * 'lb' has been removed from App\Models\Item::ALLOWED_UNITS_OF_MEASURE, because the premise behind
 * it was a misreading -- 20260905000000 carries the evidence. Only staging ever ran that migration;
 * production never did. But a row left holding 'lb' would be worse than wrong, it would be
 * invisible: normalize_unit_of_measure() answers 'unit' for any code it does not recognise, so the
 * register would quietly stop asking for a weight on a product that is nothing but weight, and the
 * item form would show "Unidad" while the column said something else.
 *
 * WHERE EACH ROW GOES
 *
 * The same test 20260903000000 uses, and for the same reason -- the description the Siigo import
 * left behind is the only evidence in the database about how a thing is sold:
 *
 *   - described as "Unidad: kilogramo" / "kilogramo neto"  ->  'kg'    (QUESO DE CABEZA)
 *   - anything else                                        ->  'unit'  (CAFÉ MAKOR LIBRA)
 *
 * That second rule is not a shrug. "Unidad: libra" on a bag of coffee names the size of the bag;
 * nobody puts it on a scale. Sending it to 'kg' would make the register demand a weight for a
 * packaged good, which is the more disruptive of the two possible mistakes -- and the one a cashier
 * cannot work around at the till.
 *
 * Only rows currently on 'lb' are touched, so this is idempotent and a tenant that never ran
 * 20260905000000 -- production, and every new business -- gets a clean no-op.
 */
class Migration_RevertPoundUnitOfMeasure extends Migration
{
    private const TABLE = 'items';
    private const COLUMN = 'unit_of_measure';
    private const POUND = 'lb';

    /**
     * Kept here rather than imported from 20260903000000: that migration's list describes what its
     * own run converted and must not change retroactively, while this one describes what a pound
     * row should become today. Same values now, different jobs.
     */
    private const KG_DESCRIPTIONS = [
        'Unidad: kilogramo',
        'Unidad: kilogramo neto',
    ];

    public function up(): void
    {
        // resetDataCache() first: fieldExists() otherwise answers from a schema list cached before
        // 20260901000000 added the column in this same process. That exact trap already cost one
        // silent no-op in production -- see 20260904000000.
        $this->db->resetDataCache();

        if (!$this->db->fieldExists(self::COLUMN, self::TABLE)) {
            CLI::write('RevertPoundUnitOfMeasure: items.' . self::COLUMN . ' does not exist, nothing to do.');

            return;
        }

        $table = $this->db->prefixTable(self::TABLE);
        $placeholders = implode(', ', array_fill(0, count(self::KG_DESCRIPTIONS), '?'));

        // Weighed rows first, then whatever is left. Order matters: the second statement is
        // deliberately unconditional on description, so it must not run while the kilogram rows are
        // still on 'lb'.
        $this->db->query(
            "UPDATE `$table` SET `" . self::COLUMN . '` = ? WHERE `' . self::COLUMN . '` = ? AND TRIM(`description`) IN (' . $placeholders . ')',
            array_merge([Item::UNIT_OF_MEASURE_KG, self::POUND], self::KG_DESCRIPTIONS)
        );
        $to_kg = $this->db->affectedRows();

        $this->db->query(
            "UPDATE `$table` SET `" . self::COLUMN . '` = ? WHERE `' . self::COLUMN . '` = ?',
            [Item::UNIT_OF_MEASURE_UNIT, self::POUND]
        );
        $to_unit = $this->db->affectedRows();

        CLI::write('RevertPoundUnitOfMeasure: ' . $to_kg . " item(s) moved from 'lb' to '"
            . Item::UNIT_OF_MEASURE_KG . "'; " . $to_unit . " item(s) moved from 'lb' to '"
            . Item::UNIT_OF_MEASURE_UNIT . "'.");
    }

    /**
     * Deliberately empty. 'lb' is not a code this application accepts any more, so putting rows back
     * on it would write data the model cannot read -- a rollback that breaks the register is not a
     * rollback. Reverting the decision means restoring the constant first, and then this is not the
     * file that does it.
     */
    public function down(): void
    {
    }
}
