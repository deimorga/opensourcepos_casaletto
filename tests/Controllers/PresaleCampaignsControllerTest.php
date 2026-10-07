<?php

namespace Tests\Controllers;

use App\Models\Item;
use App\Models\Presale;
use App\Models\Presale_campaign;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\OSPOS;

/**
 * The campaign management screens, over real requests (docs/Funcional/venta-anticipada.md §4.2).
 *
 * The rules themselves (dates, percentages, prices, what cannot be removed) are tested on the model
 * in tests/Models; here the point is that the controller reads the business's formats, answers
 * {success, message}, escapes what it echoes and is closed to anyone without presales_manage.
 *
 * SHARED STATE: the test database is shared between files. This one writes the presales_enable
 * switch, grants presales and presales_manage to person 1 only when missing, and creates campaigns,
 * items and a presale of its own. setUp() records what was there and tearDown() removes exactly what
 * this file added -- never a truncate -- and rebuilds the settings cache.
 *
 * SESSION: see SalesControllerTest::loginAsAdmin(). FeatureTestTrait overwrites $_SESSION on every
 * request and an anonymous request makes Secure_Controller end the process with exit(), so every
 * request here re-arms the session.
 *
 * @internal
 */
final class PresaleCampaignsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private const NAME_PREFIX = 'PRUEBA-CAMP ';

    protected $migrate            = true;
    protected $migrateOnce        = true;
    protected $refresh            = false;
    protected $namespace          = 'App';
    private ?string $switchBefore = null;

    /**
     * @var list<string> grants this file added, removed in tearDown
     */
    private array $grantedHere = [];

    /**
     * @var list<int>
     */
    private array $campaignIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // See SalesControllerTest::setUp(): without this Config\OSPOS sees no app_config table.
        $this->db->resetDataCache();

        $row                = $this->db->table('app_config')->where('key', 'presales_enable')->get()->getRow();
        $this->switchBefore = $row === null ? null : (string) $row->value;

        $this->switchTo('1');
        $this->grant('presales');
        $this->grant('presales_manage');
    }

    protected function tearDown(): void
    {
        $ids = $this->db->table('presale_campaigns')->select('campaign_id')->like('name', self::NAME_PREFIX, 'after')->get()->getResultArray();
        $ids = array_merge($this->campaignIds, array_map('intval', array_column($ids, 'campaign_id')));
        $ids = array_values(array_unique($ids));

        if ($ids !== []) {
            $presales = array_column($this->db->table('presales')->select('presale_id')->whereIn('campaign_id', $ids)->get()->getResultArray(), 'presale_id');

            if ($presales !== []) {
                $this->db->table('presale_items')->whereIn('presale_id', $presales)->delete();
                $this->db->table('presales')->whereIn('presale_id', $presales)->delete();
            }

            foreach (['presale_campaign_items', 'presale_campaign_dates', 'presale_campaigns'] as $table) {
                $this->db->table($table)->whereIn('campaign_id', $ids)->delete();
            }
        }

        $this->db->table('items')->like('name', self::NAME_PREFIX, 'after')->delete();

        foreach ($this->grantedHere as $permission) {
            $this->db->table('grants')->where('permission_id', $permission)->where('person_id', 1)->delete();
        }

        if ($this->switchBefore === null) {
            $this->db->table('app_config')->where('key', 'presales_enable')->delete();
        } else {
            $this->db->table('app_config')->replace(['key' => 'presales_enable', 'value' => $this->switchBefore]);
        }

        config(OSPOS::class)->update_settings();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    // Who gets in
    // ---------------------------------------------------------------------------------------------

    public function testWithTheSwitchOffTheEndpointsAnswerWithTheDisabledMessage(): void
    {
        $this->switchTo('0');

        $response = $this->getReq('presales/campaigns/search');

        $response->assertStatus(403);
        $this->assertFalse($this->json($response)['success']);
        $this->assertSame(lang('Presales.disabled'), $this->json($response)['message']);
    }

    public function testWithoutThePresalesManageSubpermissionEveryEndpointIsForbidden(): void
    {
        if (in_array('presales_manage', $this->grantedHere, true) === false) {
            $this->markTestSkipped('Person 1 already holds presales_manage in this database.');
        }

        $this->db->table('grants')->where('permission_id', 'presales_manage')->where('person_id', 1)->delete();

        $this->getReq('presales/campaigns/search')->assertStatus(403);
        $this->getReq('presales/campaigns/suggest')->assertStatus(403);
        $this->getReq('presales/campaigns/view/-1')->assertStatus(403);

        $campaign = $this->makeCampaign();
        $this->postReq("presales/campaigns/save/{$campaign}", ['name' => self::NAME_PREFIX . 'Hackeada'])->assertStatus(403);
        $this->postReq("presales/campaigns/{$campaign}/add_date", ['delivery_date' => $this->typed('2026-12-23')])->assertStatus(403);
        $this->postReq('presales/campaigns/delete', ['ids' => [(string) $campaign]])->assertStatus(403);

        $this->assertSame(self::NAME_PREFIX . 'Navidad', $this->campaign($campaign)['name'], 'Nothing was written.');

        $this->getReq("presales/campaigns/detail/{$campaign}")->assertRedirect();
    }

    // ---------------------------------------------------------------------------------------------
    // The list
    // ---------------------------------------------------------------------------------------------

    public function testTheListShowsEachCampaignWithItsPeriodInTheBusinessFormatAndItsCounts(): void
    {
        $id   = $this->makeCampaign(self::NAME_PREFIX . 'Lista');
        $item = $this->makeItem('lista');
        model(Presale_campaign::class)->add_item($id, $item);
        model(Presale_campaign::class)->add_date($id, '2026-12-23');
        model(Presale_campaign::class)->add_date($id, '2026-12-24');

        $body = $this->json($this->getReq('presales/campaigns/search?search=' . rawurlencode('PRUEBA-CAMP Lista')));

        $this->assertSame(1, $body['total']);
        $row = $body['rows'][0];
        $this->assertSame($id, $row['campaign_id']);
        $this->assertSame(self::NAME_PREFIX . 'Lista', $row['name'], 'Plain: bootstrap-table escapes the cell, escaping here would show &amp;.');
        $this->assertSame($this->typed('2026-11-01') . ' - ' . $this->typed('2026-12-15'), $row['sale_period']);
        $this->assertSame(1, $row['products']);
        $this->assertSame(2, $row['dates']);
        $this->assertStringContainsString("presales/campaigns/detail/{$id}", $row['detail']);
        $this->assertStringContainsString("presales/campaigns/view/{$id}", $row['edit']);
    }

    public function testASearchThatMatchesNothingAnswersAnEmptyList(): void
    {
        $this->makeCampaign();

        $body = $this->json($this->getReq('presales/campaigns/search?search=' . rawurlencode('no-existe-' . bin2hex(random_bytes(4)))));

        $this->assertSame(0, $body['total']);
        $this->assertSame([], $body['rows']);
    }

    public function testTheListPageAndTheFormUseTheSharedLayout(): void
    {
        $page = $this->getReq('presales/campaigns')->getBody();

        foreach (['id="title_bar"', 'id="toolbar"', 'id="table_holder"', 'resources/bootswatch/', 'table_support.init'] as $needle) {
            $this->assertStringContainsString($needle, $page);
        }

        $this->assertStringNotContainsString('bootswatch5', $page, 'No second design system.');
        $this->assertStringContainsString('presales/campaigns/view/-1', $page, 'The New button opens the form in the modal.');

        $form = $this->getReq('presales/campaigns/view/-1');
        $form->assertStatus(200);
        $html = $form->getBody();

        foreach (['id="campaign_form"', 'name="sale_starts"', 'name="sale_ends"', 'name="discount_percent"', 'name="min_initial_percent"', 'name="active"'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }

        $this->assertStringNotContainsString('resources/bootswatch/', $html, 'A fragment for the dialog, not a page with the shared header.');
    }

    // ---------------------------------------------------------------------------------------------
    // Saving
    // ---------------------------------------------------------------------------------------------

    public function testANewCampaignIsSavedReadingDatesAndPercentagesInTheBusinessFormat(): void
    {
        $response = $this->postReq('presales/campaigns/save/-1', [
            'name'                => self::NAME_PREFIX . 'Nueva',
            'sale_starts'         => $this->typed('2026-11-01'),
            'sale_ends'           => $this->typed('2026-12-15'),
            'discount_percent'    => '10',
            'min_initial_percent' => '30',
            'active'              => '1',
        ]);

        $body = $this->json($response);
        $this->assertTrue($body['success'], $body['message']);
        $this->campaignIds[] = (int) $body['id'];

        $row = $this->campaign((int) $body['id']);
        $this->assertSame(self::NAME_PREFIX . 'Nueva', $row['name']);
        $this->assertSame('2026-11-01', $row['sale_starts']);
        $this->assertSame('2026-12-15', $row['sale_ends']);
        $this->assertSame('10.00', $row['discount_percent']);
        $this->assertSame('30.00', $row['min_initial_percent']);
        $this->assertSame('1', (string) $row['active']);
        $this->assertSame(1, (int) $row['created_by']);
    }

    public function testAnUncheckedActiveBoxSavesTheCampaignAsInactive(): void
    {
        $body = $this->json($this->postReq('presales/campaigns/save/-1', $this->campaignForm(['active' => null])));

        $this->assertTrue($body['success'], $body['message']);
        $this->campaignIds[] = (int) $body['id'];
        $this->assertSame('0', (string) $this->campaign((int) $body['id'])['active']);
    }

    public function testASaveRefusesADateThatIsNotRealAndCreatesNothing(): void
    {
        $before = $this->db->table('presale_campaigns')->like('name', self::NAME_PREFIX, 'after')->countAllResults();

        $body = $this->json($this->postReq('presales/campaigns/save/-1', $this->campaignForm(['sale_starts' => '31/02/2026'])));

        $this->assertFalse($body['success']);
        $this->assertStringContainsString('31/02/2026', $body['message'], 'Says what was typed, with an example in the business format.');
        $this->assertSame($before, $this->db->table('presale_campaigns')->like('name', self::NAME_PREFIX, 'after')->countAllResults());
    }

    public function testASaveRefusesAClosingDateBeforeTheOpeningOne(): void
    {
        $body = $this->json($this->postReq('presales/campaigns/save/-1', $this->campaignForm([
            'sale_starts' => $this->typed('2026-12-15'),
            'sale_ends'   => $this->typed('2026-11-01'),
        ])));

        $this->assertFalse($body['success']);
        $this->assertSame(lang('Presales.campaign_dates_invalid'), $body['message']);
    }

    public function testASaveRefusesAPercentageOutsideZeroToOneHundred(): void
    {
        $body = $this->json($this->postReq('presales/campaigns/save/-1', $this->campaignForm(['discount_percent' => '150'])));
        $this->assertFalse($body['success']);
        $this->assertSame(lang('Presales.percent_invalid'), $body['message']);

        $body = $this->json($this->postReq('presales/campaigns/save/-1', $this->campaignForm(['min_initial_percent' => 'abc'])));
        $this->assertFalse($body['success']);
        $this->assertSame(lang('Presales.percent_invalid'), $body['message']);
    }

    public function testASaveRefusesAMissingName(): void
    {
        $body = $this->json($this->postReq('presales/campaigns/save/-1', $this->campaignForm(['name' => '   '])));

        $this->assertFalse($body['success']);
        $this->assertSame(lang('Presales.campaign_name_required'), $body['message']);
    }

    /**
     * $.notify writes HTML, so a name with markup must reach it escaped.
     */
    public function testTheSaveMessageEscapesTheCampaignName(): void
    {
        $body = $this->json($this->postReq('presales/campaigns/save/-1', $this->campaignForm(['name' => self::NAME_PREFIX . '<img src=x onerror=alert(1)>'])));

        $this->assertTrue($body['success'], $body['message']);
        $this->campaignIds[] = (int) $body['id'];
        $this->assertStringNotContainsString('<img', $body['message']);
        $this->assertStringContainsString('&lt;img', $body['message']);
    }

    public function testEditingACampaignUpdatesItInPlace(): void
    {
        $id = $this->makeCampaign();

        $body = $this->json($this->postReq("presales/campaigns/save/{$id}", $this->campaignForm([
            'name'             => self::NAME_PREFIX . 'Renombrada',
            'discount_percent' => '12',
        ])));

        $this->assertTrue($body['success'], $body['message']);
        $this->assertSame($id, (int) $body['id']);
        $this->assertSame(self::NAME_PREFIX . 'Renombrada', $this->campaign($id)['name']);
        $this->assertSame('12.00', $this->campaign($id)['discount_percent']);
    }

    public function testEditingACampaignThatDoesNotExistIsRefused(): void
    {
        $body = $this->json($this->postReq('presales/campaigns/save/999999999', $this->campaignForm()));

        $this->assertFalse($body['success']);
        $this->assertSame(lang('Presales.campaign_not_found'), $body['message']);
    }

    // ---------------------------------------------------------------------------------------------
    // Delete
    // ---------------------------------------------------------------------------------------------

    public function testDeleteIsLogicalAndTheCampaignLeavesTheList(): void
    {
        $id = $this->makeCampaign(self::NAME_PREFIX . 'Borrar');

        $body = $this->json($this->postReq('presales/campaigns/delete', ['ids' => [(string) $id]]));

        $this->assertTrue($body['success'], $body['message']);
        $this->assertSame([$id], $body['ids']);
        $this->assertSame('1', (string) $this->campaign($id)['deleted']);

        $list = $this->json($this->getReq('presales/campaigns/search?search=' . rawurlencode('PRUEBA-CAMP Borrar')));
        $this->assertSame(0, $list['total']);
    }

    public function testDeleteIsRefusedWhileTheCampaignHasPresalesAndSaysWhich(): void
    {
        $id = $this->makeCampaign(self::NAME_PREFIX . 'Con preventas <b>');
        $this->makePresale($id, $this->makeItem('en preventa'), $this->dateId($id));

        $body = $this->json($this->postReq('presales/campaigns/delete', ['ids' => [(string) $id]]));

        $this->assertFalse($body['success']);
        $this->assertStringContainsString('&lt;b&gt;', $body['message'], 'The name is escaped: the message goes to $.notify.');
        $this->assertStringNotContainsString('<b>', $body['message']);
        $this->assertSame('0', (string) $this->campaign($id)['deleted'], 'The campaign is still there.');
    }

    public function testDeleteWithNothingSelectedSaysSo(): void
    {
        $body = $this->json($this->postReq('presales/campaigns/delete', []));

        $this->assertFalse($body['success']);
        $this->assertSame(lang('Presale_campaigns.delete_none'), $body['message']);
    }

    // ---------------------------------------------------------------------------------------------
    // The detail: products
    // ---------------------------------------------------------------------------------------------

    public function testTheDetailPageDrawsTheTabsInsideTheSharedLayout(): void
    {
        $id = $this->makeCampaign();

        $page = $this->getReq("presales/campaigns/detail/{$id}");
        $page->assertStatus(200);
        $html = $page->getBody();

        foreach (['id="campaign_tabs"', 'id="items_table"', 'id="dates_table"', 'id="product_search"', 'resources/bootswatch/'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }

        $this->assertStringNotContainsString('bootswatch5', $html);
        $this->assertStringContainsString(self::NAME_PREFIX . 'Navidad', $html);
    }

    public function testTheDetailOfAMissingCampaignGoesBackToTheList(): void
    {
        $this->getReq('presales/campaigns/detail/999999999')->assertRedirect();
    }

    public function testAddingAProductCopiesItsCataloguePriceAndTheDataEndpointShowsTheEffectivePrice(): void
    {
        $id   = $this->makeCampaign(); // 10% off the whole campaign
        $item = $this->makeItem('pavo', '100000.00');

        $body = $this->json($this->postReq("presales/campaigns/{$id}/add_item", ['item_id' => (string) $item]));
        $this->assertTrue($body['success'], $body['message']);

        $row = $this->db->table('presale_campaign_items')->where('campaign_id', $id)->where('item_id', $item)->get()->getRowArray();
        $this->assertSame('100000.00', $row['base_price']);

        $data = $this->json($this->getReq("presales/campaigns/data/{$id}"));
        $this->assertTrue($data['success']);
        $this->assertCount(1, $data['items']);
        $this->assertSame($item, $data['items'][0]['item_id']);
        $this->assertSame(self::NAME_PREFIX . 'pavo', $data['items'][0]['name']);
        $this->assertSame(to_currency('100000.00'), $data['items'][0]['catalogue_price']);
        $this->assertSame(to_currency('90000.00'), $data['items'][0]['effective_price'], 'Catalogue price less the campaign 10%.');
        $this->assertSame('', $data['items'][0]['price_input']);
        $this->assertSame('', $data['items'][0]['discount_input']);
        $this->assertFalse($data['items'][0]['is_weight']);
    }

    public function testAWeightProductIsFlaggedAsSuch(): void
    {
        $id   = $this->makeCampaign();
        $item = $this->makeItem('pernil', '40000.00', ['unit_of_measure' => Item::UNIT_OF_MEASURE_KG]);
        $this->postReq("presales/campaigns/{$id}/add_item", ['item_id' => (string) $item]);

        $this->assertTrue($this->json($this->getReq("presales/campaigns/data/{$id}"))['items'][0]['is_weight']);
    }

    public function testAddingWithoutPickingAProductOrAnUnknownOneIsRefused(): void
    {
        $id = $this->makeCampaign();

        $body = $this->json($this->postReq("presales/campaigns/{$id}/add_item", ['item_id' => '']));
        $this->assertFalse($body['success']);
        $this->assertSame(lang('Presale_campaigns.item_not_picked'), $body['message']);

        $body = $this->json($this->postReq("presales/campaigns/{$id}/add_item", ['item_id' => '999999999']));
        $this->assertFalse($body['success']);
        $this->assertSame(lang('Presales.item_not_in_catalogue'), $body['message']);
    }

    public function testAProductOwnPriceAndDiscountAreSavedAndAnEmptyFieldClearsThem(): void
    {
        $id   = $this->makeCampaign();
        $item = $this->makeItem('jamon', '100000.00');
        model(Presale_campaign::class)->add_item($id, $item);

        $body = $this->json($this->postReq("presales/campaigns/{$id}/update_item/{$item}", ['discount_percent' => '20', 'campaign_price' => '']));
        $this->assertTrue($body['success'], $body['message']);

        $row = $this->itemRow($id, $item);
        $this->assertSame('20.00', $row['discount_percent']);
        $this->assertNull($row['campaign_price']);
        $this->assertSame(to_currency('80000.00'), $this->json($this->getReq("presales/campaigns/data/{$id}"))['items'][0]['effective_price'], 'Own 20% replaces the campaign 10%.');

        $this->postReq("presales/campaigns/{$id}/update_item/{$item}", ['discount_percent' => '20', 'campaign_price' => '75000']);
        $this->assertSame('75000.00', $this->itemRow($id, $item)['campaign_price']);
        $this->assertSame(to_currency('75000.00'), $this->json($this->getReq("presales/campaigns/data/{$id}"))['items'][0]['effective_price'], 'Own price replaces both discounts.');

        $this->postReq("presales/campaigns/{$id}/update_item/{$item}", ['discount_percent' => '', 'campaign_price' => '']);
        $row = $this->itemRow($id, $item);
        $this->assertNull($row['discount_percent']);
        $this->assertNull($row['campaign_price']);
    }

    public function testAProductUpdateRefusesAnUnreadablePriceOrAPercentageOverOneHundred(): void
    {
        $id   = $this->makeCampaign();
        $item = $this->makeItem('invalido');
        model(Presale_campaign::class)->add_item($id, $item);

        $body = $this->json($this->postReq("presales/campaigns/{$id}/update_item/{$item}", ['discount_percent' => '', 'campaign_price' => 'mucho']));
        $this->assertFalse($body['success']);
        $this->assertSame(lang('Presales.price_invalid'), $body['message']);

        $body = $this->json($this->postReq("presales/campaigns/{$id}/update_item/{$item}", ['discount_percent' => '101', 'campaign_price' => '']));
        $this->assertFalse($body['success']);
        $this->assertSame(lang('Presales.percent_invalid'), $body['message']);

        $body = $this->json($this->postReq("presales/campaigns/{$id}/update_item/999999999", ['discount_percent' => '5', 'campaign_price' => '']));
        $this->assertFalse($body['success']);
        $this->assertSame(lang('Presales.item_not_in_campaign'), $body['message']);
    }

    public function testRemovingAProductTakesItOutOfTheCampaign(): void
    {
        $id   = $this->makeCampaign();
        $item = $this->makeItem('quitar');
        model(Presale_campaign::class)->add_item($id, $item);

        $body = $this->json($this->postReq("presales/campaigns/{$id}/remove_item/{$item}", []));

        $this->assertTrue($body['success'], $body['message']);
        $this->assertNull($this->itemRow($id, $item));
    }

    public function testRemovingAProductAnOpenPresaleUsesIsRefused(): void
    {
        $id   = $this->makeCampaign();
        $item = $this->makeItem('en uso');
        model(Presale_campaign::class)->add_item($id, $item);
        $this->makePresale($id, $item, $this->dateId($id));

        $body = $this->json($this->postReq("presales/campaigns/{$id}/remove_item/{$item}", []));

        $this->assertFalse($body['success']);
        $this->assertSame(lang('Presale_campaigns.item_remove_blocked'), $body['message']);
        $this->assertNotNull($this->itemRow($id, $item));
    }

    public function testTheProductPickerHasItsOwnSuggestEndpoint(): void
    {
        $item = $this->makeItem('suggest unico');

        $rows = json_decode((string) $this->getReq('presales/campaigns/suggest?term=' . rawurlencode('suggest unico'))->getJSON(), true);

        $this->assertIsArray($rows);
        $this->assertContains($item, array_map('intval', array_column($rows, 'value')));
    }

    // ---------------------------------------------------------------------------------------------
    // The detail: delivery dates
    // ---------------------------------------------------------------------------------------------

    public function testADeliveryDateIsAddedInTheBusinessFormatAndListedBackInIt(): void
    {
        $id = $this->makeCampaign();

        $body = $this->json($this->postReq("presales/campaigns/{$id}/add_date", ['delivery_date' => $this->typed('2026-12-23')]));
        $this->assertTrue($body['success'], $body['message']);

        $this->assertSame('2026-12-23', $this->db->table('presale_campaign_dates')->where('campaign_id', $id)->get()->getRowArray()['delivery_date']);

        $data = $this->json($this->getReq("presales/campaigns/data/{$id}"));
        $this->assertSame($this->typed('2026-12-23'), $data['dates'][0]['date']);
    }

    public function testADeliveryDateThatIsNotRealIsRefused(): void
    {
        $id = $this->makeCampaign();

        $body = $this->json($this->postReq("presales/campaigns/{$id}/add_date", ['delivery_date' => '45/45/2026']));

        $this->assertFalse($body['success']);
        $this->assertStringContainsString('45/45/2026', $body['message']);
        $this->assertSame(0, $this->db->table('presale_campaign_dates')->where('campaign_id', $id)->countAllResults());
    }

    public function testADeliveryDateIsRemovedUnlessAPresaleWasAgreedForIt(): void
    {
        $id = $this->makeCampaign();
        model(Presale_campaign::class)->add_date($id, '2026-12-23');
        model(Presale_campaign::class)->add_date($id, '2026-12-24');
        $free = (int) $this->db->table('presale_campaign_dates')->where('campaign_id', $id)->where('delivery_date', '2026-12-24')->get()->getRow()->date_id;
        $used = (int) $this->db->table('presale_campaign_dates')->where('campaign_id', $id)->where('delivery_date', '2026-12-23')->get()->getRow()->date_id;
        $this->makePresale($id, $this->makeItem('fecha'), $used);

        $body = $this->json($this->postReq("presales/campaigns/{$id}/remove_date/{$used}", []));
        $this->assertFalse($body['success']);
        $this->assertSame(lang('Presale_campaigns.date_remove_blocked'), $body['message']);

        $body = $this->json($this->postReq("presales/campaigns/{$id}/remove_date/{$free}", []));
        $this->assertTrue($body['success'], $body['message']);
        $this->assertSame(1, $this->db->table('presale_campaign_dates')->where('campaign_id', $id)->countAllResults());
    }

    public function testADateOfAnotherCampaignCannotBeRemovedThroughThisOne(): void
    {
        $mine  = $this->makeCampaign();
        $other = $this->makeCampaign(self::NAME_PREFIX . 'Otra');
        model(Presale_campaign::class)->add_date($other, '2026-12-23');
        $date = $this->dateId($other);

        $body = $this->json($this->postReq("presales/campaigns/{$mine}/remove_date/{$date}", []));

        $this->assertFalse($body['success']);
        $this->assertSame(1, $this->db->table('presale_campaign_dates')->where('campaign_id', $other)->countAllResults());
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------

    private function switchTo(string $value): void
    {
        $this->db->table('app_config')->replace(['key' => 'presales_enable', 'value' => $value]);
        config(OSPOS::class)->update_settings();
    }

    private function grant(string $permission): void
    {
        $exists = $this->db->table('grants')->where('permission_id', $permission)->where('person_id', 1)->countAllResults() > 0;

        if (! $exists) {
            $this->db->table('grants')->insert(['permission_id' => $permission, 'person_id' => 1, 'menu_group' => $permission === 'presales' ? 'both' : '--']);
            $this->grantedHere[] = $permission;
        }
    }

    /**
     * A Y-m-d date the way the business types it.
     */
    private function typed(string $ymd): string
    {
        return date(config(OSPOS::class)->settings['dateformat'], strtotime($ymd));
    }

    /**
     * A valid campaign form, with $overrides on top; a null override leaves that field out (an
     * unchecked box is not sent at all).
     *
     * @param array<string, string|null> $overrides
     *
     * @return array<string, string>
     */
    private function campaignForm(array $overrides = []): array
    {
        $form = $overrides + [
            'name'                => self::NAME_PREFIX . 'Formulario',
            'sale_starts'         => $this->typed('2026-11-01'),
            'sale_ends'           => $this->typed('2026-12-15'),
            'discount_percent'    => '10',
            'min_initial_percent' => '30',
            'active'              => '1',
        ];

        return array_filter($form, static fn ($value) => $value !== null);
    }

    /**
     * A campaign made through the model: 2026-11-01 to 2026-12-15, 10% off, 30% initial.
     */
    private function makeCampaign(string $name = self::NAME_PREFIX . 'Navidad'): int
    {
        $id = model(Presale_campaign::class)->save_campaign([
            'name'                => $name,
            'sale_starts'         => '2026-11-01',
            'sale_ends'           => '2026-12-15',
            'discount_percent'    => '10',
            'min_initial_percent' => '30',
            'active'              => 1,
        ], NEW_ENTRY, 1);

        $this->assertIsInt($id);
        $this->campaignIds[] = $id;

        return $id;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeItem(string $name, string $price = '3500.00', array $overrides = []): int
    {
        $this->db->table('items')->insert($overrides + [
            'name'                  => self::NAME_PREFIX . $name,
            'category'              => 'Test',
            'item_number'           => null,
            'description'           => '',
            'cost_price'            => '1.00',
            'unit_price'            => $price,
            'reorder_level'         => '0',
            'receiving_quantity'    => '1',
            'allow_alt_description' => 0,
            'is_serialized'         => 0,
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * The id of the campaign's only delivery date, creating one when it has none.
     */
    private function dateId(int $campaign_id): int
    {
        model(Presale_campaign::class)->add_date($campaign_id, '2026-12-23');

        return (int) model(Presale_campaign::class)->get_dates($campaign_id)[0]['date_id'];
    }

    /**
     * A bare open presale with one line, inserted straight into the tables: what is under test is the
     * campaign screens' refusals, not how a presale is registered (tests/Models/PresaleTest.php).
     */
    private function makePresale(int $campaign_id, int $item_id, int $date_id): int
    {
        $this->db->table('presales')->insert([
            'campaign_id'      => $campaign_id,
            'customer_id'      => 1,
            'employee_id'      => 1,
            'location_id'      => 1,
            'created_at'       => date('Y-m-d H:i:s'),
            'delivery_date_id' => $date_id,
            'delivery_date'    => '2026-12-23',
            'status'           => Presale::STATUS_OPEN,
            'total'            => '1.00',
            'comment'          => '',
        ]);
        $presale_id = (int) $this->db->insertID();

        $this->db->table('presale_items')->insert([
            'presale_id'    => $presale_id,
            'line'          => 1,
            'item_id'       => $item_id,
            'description'   => '',
            'quantity'      => '1.000',
            'unit_price'    => '1.00',
            'discount'      => '0.00',
            'discount_type' => 0,
            'print_option'  => 0,
            'item_type'     => 0,
        ]);

        return $presale_id;
    }

    /**
     * @return array<string, mixed>
     */
    private function campaign(int $id): array
    {
        return $this->db->table('presale_campaigns')->where('campaign_id', $id)->get()->getRowArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function itemRow(int $campaign_id, int $item_id): ?array
    {
        return $this->db->table('presale_campaign_items')->where('campaign_id', $campaign_id)->where('item_id', $item_id)->get()->getRowArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(TestResponse $response): array
    {
        $decoded = json_decode((string) $response->getJSON(), true);

        $this->assertIsArray($decoded, 'The answer was not JSON: ' . substr((string) $response->getBody(), 0, 200));

        return $decoded;
    }

    /**
     * GET with the session re-armed every time -- see the class docblock.
     */
    private function getReq(string $path): TestResponse
    {
        $_SESSION = ['person_id' => 1, 'menu_group' => 'office'];
        $this->withSession($_SESSION);

        return $this->get($path);
    }

    /**
     * POST with the session re-armed.
     *
     * @param array<string, mixed> $params
     */
    private function postReq(string $path, array $params): TestResponse
    {
        $_SESSION = ['person_id' => 1, 'menu_group' => 'office'];
        $this->withSession($_SESSION);

        return $this->post($path, $params);
    }
}
