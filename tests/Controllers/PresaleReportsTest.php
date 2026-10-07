<?php

namespace Tests\Controllers;

use App\Controllers\PresaleReports;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\OSPOS;

/**
 * The committed-quantities screen and its CSV: who reaches them, what they show.
 *
 * Person 1 is given the grant only when it did not hold it, and loses it afterwards: the grants table
 * is shared with every other test file. Own campaign, customer, item and presale (COMMITTED-TEST),
 * removed by id.
 *
 * @internal
 */
final class PresaleReportsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate            = true;
    protected $migrateOnce        = true;
    protected $refresh            = false;
    protected $namespace          = 'App';
    private ?string $switchBefore = null;
    private bool $grantedHere     = false;
    private int $campaign_id      = 0;
    private int $customer_id      = 0;
    private int $item_id          = 0;
    private int $presale_id       = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $row                = $this->db->table('app_config')->where('key', 'presales_enable')->get()->getRow();
        $this->switchBefore = $row === null ? null : (string) $row->value;

        $location    = $this->db->table('stock_locations')->select('location_id')->orderBy('location_id')->limit(1)->get()->getRow();
        $location_id = $location === null ? 1 : (int) $location->location_id;

        $this->db->table('presale_campaigns')->insert([
            'name'             => 'COMMITTED-TEST campana', 'sale_starts' => '2026-11-01', 'sale_ends' => '2026-12-15',
            'discount_percent' => '0', 'min_initial_percent' => '0', 'active' => 1, 'deleted' => 0,
            'created_by'       => 1, 'created_at' => '2026-10-01 00:00:00',
        ]);
        $this->campaign_id = (int) $this->db->insertID();

        $this->db->table('people')->insert([
            'first_name' => 'Ana', 'last_name' => 'COMMITTED-TEST', 'phone_number' => '3000000000', 'email' => '',
            'address_1'  => '', 'address_2' => '', 'city' => '', 'state' => '', 'zip' => '', 'country' => '', 'comments' => '',
        ]);
        $this->customer_id = (int) $this->db->insertID();
        $this->db->table('customers')->insert(['person_id' => $this->customer_id, 'taxable' => 1, 'deleted' => 0, 'employee_id' => 1]);

        // A name that starts like a formula: the CSV must not let a spreadsheet run it.
        $this->db->table('items')->insert([
            'name'               => '=COMMITTED-TEST manzana', 'category' => 'Test', 'item_number' => null, 'description' => '',
            'cost_price'         => '0.00', 'unit_price' => '1.00', 'unit_of_measure' => 'unit', 'reorder_level' => '0',
            'receiving_quantity' => '1', 'allow_alt_description' => 0, 'is_serialized' => 0,
        ]);
        $this->item_id = (int) $this->db->insertID();

        $this->db->table('presales')->insert([
            'campaign_id'   => $this->campaign_id, 'customer_id' => $this->customer_id, 'employee_id' => 1,
            'location_id'   => $location_id, 'created_at' => '2026-11-10 10:00:00', 'delivery_date_id' => 0,
            'delivery_date' => '2026-12-20', 'status' => 'open', 'total' => '0.00',
        ]);
        $this->presale_id = (int) $this->db->insertID();
        $this->db->table('presale_items')->insert([
            'presale_id' => $this->presale_id, 'line' => 1, 'item_id' => $this->item_id, 'quantity' => '3', 'unit_price' => '1.00',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->grantedHere) {
            $this->db->table('grants')->where('permission_id', 'presales')->where('person_id', 1)->delete();
        }

        $this->db->table('presale_items')->where('presale_id', $this->presale_id)->delete();
        $this->db->table('presales')->where('presale_id', $this->presale_id)->delete();
        $this->db->table('items')->where('item_id', $this->item_id)->delete();
        $this->db->table('presale_campaigns')->where('campaign_id', $this->campaign_id)->delete();
        $this->db->table('customers')->where('person_id', $this->customer_id)->delete();
        $this->db->table('people')->where('person_id', $this->customer_id)->delete();

        if ($this->switchBefore === null) {
            $this->db->table('app_config')->where('key', 'presales_enable')->delete();
        } else {
            $this->db->table('app_config')->replace(['key' => 'presales_enable', 'value' => $this->switchBefore]);
        }

        config(OSPOS::class)->update_settings();

        parent::tearDown();
    }

    private function grant(): void
    {
        $exists = $this->db->table('grants')->where('permission_id', 'presales')->where('person_id', 1)->countAllResults() > 0;

        if (! $exists) {
            $this->db->table('grants')->insert(['permission_id' => 'presales', 'person_id' => 1, 'menu_group' => 'both']);
            $this->grantedHere = true;
        }
    }

    private function switchTo(string $value): void
    {
        $this->db->table('app_config')->replace(['key' => 'presales_enable', 'value' => $value]);
        config(OSPOS::class)->update_settings();
    }

    private function getAs(string $uri)
    {
        $session = Services::session();
        $session->destroy();
        $session->set('person_id', 1);
        $session->set('menu_group', 'office');
        $this->withSession(['person_id' => 1, 'menu_group' => 'office']);

        return $this->get($uri);
    }

    public function testWithoutTheModuleGrantTheScreenIsRefused(): void
    {
        if ($this->db->table('grants')->where('permission_id', 'presales')->where('person_id', 1)->countAllResults() > 0) {
            $this->markTestSkipped('Person 1 already holds presales in this database.');
        }

        $this->switchTo('1');

        $response = $this->getAs('/presales/committed?campaign_id=' . $this->campaign_id);

        $response->assertRedirect();
        $this->assertStringContainsString('no_access', (string) $response->getRedirectUrl());
    }

    public function testWithTheModuleOffTheScreenSaysSo(): void
    {
        $this->grant();
        $this->switchTo('0');

        $response = $this->getAs('/presales/committed?campaign_id=' . $this->campaign_id);

        $response->assertStatus(200);
        $this->assertStringContainsString(esc(lang('Presales.disabled')), (string) $response->getBody());
    }

    public function testWithTheModuleOffTheCsvIsNotServed(): void
    {
        $this->grant();
        $this->switchTo('0');

        $response = $this->getAs('/presales/committed/csv?campaign_id=' . $this->campaign_id);

        $response->assertRedirect();
    }

    public function testTheScreenShowsTheCommittedQuantityEscaped(): void
    {
        $this->grant();
        $this->switchTo('1');

        $response = $this->getAs('/presales/committed?campaign_id=' . $this->campaign_id);

        $response->assertStatus(200);
        $body = (string) $response->getBody();
        $this->assertStringContainsString('=COMMITTED-TEST manzana', $body);
        $this->assertStringContainsString(esc(lang('Presale_reports.shortfall')), $body);
        $this->assertStringContainsString(to_date(strtotime('2026-12-20')), $body);
    }

    public function testAnUnknownCampaignShowsNoTable(): void
    {
        $this->grant();
        $this->switchTo('1');

        $response = $this->getAs('/presales/committed?campaign_id=999999999');

        $response->assertStatus(200);
        $this->assertStringContainsString(esc(lang('Presale_reports.pick_campaign')), (string) $response->getBody());
    }

    public function testTheCsvCarriesTheTableAndDefusesFormulas(): void
    {
        $this->grant();
        $this->switchTo('1');

        $response = $this->getAs('/presales/committed/csv?campaign_id=' . $this->campaign_id);

        $response->assertStatus(200);

        ob_start();
        $response->response()->sendBody();
        $csv = (string) ob_get_clean();

        $this->assertStringContainsString(lang('Presale_reports.shortfall'), $csv);
        $this->assertStringContainsString("'=COMMITTED-TEST manzana", $csv);
        $this->assertStringNotContainsString(',=COMMITTED-TEST', $csv);
        $this->assertStringNotContainsString("\n=COMMITTED-TEST", $csv);
    }

    /**
     * With quantity_decimals = 0, three quarters of a kilo used to read "1".
     */
    public function testAWeightQuantityKeepsItsDecimalsWithoutTrailingZeros(): void
    {
        $this->grant();
        $this->switchTo('1');

        $before = [];

        foreach (['quantity_decimals' => '0', 'number_locale' => 'en_US', 'thousands_separator' => '0'] as $key => $value) {
            $row          = $this->db->table('app_config')->where('key', $key)->get()->getRow();
            $before[$key] = $row === null ? null : (string) $row->value;
            $this->db->table('app_config')->replace(['key' => $key, 'value' => $value]);
        }

        config(OSPOS::class)->update_settings();

        try {
            $this->db->table('items')->insert([
                'name'               => 'COMMITTED-TEST pernil', 'category' => 'Test', 'item_number' => null, 'description' => '',
                'cost_price'         => '0.00', 'unit_price' => '1.00', 'unit_of_measure' => 'kg', 'reorder_level' => '0',
                'receiving_quantity' => '1', 'allow_alt_description' => 0, 'is_serialized' => 0,
            ]);
            $kg_id = (int) $this->db->insertID();
            $this->db->table('presale_items')->insert([
                'presale_id' => $this->presale_id, 'line' => 2, 'item_id' => $kg_id, 'quantity' => '0.750', 'unit_price' => '1.00',
            ]);

            $body = (string) $this->getAs('/presales/committed?campaign_id=' . $this->campaign_id)->getBody();

            $this->assertStringContainsString('0.75 kg', $body);
            $this->assertStringNotContainsString('0.750', $body);

            $response = $this->getAs('/presales/committed/csv?campaign_id=' . $this->campaign_id);
            ob_start();
            $response->response()->sendBody();
            $csv = (string) ob_get_clean();

            $this->assertStringContainsString('0.75,0.75,0,0.75', $csv);
        } finally {
            foreach ($before as $key => $value) {
                if ($value === null) {
                    $this->db->table('app_config')->where('key', $key)->delete();
                } else {
                    $this->db->table('app_config')->replace(['key' => $key, 'value' => $value]);
                }
            }

            $this->db->table('items')->where('name', 'COMMITTED-TEST pernil')->delete();
            config(OSPOS::class)->update_settings();
        }
    }

    public function testSafeCellPrefixesFormulaStarters(): void
    {
        foreach (['=1+1', '+1', '-1', '@x'] as $text) {
            $this->assertSame("'" . $text, PresaleReports::safeCell($text));
        }

        $this->assertSame('Pera', PresaleReports::safeCell('Pera'));
        $this->assertSame('', PresaleReports::safeCell(''));
    }
}
