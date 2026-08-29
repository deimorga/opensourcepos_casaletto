<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;

/**
 * Fills in unit_of_measure for items that already say how they are sold, in words.
 *
 * 20260901000000_AddItemUnitOfMeasure landed the column with DEFAULT 'unit', which was the right
 * call: a migration must not guess. But on the tenant that has been trading for two months the
 * answer was not a guess at all -- it was sitting in the description field the whole time. The
 * Siigo import wrote the unit of measure there as free text, because the schema had nowhere else
 * to put it:
 *
 *     Unidad: unidad                                98 items
 *     Unidad: kilogramo                             77 items
 *     Unidad: número de unidades internacionales    50 items
 *     Unidad: botella / litro / libra / kg neto     10 items
 *
 * Leaving those 77 on 'unit' would mean hand-editing them one by one, and the register would keep
 * refusing to ask for a weight on products that are demonstrably sold by weight -- 249 sale lines
 * and ~$4.000.000 of them (see docs/Tecnico/venta-por-peso-y-hardware-de-caja.md section 2b).
 *
 * ONLY the two spellings that unambiguously mean kilograms are converted:
 *
 *   - "Unidad: kilogramo"       -> kg
 *   - "Unidad: kilogramo neto"  -> kg
 *
 * Everything else is deliberately left alone, including "Unidad: libra". A pound IS a weight, but
 * this column only distinguishes 'unit' from 'kg', and the till reads the number in the weight
 * field as kilograms. Marking a pound-priced item as 'kg' would not be a label problem, it would be
 * a pricing one. There is exactly one such item; the business decides what to do with it.
 * "botella" and "litro" are volume, not weight, and "número de unidades internacionales" is a
 * Siigo default that means nothing here.
 *
 * A tenant without these descriptions -- any new business -- gets a clean no-op.
 */
class Migration_BackfillUnitOfMeasureFromDescription extends Migration
{
    private const TABLE = 'items';
    private const COLUMN = 'unit_of_measure';
    private const UNIT = 'unit';
    private const KG = 'kg';

    /**
     * Descriptions that unambiguously mean "sold by the kilogram".
     */
    private const KG_DESCRIPTIONS = [
        'Unidad: kilogramo',
        'Unidad: kilogramo neto',
    ];

    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        // resetDataCache() FIRST, and it is not optional. fieldExists() answers from a schema list
        // the connection cached the first time it looked at the table -- which, in a migration run,
        // is before the earlier migration in the same process added this column. Without this the
        // guard reports "no such column" on the very deploy that just created it, and the backfill
        // silently does nothing. That is exactly what happened on the first production run.
        $this->db->resetDataCache();

        if (!$this->db->fieldExists(self::COLUMN, self::TABLE)) {
            CLI::write('BackfillUnitOfMeasureFromDescription: items.' . self::COLUMN . ' does not exist, nothing to do.');

            return;
        }

        // Written as a bound query rather than the builder on purpose: matching on TRIM(description)
        // needs the field left unescaped, and the builder's whereIn() applies that same flag to the
        // values, which would drop their quoting. Explicit placeholders keep the function and the
        // escaping straight.
        //
        // Only rows still holding the default are touched. If somebody already set a unit by hand
        // -- on this deploy or a later one -- their answer wins over a string in a description.
        $table = $this->db->prefixTable(self::TABLE);
        $placeholders = implode(', ', array_fill(0, count(self::KG_DESCRIPTIONS), '?'));

        $this->db->query(
            "UPDATE `$table` SET `" . self::COLUMN . '` = ? WHERE TRIM(`description`) IN (' . $placeholders . ') AND `' . self::COLUMN . '` = ?',
            array_merge([self::KG], self::KG_DESCRIPTIONS, [self::UNIT])
        );

        $converted = $this->db->affectedRows();

        CLI::write('BackfillUnitOfMeasureFromDescription: ' . $converted . " item(s) set to '" . self::KG . "' from their description.");

        $this->reportWhatWasLeftAlone();
    }

    /**
     * Revert a migration step.
     *
     * Puts back exactly what up() changed and nothing more: rows that say kilogram in the
     * description AND are currently 'kg'. An item somebody marked 'kg' by hand whose description
     * says something else is not ours to revert.
     */
    public function down(): void
    {
        // resetDataCache() FIRST, and it is not optional. fieldExists() answers from a schema list
        // the connection cached the first time it looked at the table -- which, in a migration run,
        // is before the earlier migration in the same process added this column. Without this the
        // guard reports "no such column" on the very deploy that just created it, and the backfill
        // silently does nothing. That is exactly what happened on the first production run.
        $this->db->resetDataCache();

        if (!$this->db->fieldExists(self::COLUMN, self::TABLE)) {
            return;
        }

        $table = $this->db->prefixTable(self::TABLE);
        $placeholders = implode(', ', array_fill(0, count(self::KG_DESCRIPTIONS), '?'));

        $this->db->query(
            "UPDATE `$table` SET `" . self::COLUMN . '` = ? WHERE TRIM(`description`) IN (' . $placeholders . ') AND `' . self::COLUMN . '` = ?',
            array_merge([self::UNIT], self::KG_DESCRIPTIONS, [self::KG])
        );

        CLI::write('BackfillUnitOfMeasureFromDescription: ' . $this->db->affectedRows() . " item(s) returned to '" . self::UNIT . "'.");
    }

    /**
     * Says out loud which items describe a unit that this column cannot express, so the business
     * can decide rather than discover it at the till.
     */
    private function reportWhatWasLeftAlone(): void
    {
        $table = $this->db->prefixTable(self::TABLE);

        $rows = $this->db->query(
            "SELECT TRIM(`description`) AS description, COUNT(*) AS total FROM `$table`
              WHERE `deleted` = 0 AND TRIM(`description`) IN (?, ?, ?)
              GROUP BY TRIM(`description`)",
            ['Unidad: libra', 'Unidad: litro', 'Unidad: botella']
        )->getResultArray();

        foreach ($rows as $row) {
            CLI::write('  ! left as ' . self::UNIT . ': ' . $row['total'] . ' item(s) described as "' . $row['description'] . '" -- not kilograms, decide by hand.');
        }
    }
}
