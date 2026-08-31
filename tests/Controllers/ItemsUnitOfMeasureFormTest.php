<?php

namespace Tests\Controllers;

use App\Models\Item;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The selector must never become mandatory.
 *
 * A tenant is already selling every day with this code and has no use for the field. The moment
 * the selector starts refusing a save, saving an item breaks for a business that never asked for
 * weight. That rule lives in a template rather than in a function, so this reads the template --
 * there is no cheaper place to catch a 'required' someone adds without thinking about it.
 *
 * No database on purpose: the constraint is in the markup, not in the schema.
 */
class ItemsUnitOfMeasureFormTest extends CIUnitTestCase
{
    private string $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->template = file_get_contents(__DIR__ . '/../../app/Views/items/form.php');
    }

    public function testTheFormOffersTheSelector(): void
    {
        $this->assertStringContainsString(
            "form_dropdown('unit_of_measure'",
            $this->template,
            'The item form no longer offers a unit of measure selector.'
        );
    }

    /**
     * form_label() marks a field mandatory by carrying the 'required' class, which is also what
     * paints the asterisk. The block for this field must not have it.
     */
    public function testTheSelectorIsNotMarkedRequiredInTheMarkup(): void
    {
        $label = $this->extractLabelCall('unit_of_measure');

        $this->assertStringNotContainsString(
            'required',
            $label,
            "The unit of measure label is marked required. An item saved without it has to keep working."
        );
    }

    /**
     * The jQuery validate config is a whitelist: a field absent from 'rules' is never validated.
     * Being absent is the whole point, so absence is what gets asserted.
     */
    public function testTheSelectorHasNoClientSideValidationRule(): void
    {
        $rules = $this->extractBetween($this->template, 'rules: {', 'messages: {');

        $this->assertStringNotContainsString(
            'unit_of_measure',
            $rules,
            'A client-side rule would let the browser refuse a save over a field that is optional by design.'
        );
    }

    /**
     * After a save with the dialog kept open the form strips every 'selected' attribute, so the
     * browser falls back to whichever option the template printed first. That has to be the code
     * which preserves today's behaviour, or the next item typed in that dialog silently becomes a
     * weighed one.
     */
    public function testUnitIsTheFirstAndThereforeTheFallbackOption(): void
    {
        $this->assertSame(
            Item::UNIT_OF_MEASURE_UNIT,
            array_key_first(Item::units_of_measure_options()),
            'The safe code must be printed first so a de-selected dropdown falls back to it.'
        );
    }

    /**
     * The template renders the model's list rather than the one the controller assembles. Reading
     * the markup for that is the cheap way to catch a revert to a hand-written array in the view --
     * which is where a newly allowed code silently fails to appear.
     */
    public function testTheSelectorIsBuiltFromTheModelsList(): void
    {
        $this->assertStringContainsString(
            'Item::units_of_measure_options()',
            $this->template,
            'The selector must offer every code the column accepts, not a list kept in parallel.'
        );
    }

    /**
     * The selector offers a unit and a kilogram, and nothing else.
     *
     * A pound was briefly on offer here and taking it away was the point of a later change, so this
     * asserts the absence rather than trusting the list above to notice. Nothing converts between
     * weighed units, so an item put on the wrong one is charged 2.2 times the wrong price in
     * silence -- the reason the option is gone is exactly the reason nobody should be able to pick
     * it back up from this screen.
     */
    public function testTheSelectorOffersOnlyTheUnitAndTheKilogram(): void
    {
        $options = Item::units_of_measure_options();

        $this->assertSame(
            [Item::UNIT_OF_MEASURE_UNIT, Item::UNIT_OF_MEASURE_KG],
            array_keys($options)
        );
        $this->assertArrayNotHasKey('lb', $options, 'The pound must not be offered again.');
    }

    private function extractLabelCall(string $field): string
    {
        $needle = "form_label(lang('Items.$field')";
        $start = strpos($this->template, $needle);

        $this->assertNotFalse($start, "No label found for $field in the item form.");

        return substr($this->template, $start, strpos($this->template, "\n", $start) - $start);
    }

    private function extractBetween(string $haystack, string $from, string $to): string
    {
        $start = strpos($haystack, $from);
        $end = strpos($haystack, $to, $start);

        $this->assertNotFalse($start, "Marker '$from' not found.");
        $this->assertNotFalse($end, "Marker '$to' not found.");

        return substr($haystack, $start, $end - $start);
    }
}
