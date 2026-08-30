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
    /**
     * Three, not two. Two was a guess and the data disagreed: the business sells QUESO DE CABEZA
     * by the pound, and it is not the only one. 'unit' stays first because a de-selected dropdown
     * falls back to whatever is printed first, and that has to be the code that changes nothing.
     */
    public function testTheThreeCodesAreTheOnlyOnesAllowed(): void
    {
        $this->assertSame(['unit', 'kg', 'lb'], Item::ALLOWED_UNITS_OF_MEASURE);
    }

    public function testCodesAreAccepted(): void
    {
        $this->assertSame('unit', Item::normalize_unit_of_measure('unit'));
        $this->assertSame('kg', Item::normalize_unit_of_measure('kg'));
        $this->assertSame('lb', Item::normalize_unit_of_measure('lb'));
    }

    /**
     * Pounds and kilos are separate units and stay separate: the price of an item is the price of
     * one of ITS unit and the quantity is stored in that unit. Nothing here converts, and nothing
     * anywhere else does either -- a conversion would be a pricing error, not a labelling one.
     */
    public function testAPoundIsNotAKilo(): void
    {
        $this->assertNotSame(
            Item::normalize_unit_of_measure('lb'),
            Item::normalize_unit_of_measure('kg')
        );
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
        $this->assertSame('lb', Item::normalize_unit_of_measure(' LB '));
        $this->assertSame('lb', Item::normalize_unit_of_measure('Lb'));
    }

    /**
     * The column is VARCHAR(10) and nothing downstream validates it again, so anything unknown has
     * to collapse to the safe code here -- including a value long enough to be truncated by the
     * database, and including the label that a Spanish-speaking user would reasonably guess.
     */
    public function testUnrecognisedInputFallsBackToUnit(): void
    {
        $this->assertSame('unit', Item::normalize_unit_of_measure('kilogramo'));
        $this->assertSame('unit', Item::normalize_unit_of_measure('libra'));
        $this->assertSame('unit', Item::normalize_unit_of_measure('lbs'));
        $this->assertSame('unit', Item::normalize_unit_of_measure('pound'));
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
        $inputs = ['unit', 'kg', ' KG ', 'lb', ' LB ', null, '', 'kilogramo', 'libra', ['kg'], 0, 'unidad'];

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

    /**
     * One list of options, next to the codes it labels. A selector built anywhere else is a place
     * where a fourth code can be forgotten -- which is exactly how 'lb' came to be missing from a
     * column that already accepted it in every other sense.
     */
    public function testEveryAllowedCodeIsOfferedInTheSelector(): void
    {
        $this->assertSame(
            Item::ALLOWED_UNITS_OF_MEASURE,
            array_keys(Item::units_of_measure_options()),
            'The selector and the column have to agree on which codes exist, and in what order.'
        );
    }

    public function testEveryOptionCarriesALabelRatherThanItsOwnKey(): void
    {
        foreach (Item::units_of_measure_options() as $code => $label) {
            $this->assertNotSame(
                'Items.unit_of_measure_' . $code,
                $label,
                "No translation for '$code'; the selector would show the language key."
            );
            $this->assertNotSame('', trim($label));
        }
    }

    /**
     * The two locales this fork actually ships to a shop floor. The rest fall back to en, which is
     * CodeIgniter's own behaviour and is fine; Spanish is not a fallback here, it is the language
     * the cashier reads.
     */
    public function testBothShippedLocalesLabelEveryCode(): void
    {
        foreach (['en', 'es-ES'] as $locale) {
            $strings = require APPPATH . 'Language/' . $locale . '/Items.php';

            foreach (Item::ALLOWED_UNITS_OF_MEASURE as $code) {
                $this->assertArrayHasKey(
                    'unit_of_measure_' . $code,
                    $strings,
                    "$locale has no label for the '$code' unit of measure."
                );
            }
        }
    }
}
