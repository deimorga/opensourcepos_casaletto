<?php

declare(strict_types=1);

namespace Tests\Views;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * D25: the order-ticket screen is the register's design, made responsive -- never a look of its own.
 *
 * The first version (2026-09-23) had its own Bootstrap 5 layout: another font, another palette, a
 * plain search that reloaded the page. The owner rejected it for breaking the visual line of the
 * system. What is pinned here is what a later "small fix" would quietly undo:
 *
 * - the screen uses the shared POS header and footer and the register's layout blocks;
 * - it cannot charge;
 * - the header options it needs are OFF by default, so no other screen changes;
 * - every rule of its stylesheet is scoped to #ot_screen, so it cannot reach the register.
 *
 * Reads the files as source: rendering needs the settings and a logged-in employee. The rendered
 * page is covered by OrderTicketsControllerTest::testTheScreenIsTheRegistersDesignMadeResponsive().
 *
 * @internal
 */
final class OrderTicketsScreenDesignTest extends CIUnitTestCase
{
    private string $screen;
    private string $header;
    private string $css;

    protected function setUp(): void
    {
        parent::setUp();

        $this->screen = (string) file_get_contents(APPPATH . 'Views/order_tickets/screen.php');
        $this->header = (string) file_get_contents(APPPATH . 'Views/partial/header.php');
        $this->css    = (string) file_get_contents(FCPATH . 'css/order_tickets.css');
    }

    public function testTheScreenUsesTheSharedHeaderAndTheRegistersBlocks(): void
    {
        $this->assertStringContainsString("view('partial/header')", $this->screen);
        $this->assertStringContainsString("view('partial/footer')", $this->screen);
        $this->assertStringNotContainsString('bootswatch5', $this->screen);

        foreach (['register_wrapper', 'open_tabs_bar', 'add_item_form', 'register', 'overall_sale', 'sale_totals'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $this->screen, '#' . $id . ' is the register\'s; register.css lays it out.');
        }
    }

    public function testTheScreenHasTheRegistersLiveSearch(): void
    {
        $this->assertStringContainsString("autocomplete({", $this->screen);
        $this->assertStringContainsString("base_url('comandas/buscar')", $this->screen);
    }

    public function testTheScreenCannotCharge(): void
    {
        foreach (['addPayment', 'sales/complete', 'finish_sale_button', 'payment_types'] as $charging) {
            $this->assertStringNotContainsString($charging, $this->screen);
        }
    }

    /**
     * The viewport is emitted only when a screen asks for it. Unconditionally, it would change how
     * every screen of the system renders on a tablet at once.
     */
    public function testTheHeaderOptionsAreOffByDefault(): void
    {
        $this->assertMatchesRegularExpression('/if \(!empty\(\$responsive\)\)[^<]*<meta name="viewport"/s', $this->header);
        $this->assertStringContainsString("\$logout_route ?? 'home/logout'", $this->header);
        $this->assertStringContainsString('$profile_link ?? true', $this->header);
        $this->assertStringContainsString('$extra_stylesheets ?? []', $this->header);
    }

    /**
     * Every selector of every rule starts with #ot_screen. One unscoped rule would restyle the
     * register, which loads the same bundle.
     */
    public function testEveryStylesheetRuleIsScopedToTheScreen(): void
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $this->css);
        preg_match_all('/([^{}]+)\{[^{}]*\}/', (string) preg_replace('/@media[^{]+\{/', '', (string) $css), $rules);

        $this->assertNotEmpty($rules[1]);

        foreach ($rules[1] as $selectors) {
            foreach (explode(',', $selectors) as $selector) {
                $this->assertStringStartsWith('#ot_screen', trim($selector), 'Unscoped selector: ' . trim($selector));
            }
        }
    }

    /**
     * What only fails on a real phone: the breakpoint that stacks the panels, 16px inputs (or iOS
     * zooms on every focus), and thumb-size targets.
     */
    public function testThePhoneRulesExist(): void
    {
        $this->assertStringContainsString('@media (max-width: 767px)', $this->css);
        $this->assertStringContainsString('font-size: 16px', $this->css);
        $this->assertStringContainsString('min-height: 44px', $this->css);
    }
}
