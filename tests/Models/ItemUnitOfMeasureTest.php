<?php

namespace Tests\Models;

use CodeIgniter\Test\CIUnitTestCase;
use App\Models\Item;

/**
 * The gate every write path to items.unit_of_measure goes through.
 *
 * These run without a database on purpose. save_value() writes with the raw query builder and
 * never consults $allowedFields, so the normaliser -- not the model's field list -- is what
 * actually keeps a junk value out of the column. That makes it the rule worth proving on its own,
 * rather than only through a controller that needs a live schema to boot.
 */
class ItemUnitOfMeasureTest extends CIUnitTestCase
{
    public function testTheTwoCodesAreTheOnlyOnesAllowed(): void
    {
        $this->assertSame(['unit', 'kg'], Item::ALLOWED_UNITS_OF_MEASURE);
    }

    public function testCodesAreAccepted(): void
    {
        $this->assertSame('unit', Item::normalize_unit_of_measure('unit'));
        $this->assertSame('kg', Item::normalize_unit_of_measure('kg'));
    }

    /**
     * The field is never mandatory. An item form submitted without touching the selector, a CSV
     * written from a template that predates the column, a bulk edit that leaves it alone -- all of
     * them arrive here as nothing, and all of them have to keep working.
     */
    public function testAbsentInputFallsBackToUnit(): void
    {
        $this->assertSame('unit', Item::normalize_unit_of_measure(null));
        $this->assertSame('unit', Item::normalize_unit_of_measure(''));
        $this->assertSame('unit', Item::normalize_unit_of_measure('   '));
    }

    /**
     * getPost() and a CSV cell can both hand over something that is not a string at all.
     */
    public function testNonStringInputFallsBackToUnit(): void
    {
        $this->assertSame('unit', Item::normalize_unit_of_measure(['kg']));
        $this->assertSame('unit', Item::normalize_unit_of_measure(0));
        $this->assertSame('unit', Item::normalize_unit_of_measure(true));
    }

    /**
     * A cashier typing into a spreadsheet does not think in codes.
     */
    public function testInputIsTrimmedAndLowercased(): void
    {
        $this->assertSame('kg', Item::normalize_unit_of_measure(' KG '));
        $this->assertSame('kg', Item::normalize_unit_of_measure('Kg'));
        $this->assertSame('unit', Item::normalize_unit_of_measure('Unit'));
    }

    /**
     * The column is VARCHAR(10) and nothing downstream validates it again, so anything unknown has
     * to collapse to the safe code here -- including a value long enough to be truncated by the
     * database, and including the label that a Spanish-speaking user would reasonably guess.
     */
    public function testUnrecognisedInputFallsBackToUnit(): void
    {
        $this->assertSame('unit', Item::normalize_unit_of_measure('kilogramo'));
        $this->assertSame('unit', Item::normalize_unit_of_measure('lb'));
        $this->assertSame('unit', Item::normalize_unit_of_measure('gramo'));
        $this->assertSame('unit', Item::normalize_unit_of_measure(str_repeat('k', 50)));
        $this->assertSame('unit', Item::normalize_unit_of_measure("kg'; DROP TABLE ospos_items; --"));
    }

    /**
     * Every code the normaliser can emit has to be a code the column accepts, or a write path can
     * still produce a row nothing else understands.
     */
    public function testTheNormaliserCanOnlyEverEmitAllowedCodes(): void
    {
        $inputs = ['unit', 'kg', ' KG ', null, '', 'kilogramo', ['kg'], 0, 'unidad'];

        foreach ($inputs as $input) {
            $this->assertContains(
                Item::normalize_unit_of_measure($input),
                Item::ALLOWED_UNITS_OF_MEASURE,
                'The normaliser emitted a code the column does not accept.'
            );
        }
    }

    public function testUnitOfMeasureIsWritableThroughTheModel(): void
    {
        $item = new Item();

        $this->assertContains(
            'unit_of_measure',
            $this->getPrivateProperty($item, 'allowedFields'),
            'Without this the CodeIgniter model layer silently drops the field on save.'
        );
    }

    /**
     * Bulk edit builds its update straight from this constant.
     */
    public function testUnitOfMeasureCanBeBulkEdited(): void
    {
        $this->assertContains('unit_of_measure', Item::ALLOWED_BULK_EDIT_FIELDS);
    }
}
