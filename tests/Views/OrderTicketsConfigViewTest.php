<?php

namespace Tests\Views;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The order tickets tab of the configuration screen, rendered with no database behind it.
 *
 * Same reason ScaleConfigViewTest exists: the configuration screens are one page shared by every
 * tenant, and the tenant that matters is the one whose settings cache predates the migration that
 * seeds order_tickets_*. A direct $config['order_tickets_enable'] here would not break this tab --
 * it would take that tenant's whole configuration page down with it.
 *
 * @internal
 */
final class OrderTicketsConfigViewTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper(['form', 'url']);
    }

    public function testRendersForATenantWithNoOrderTicketsSettingsAtAll(): void
    {
        $html = view('configs/order_tickets_config', ['config' => []]);

        $this->assertStringContainsString('order_tickets_config_form', $html);
        $this->assertStringContainsString('name="order_tickets_enable"', $html);
        $this->assertStringContainsString('name="order_tickets_kitchen_enable"', $html);
    }

    public function testBothSwitchesAreOffWhenNothingIsConfigured(): void
    {
        $html = view('configs/order_tickets_config', ['config' => []]);

        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="order_tickets_enable"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="order_tickets_kitchen_enable"[^>]*checked/', $html);
    }

    public function testChecksTheSwitchThatIsOnAndOnlyThatOne(): void
    {
        $html = view('configs/order_tickets_config', [
            'config' => [
                'order_tickets_enable'         => '1',
                'order_tickets_kitchen_enable' => '0',
                'dinner_table_enable'          => '1',
            ],
        ]);

        $this->assertMatchesRegularExpression('/<input[^>]*name="order_tickets_enable"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="order_tickets_kitchen_enable"[^>]*checked/', $html);
    }

    public function testChecksTheKitchenSwitchWhenItIsOn(): void
    {
        $html = view('configs/order_tickets_config', [
            'config' => [
                'order_tickets_enable'         => '1',
                'order_tickets_kitchen_enable' => '1',
                'dinner_table_enable'          => '1',
            ],
        ]);

        $this->assertMatchesRegularExpression('/<input[^>]*name="order_tickets_kitchen_enable"[^>]*checked/', $html);
    }

    public function testAlwaysSaysThatOrderTicketsNeedTables(): void
    {
        // Shown permanently, not only after a refused save: the dependency is a fact about the
        // module, and finding it out by failing is the worse way to learn it.
        foreach ([[], ['dinner_table_enable' => '1']] as $config) {
            $html = view('configs/order_tickets_config', ['config' => $config]);

            $this->assertStringContainsString(esc(lang('Config.order_tickets_enable_help')), $html);
        }
    }

    public function testEveryLabelOnTheScreenIsTranslated(): void
    {
        $html = view('configs/order_tickets_config', ['config' => []]);

        // A key with no translation renders as its own name, so this catches all of them at once.
        $this->assertStringNotContainsString('Config.order_tickets', $html);

        foreach ([
            'order_tickets',
            'order_tickets_configuration',
            'order_tickets_enable',
            'order_tickets_kitchen_enable',
            'order_tickets_requires_tables',
            'order_tickets_enable_help',
            'order_tickets_kitchen_help',
        ] as $key) {
            $this->assertNotSame('Config.' . $key, lang('Config.' . $key));
        }
    }

    public function testTheTabSitsRightAfterTheTablesTabInBothBlocks(): void
    {
        $manage = file_get_contents(APPPATH . 'Views/configs/manage.php');

        $this->assertStringContainsString('href="#order_tickets_tab"', $manage);
        $this->assertStringContainsString('id="order_tickets_tab"', $manage);
        $this->assertStringContainsString("view('configs/order_tickets_config')", $manage);

        // The tab strip: Tables, then Order tickets, then whatever came after Tables before.
        $tablesLink  = strpos($manage, 'href="#table_tab"');
        $ticketsLink = strpos($manage, 'href="#order_tickets_tab"');
        $systemLink  = strpos($manage, 'href="#system_tab"');
        $this->assertNotFalse($tablesLink);
        $this->assertGreaterThan($tablesLink, $ticketsLink);
        $this->assertLessThan($systemLink, $ticketsLink);

        // The panes, in the same order.
        $tablesPane  = strpos($manage, 'id="table_tab"');
        $ticketsPane = strpos($manage, 'id="order_tickets_tab"');
        $systemPane  = strpos($manage, 'id="system_tab"');
        $this->assertNotFalse($tablesPane);
        $this->assertGreaterThan($tablesPane, $ticketsPane);
        $this->assertLessThan($systemPane, $ticketsPane);
    }
}
