<?php

namespace Tests\Controllers;

use App\Models\Item;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\OSPOS;

/**
 * What the staging certification of 2026-10-07 found in the presales screens, pinned so it cannot come
 * back (docs/Tecnico/venta-anticipada.md §7.10):
 *
 * 1. A new campaign's form came with "active" unchecked, so a campaign saved as offered never appeared
 *    when registering a presale.
 * 2. The campaign's product search left item kits out, so no basket or combo could be sold in advance.
 * 3. The presales list had no way to reach Campaigns or the committed-quantities report.
 * 4. A product sold by weight was shown with the business's quantity decimals: with 0, 2.5 kg read "3".
 *
 * Shared database: only this file's rows are removed, and every setting and grant it touches is put
 * back as it was.
 *
 * @internal
 */
final class PresaleCertificationFixesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private const TOUCHED_KEYS = ['presales_enable', 'quantity_decimals', 'number_locale', 'thousands_separator'];

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    /**
     * @var array<string, string|null>
     */
    private array $previous = [];

    /**
     * @var list<string>
     */
    private array $granted_here = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::TOUCHED_KEYS as $key) {
            $row                  = $this->db->table('app_config')->where('key', $key)->get()->getRow();
            $this->previous[$key] = $row === null ? null : (string) $row->value;
        }

        $this->given(['presales_enable' => '1']);
        $this->grant('presales');
    }

    protected function tearDown(): void
    {
        foreach ($this->granted_here as $permission) {
            $this->db->table('grants')->where('permission_id', $permission)->where('person_id', 1)->delete();
        }

        $this->db->table('items')->like('name', 'CERTFIX', 'after')->delete();

        foreach ($this->previous as $key => $value) {
            if ($value === null) {
                $this->db->table('app_config')->where('key', $key)->delete();
            } else {
                $this->db->table('app_config')->replace(['key' => $key, 'value' => $value]);
            }
        }

        config(OSPOS::class)->update_settings();

        parent::tearDown();
    }

    public function testANewCampaignComesActive(): void
    {
        $this->grant('presales_manage');

        $body = (string) $this->get_as('/presales/campaigns/view/-1')->response()->getBody();

        $this->assertMatchesRegularExpression('/<input[^>]*name="active"[^>]*checked/', $body);
    }

    public function testTheCampaignProductSearchOffersItemKits(): void
    {
        $this->grant('presales_manage');

        $this->db->table('items')->insert([
            'name'                  => 'CERTFIX Canasta navideña',
            'category'              => 'Test',
            'item_number'           => null,
            'description'           => '',
            'cost_price'            => '0.00',
            'unit_price'            => '45000.00',
            'reorder_level'         => '0',
            'receiving_quantity'    => '1',
            'allow_alt_description' => 0,
            'is_serialized'         => 0,
            'item_type'             => ITEM_KIT,
        ]);
        $kit_id = (string) $this->db->insertID();

        $json = json_decode((string) $this->get_as('/presales/campaigns/suggest?term=CERTFIX')->response()->getBody(), true);

        $this->assertContains($kit_id, array_map('strval', array_column($json, 'value')));
    }

    public function testTheListLeadsToTheCommittedReportAndToCampaignsForWhoeverManages(): void
    {
        $had_manage = $this->db->table('grants')->where('permission_id', 'presales_manage')->where('person_id', 1)->countAllResults() > 0;

        $body = (string) $this->get_as('/presales')->response()->getBody();
        $this->assertStringContainsString('presales/committed', $body);

        if (! $had_manage) {
            $this->assertStringNotContainsString('href="' . site_url('presales/campaigns') . '"', $body, 'Campaigns is for whoever holds presales_manage.');
        }

        $this->grant('presales_manage');

        $body = (string) $this->get_as('/presales')->response()->getBody();
        $this->assertStringContainsString('href="' . site_url('presales/campaigns') . '"', $body);
    }

    public function testAWeightKeepsItsDecimalsWhateverTheBusinessQuantitySetting(): void
    {
        $this->given(['quantity_decimals' => '0', 'number_locale' => 'en_US', 'thousands_separator' => '']);
        helper(['locale', 'presales']);

        $this->assertSame('2.5 ' . Item::unit_of_measure_symbol(Item::UNIT_OF_MEASURE_KG), presale_quantity('2.500', Item::UNIT_OF_MEASURE_KG));
        $this->assertSame('0.75 ' . Item::unit_of_measure_symbol(Item::UNIT_OF_MEASURE_KG), presale_quantity('0.750', Item::UNIT_OF_MEASURE_KG));
        $this->assertSame(to_quantity_decimals('3.000'), presale_quantity('3.000', Item::UNIT_OF_MEASURE_UNIT), 'A product sold by the unit keeps the register rule.');
    }

    /**
     * Staging, 2026-10-08: the instalment date picker wrote "08/10/2026 20:24:03" and the server, which
     * reads a date, refused it. The instalment picker is configured with the business's date format only,
     * not through the shared pickerconfig() (which carries the time and ignores the options it is given).
     */
    public function testTheInstalmentDatePickerWritesTheDateOnly(): void
    {
        helper('locale');

        $body = (string) $this->get_as('/presales/new')->response()->getBody();

        $this->assertStringNotContainsString('pickerconfig({ minView', $body);
        $this->assertStringContainsString('format: ' . json_encode(dateformat_bootstrap(config(OSPOS::class)->settings['dateformat'])), $body);
    }

    /**
     * @param array<string, string> $settings
     */
    private function given(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $this->db->table('app_config')->replace(['key' => $key, 'value' => $value]);
        }

        config(OSPOS::class)->update_settings();
    }

    private function grant(string $permission): void
    {
        $exists = $this->db->table('grants')->where('permission_id', $permission)->where('person_id', 1)->countAllResults() > 0;

        if (! $exists) {
            $this->db->table('grants')->insert(['permission_id' => $permission, 'person_id' => 1, 'menu_group' => $permission === 'presales' ? 'both' : '--']);
            $this->granted_here[] = $permission;
        }
    }

    /**
     * Re-arms the session before the request: without it Secure_Controller calls a real exit() and
     * the PHPUnit process dies with no output (see SalesControllerTest::loginAsAdmin()).
     */
    private function get_as(string $uri)
    {
        $session = Services::session();
        $session->destroy();
        $session->set('person_id', 1);
        $session->set('menu_group', 'office');
        $this->withSession(['person_id' => 1, 'menu_group' => 'office']);

        $response = $this->get($uri);
        $response->assertStatus(200);

        return $response;
    }
}
