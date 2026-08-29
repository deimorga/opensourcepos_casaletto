<?php

namespace Tests\Views;

use App\Libraries\Sale_lib;
use App\Models\Item_quantity;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Guards the handful of decisions in app/Views/sales/register.php that the
 * "this cannot touch the shop already selling with it" argument rests on.
 *
 * This reads the view as source rather than rendering it, and that is a
 * deliberate trade: rendering the register pulls in partial/header, the
 * employee model and the menu, so it only runs with a database -- and it is
 * covered there, by SalesWeightEntryTest, which asserts the field appears and
 * disappears against a real request. What is checked here is the set of
 * choices that a later tidy-up would quietly undo: a `??` that looks
 * redundant, a `type="number"` that looks more correct than it is, a display
 * helper that looks interchangeable with the general one. Each of those has a
 * cost that only shows up in production, on somebody else's shift.
 */
class RegisterWeightFieldTest extends CIUnitTestCase
{
    private string $view;

    protected function setUp(): void
    {
        parent::setUp();

        $this->view = file_get_contents(APPPATH . 'Views/sales/register.php');
    }

    /**
     * The isolation mechanism itself. No configuration decides who sees the
     * weight field; the presence of an item waiting to be weighed does, and a
     * shop that sells nothing by the kilo never has one.
     */
    public function testTheWeightFieldIsRenderedOnlyWhenAnItemIsWaitingForAWeight(): void
    {
        $this->assertStringContainsString('if (!empty($weight_entry))', $this->view);
        $this->assertStringContainsString("'id'           => 'weight'", $this->view);

        $guard = strpos($this->view, 'if (!empty($weight_entry))');
        $field = strpos($this->view, "'id'           => 'weight'");

        $this->assertNotFalse($guard);
        $this->assertNotFalse($field);
        $this->assertLessThan($field, $guard, 'The weight field must sit inside the guard, not beside it.');
    }

    /**
     * _reload() always sets it, but the view is also the thing a half-applied
     * deploy renders with a stale controller.
     */
    public function testTheViewSurvivesAWeightEntryThatWasNeverPassedIn(): void
    {
        $this->assertStringContainsString('$weight_entry = $weight_entry ?? [];', $this->view);
    }

    /**
     * type="number" looks like the correct input type and is a trap: in a
     * comma locale the browser calls "0,735" invalid, hands back an empty
     * string, and the weight disappears with nothing said.
     */
    public function testTheWeightFieldIsATextInput(): void
    {
        $this->assertStringContainsString("'type'         => 'text'", $this->view);
        $this->assertStringNotContainsString("'type' => 'number'", $this->view);
        $this->assertStringContainsString("'inputmode'    => 'decimal'", $this->view);
    }

    /**
     * The cart lives in the session, so on deploy day it holds lines written
     * before the key existed. Every read has to default.
     */
    public function testTheCartNeverReadsTheUnitOfMeasureKeyDirectly(): void
    {
        $this->assertStringNotContainsString("\$item['unit_of_measure']", $this->view);
        $this->assertStringContainsString('Sale_lib::line_sells_by_weight($item)', $this->view);
    }

    /**
     * The quantity input is what the next edit of the line posts back, so a
     * weight shown with fewer decimals than it has is a weight about to be
     * overwritten. quantity_scale() is the floor the arithmetic uses, so the
     * display cannot drift below it.
     */
    public function testAWeightIsDisplayedWithTheSameFloorTheArithmeticUses(): void
    {
        $this->assertStringContainsString('Item_quantity::quantity_scale()', $this->view);
        $this->assertTrue(method_exists(Item_quantity::class, 'quantity_scale'));
    }

    /**
     * The scale in keyboard mode types wherever the cursor is, so the cursor
     * has to be in the weight field -- and back in the scan field the moment
     * nothing is waiting, which is how the register behaves today.
     */
    public function testTheCursorGoesToTheWeightFieldOnlyWhileOneIsWaiting(): void
    {
        $this->assertMatchesRegularExpression(
            '/if \(\$weight_field\.length\) \{\s*\$weight_field\.focus\(\);\s*\} else \{\s*\$\(\'#item\'\)\.focus\(\);/',
            $this->view,
            'With nothing to weigh the scan field must still take the focus, exactly as it does today.'
        );
    }

    /**
     * The on-screen keypad is the contingency for the day the scale or the
     * keyboard fails, so it has to be able to type a decimal and to correct a
     * mistake -- a pad of digits alone cannot enter 0,735.
     */
    public function testTheKeypadCanTypeADecimalAndCorrectAMistake(): void
    {
        $this->assertStringContainsString('data-weight-key="separator"', $this->view);
        $this->assertStringContainsString('data-weight-key="backspace"', $this->view);
        $this->assertStringContainsString('data-weight-key="0"', $this->view);

        // 1 through 9 are emitted from this list rather than written out, so
        // the list is what has to be complete.
        $this->assertStringContainsString(
            "\$weight_keypad_rows = [['7', '8', '9'], ['4', '5', '6'], ['1', '2', '3']];",
            $this->view
        );
    }

    /**
     * Everything the view calls on the library has to exist; a rename that
     * compiles is still a blank register.
     */
    public function testTheHelpersTheViewLeansOnExist(): void
    {
        $this->assertTrue(method_exists(Sale_lib::class, 'line_sells_by_weight'));
        $this->assertTrue(method_exists(Sale_lib::class, 'translate_or'));
    }

    /**
     * header.php loads every stylesheet and script through gulp-inject blocks.
     * A change that breaks one of them leaves a page with no CSS and no JS
     * behind an HTTP 200 that no smoke test notices, which is why the weight
     * field brings its styles inline instead.
     */
    public function testTheWeightFieldDidNotTouchTheAssetPipeline(): void
    {
        $header = file_get_contents(APPPATH . 'Views/partial/header.php');

        $this->assertSame(2, substr_count($header, '<!-- inject:debug:css -->') + substr_count($header, '<!--inject:prod:css -->'));
        $this->assertStringNotContainsString('inject:', $this->view, 'The register view has no inject blocks of its own and must not grow one.');
    }
}
