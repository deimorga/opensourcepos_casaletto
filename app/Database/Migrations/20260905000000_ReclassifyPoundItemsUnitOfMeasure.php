<?php

namespace App\Database\Migrations;

use App\Models\Item;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;

/**
 * Puts the pound-priced items on 'lb', including the one that was filed as kilograms.
 *
 * 20260903000000_BackfillUnitOfMeasureFromDescription read the unit of measure back out of the
 * descriptions the Siigo import left behind, and did two things this migration corrects.
 *
 * The first was deliberate and is now obsolete. It left every "Unidad: libra" on 'unit', and said
 * why in its own comment: a pound IS a weight, but the column only knew 'unit' and 'kg', and the
 * till read the weight field as kilograms, so marking a pound-priced item 'kg' would have mispriced
 * it rather than mislabelled it. The column knows 'lb' now, so the reason is gone and the items can
 * say what they are.
 *
 * The second was a mistake, and it reached production. QUESO DE CABEZA was described by the import
 * as "Unidad: kilogramo", so the backfill set it to 'kg' -- but the business sells it by the pound.
 * A weighed line on that item prices the pound figure the cashier types as if it were kilos.
 *
 * WHAT IS AND IS NOT TOUCHED
 *
 * Only rows that still hold what an earlier migration put there:
 *
 *   - "Unidad: libra" AND currently 'unit'  -> 'lb'   (what the backfill deliberately left alone)
 *   - QUESO DE CABEZA AND currently 'kg' AND described as kilograms -> 'lb'  (what it got wrong)
 *
 * The description predicate on the second rule is what keeps this from being a guess: it converts
 * exactly the rows the backfill would have produced, which is the same test that migration's own
 * down() uses to decide what is its to undo. Anything a person has since set by hand -- including
 * a QUESO DE CABEZA somebody deliberately put back on 'unit' -- fails one of those predicates and
 * is left alone. Re-running changes nothing, because a row already on 'lb' matches neither.
 *
 * QUESO DE CABEZA is matched BY NAME, never by id: the ids differ between local, staging and
 * production, and an id written into a migration is a migration that corrupts one environment to
 * fix another. The name match is a LIKE because the import's naming is not fully known, and it is
 * safe to be that loose here precisely because it is fenced in by the two other predicates -- it
 * can only ever reach rows the backfill itself marked as kilograms.
 *
 * A tenant with none of these descriptions -- any new business -- gets a clean no-op.
 *
 * NO CONVERSION HAPPENS HERE, and none happens anywhere else. Only the unit code changes. The
 * price stays the price of one pound and the quantity stays a number of pounds; nothing multiplies
 * by 0.4536. See App\Models\Item::ALLOWED_UNITS_OF_MEASURE.
 */
class Migration_ReclassifyPoundItemsUnitOfMeasure extends Migration
{
    private const TABLE = 'items';
    private const COLUMN = 'unit_of_measure';

    /**
     * Descriptions that unambiguously mean "sold by the pound". Exactly the spelling the backfill
     * reported as left alone -- no invented variants: a spelling nobody has seen matches nothing,
     * and pretending otherwise only makes this harder to read.
     */
    private const LB_DESCRIPTIONS = ['Unidad: libra'];

    /**
     * The descriptions 20260903000000 converted to 'kg'. Kept in step with that migration on
     * purpose: this is the definition of "a row that migration produced".
     */
    private const KG_DESCRIPTIONS = ['Unidad: kilogramo', 'Unidad: kilogramo neto'];

    /**
     * Sold by the pound, described by the import as kilograms. Matched with LIKE '%...%'.
     */
    private const MISFILED_BY_NAME = ['QUESO DE CABEZA'];

    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        if (!$this->columnIsThere('ReclassifyPoundItemsUnitOfMeasure')) {
            return;
        }

        $fromDescription = $this->convertByDescription(
            self::LB_DESCRIPTIONS,
            Item::UNIT_OF_MEASURE_UNIT,
            Item::UNIT_OF_MEASURE_LB
        );

        CLI::write("ReclassifyPoundItemsUnitOfMeasure: $fromDescription item(s) described as a pound set to '"
            . Item::UNIT_OF_MEASURE_LB . "'.");

        $this->reportMisfiledBeforeConverting();

        $misfiled = $this->convertMisfiled(Item::UNIT_OF_MEASURE_KG, Item::UNIT_OF_MEASURE_LB);

        CLI::write("ReclassifyPoundItemsUnitOfMeasure: $misfiled item(s) moved from '" . Item::UNIT_OF_MEASURE_KG
            . "' to '" . Item::UNIT_OF_MEASURE_LB . "' because the business sells them by the pound.");
    }

    /**
     * Revert a migration step.
     *
     * Puts back exactly what up() changed and nothing more: rows that are on 'lb' AND still carry
     * the description that got them there. An item somebody marked 'lb' by hand whose description
     * says something else is not ours to revert.
     */
    public function down(): void
    {
        if (!$this->columnIsThere('ReclassifyPoundItemsUnitOfMeasure (down)')) {
            return;
        }

        $misfiled = $this->convertMisfiled(Item::UNIT_OF_MEASURE_LB, Item::UNIT_OF_MEASURE_KG);
        $fromDescription = $this->convertByDescription(
            self::LB_DESCRIPTIONS,
            Item::UNIT_OF_MEASURE_LB,
            Item::UNIT_OF_MEASURE_UNIT
        );

        CLI::write("ReclassifyPoundItemsUnitOfMeasure: $misfiled item(s) returned to '" . Item::UNIT_OF_MEASURE_KG
            . "' and $fromDescription to '" . Item::UNIT_OF_MEASURE_UNIT . "'.");
    }

    /**
     * resetDataCache() FIRST, and it is not optional. fieldExists() answers from a schema list the
     * connection cached the first time it looked at the table -- which, in a migration run, is
     * before 20260901000000 added this column, in the same process, milliseconds earlier. Without
     * this the guard reports "no such column" on the very deploy that just created it and this
     * migration silently does nothing while CodeIgniter records it as applied. That is not a
     * hypothetical: it is what happened to 20260903000000 on its first production run, and is the
     * entire reason 20260904000000 exists.
     */
    private function columnIsThere(string $label): bool
    {
        $this->db->resetDataCache();

        if ($this->db->fieldExists(self::COLUMN, self::TABLE)) {
            return true;
        }

        CLI::write($label . ': items.' . self::COLUMN . ' does not exist, nothing to do.');

        return false;
    }

    /**
     * Written as a bound query rather than through the builder for the same reason 20260903000000
     * is: matching on TRIM(description) needs the field left unescaped, and the builder's whereIn()
     * applies that same flag to the values, which would strip their quoting.
     *
     * @param list<string> $descriptions
     */
    private function convertByDescription(array $descriptions, string $from, string $to): int
    {
        $table = $this->db->prefixTable(self::TABLE);
        $placeholders = implode(', ', array_fill(0, count($descriptions), '?'));

        $this->db->query(
            "UPDATE `$table` SET `" . self::COLUMN . "` = ?"
            . " WHERE TRIM(`description`) IN ($placeholders) AND `" . self::COLUMN . '` = ?',
            array_merge([$to], $descriptions, [$from])
        );

        return $this->db->affectedRows();
    }

    /**
     * The QUESO DE CABEZA rule, in both directions.
     *
     * The description predicate stays the same in up() and down() because it is not "what the unit
     * should be", it is "which rows the backfill created". That is what makes this reversible
     * without gambling on somebody's hand edit.
     */
    private function convertMisfiled(string $from, string $to): int
    {
        $table = $this->db->prefixTable(self::TABLE);
        $descriptionPlaceholders = implode(', ', array_fill(0, count(self::KG_DESCRIPTIONS), '?'));
        $nameClauses = implode(' OR ', array_fill(0, count(self::MISFILED_BY_NAME), '`name` LIKE ?'));
        $nameValues = array_map(static fn(string $name): string => '%' . $name . '%', self::MISFILED_BY_NAME);

        $this->db->query(
            "UPDATE `$table` SET `" . self::COLUMN . "` = ?"
            . " WHERE ($nameClauses)"
            . " AND TRIM(`description`) IN ($descriptionPlaceholders)"
            . ' AND `' . self::COLUMN . '` = ?',
            array_merge([$to], $nameValues, self::KG_DESCRIPTIONS, [$from])
        );

        return $this->db->affectedRows();
    }

    /**
     * Says out loud, by name, which items are about to be repriced by this change.
     *
     * A unit of measure is money here, and this one is being changed on somebody's behalf from a
     * rule written into a file. Whoever runs the deploy should be able to read back the list and
     * recognise the products -- or notice that it caught something it should not have.
     */
    private function reportMisfiledBeforeConverting(): void
    {
        $table = $this->db->prefixTable(self::TABLE);
        $descriptionPlaceholders = implode(', ', array_fill(0, count(self::KG_DESCRIPTIONS), '?'));
        $nameClauses = implode(' OR ', array_fill(0, count(self::MISFILED_BY_NAME), '`name` LIKE ?'));
        $nameValues = array_map(static fn(string $name): string => '%' . $name . '%', self::MISFILED_BY_NAME);

        $rows = $this->db->query(
            "SELECT `item_number`, `name` FROM `$table`"
            . " WHERE ($nameClauses)"
            . " AND TRIM(`description`) IN ($descriptionPlaceholders)"
            . ' AND `' . self::COLUMN . '` = ?',
            array_merge($nameValues, self::KG_DESCRIPTIONS, [Item::UNIT_OF_MEASURE_KG])
        )->getResultArray();

        foreach ($rows as $row) {
            CLI::write('  -> ' . $row['item_number'] . ' ' . $row['name']
                . ': the import called it a kilogram; the business sells it by the pound.');
        }
    }
}
