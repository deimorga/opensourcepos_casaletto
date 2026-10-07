<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Models\Item;
use App\Models\Presale;
use App\Models\Presale_campaign;
use App\Models\Presale_payment;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\OSPOS;

/**
 * The presales screens through their real routes: the list, registering, one presale, taking an
 * instalment and the two documents.
 *
 * The rules themselves are the model's and are tested in tests/Models/PresaleTest.php. What is pinned
 * here is what the controller adds: it reads the form in the business's formats, never takes a price
 * from it, answers with escaped messages, filters the list by the derived state, and shows each action
 * only when it can work.
 *
 * SHARED DATABASE: this file creates its own customer, item, open shift and campaign, and removes them
 * by their ids. Person 1 is granted `presales` only if it did not hold it, and the switch and the terms
 * are put back as they were. The session is re-armed before every request, or Secure_Controller exits
 * silently (OrderTicketsControllerTest).
 *
 * "Today" is the real date: the controller registers with date('Y-m-d'), so the campaign's window and
 * the plan are built around it, with a margin of days for the timezone the request switches to.
 *
 * @internal
 */
final class PresalesControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';
    private int $customer_id;
    private int $cashup_id;
    private int $campaign_id;
    private int $date_id;
    private int $item_id;

    /**
     * @var array<string, string|null> app_config values before the test, null = absent
     */
    private array $configBefore = [];

    /**
     * @var list<string>
     */
    private array $grantedHere = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['presales_enable', 'presales_terms'] as $key) {
            $row                      = $this->db->table('app_config')->where('key', $key)->get()->getRow();
            $this->configBefore[$key] = $row === null ? null : (string) $row->value;
        }

        $this->setConfig('presales_enable', '1');
        $this->grant('presales');

        $this->customer_id = $this->makeCustomer('Ana', 'Ruiz PRESALECTL-TEST <i>x</i>', '3109998877');
        $this->cashup_id   = $this->openShift();
        $this->item_id     = $this->makeItem('PRESALECTL-TEST pavo', '100000.00');

        $campaigns = model(Presale_campaign::class);
        $campaign  = $campaigns->save_campaign([
            'name'                => 'PRESALECTL-TEST Navidad',
            'sale_starts'         => $this->day(-5),
            'sale_ends'           => $this->day(40),
            'discount_percent'    => '10',
            'min_initial_percent' => '30',
            'active'              => 1,
        ], NEW_ENTRY, 1);

        $this->assertIsInt($campaign);
        $this->campaign_id = $campaign;

        $this->assertTrue($campaigns->add_date($this->campaign_id, $this->day(60)));
        $this->date_id = (int) $campaigns->get_dates($this->campaign_id)[0]['date_id'];
        $this->assertTrue($campaigns->add_item($this->campaign_id, $this->item_id));
    }

    protected function tearDown(): void
    {
        $ids = array_column($this->db->table('presales')->select('presale_id')->where('campaign_id', $this->campaign_id ?? 0)->get()->getResultArray(), 'presale_id');

        if ($ids !== []) {
            foreach (['presale_events', 'presale_payments', 'presale_installments', 'presale_items', 'presales'] as $table) {
                $this->db->table($table)->whereIn('presale_id', $ids)->delete();
            }
        }

        $this->db->table('presale_campaign_items')->where('campaign_id', $this->campaign_id ?? 0)->delete();
        $this->db->table('presale_campaign_dates')->where('campaign_id', $this->campaign_id ?? 0)->delete();
        $this->db->table('presale_campaigns')->where('campaign_id', $this->campaign_id ?? 0)->delete();
        $this->db->table('items')->like('name', 'PRESALECTL-TEST', 'after')->delete();
        $this->db->table('cash_up')->where('cashup_id', $this->cashup_id ?? 0)->delete();
        $this->db->table('customers')->where('person_id', $this->customer_id ?? 0)->delete();
        $this->db->table('people')->where('person_id', $this->customer_id ?? 0)->delete();

        foreach ($this->grantedHere as $permission) {
            $this->db->table('grants')->where('permission_id', $permission)->where('person_id', 1)->delete();
        }

        foreach ($this->configBefore as $key => $value) {
            if ($value === null) {
                $this->db->table('app_config')->where('key', $key)->delete();
            } else {
                $this->db->table('app_config')->replace(['key' => $key, 'value' => $value]);
            }
        }

        config(OSPOS::class)->update_settings();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    // Registering
    // ---------------------------------------------------------------------------------------------

    /**
     * The form says the product costs 1; the campaign says 90,000 (100,000 less 10%). The campaign wins.
     */
    public function testRegisteringTakesThePriceFromTheCampaignNeverFromTheForm(): void
    {
        $result = $this->postJson('presales/save', $this->form([
            'lines' => [['item_id' => (string) $this->item_id, 'quantity' => '2', 'unit_price' => '1']],
        ]));

        $this->assertTrue($result['success'], $result['message'] ?? '');

        $presale = model(Presale::class)->get_info((int) $result['id']);
        $this->assertSame('180000.00', $presale['total']);
        $this->assertSame((int) $this->date_id, (int) $presale['delivery_date_id']);
        $this->assertStringContainsString('presales/receipt/' . $result['id'], $result['receipt_url']);

        $payments = model(Presale_payment::class)->get_for((int) $result['id']);
        $this->assertCount(1, $payments);
        $this->assertSame('60000.00', $payments[0]['amount']);
        $this->assertSame($this->cashup_id, (int) $payments[0]['cashup_id']);
    }

    public function testAPlanThatDoesNotAddUpIsRefusedWithTheModelsMessage(): void
    {
        $result = $this->postJson('presales/save', $this->form([
            'installments' => [
                ['due_date' => $this->typed(0), 'amount' => '60000'],
                ['due_date' => $this->typed(30), 'amount' => '1000'],
            ],
        ]));

        $this->assertFalse($result['success']);
        $this->assertSame(esc(lang('Presales.installments_must_add_up')), $result['message']);
        $this->assertSame(0, $this->db->table('presales')->where('campaign_id', $this->campaign_id)->countAllResults());
    }

    /**
     * An impossible date is refused with the app's date message, never rolled over into another day.
     */
    public function testAnImpossibleDateIsRefused(): void
    {
        $result = $this->postJson('presales/save', $this->form([
            'installments' => [['due_date' => '31/02/2026', 'amount' => '180000']],
        ]));

        $this->assertFalse($result['success']);
        $this->assertSame(esc(typed_date_error('31/02/2026')), $result['message']);
    }

    /**
     * What the cashier typed comes back in the message, escaped: the screen shows it as HTML.
     */
    public function testATypedAmountComesBackEscaped(): void
    {
        $result = $this->postJson('presales/save', $this->form(['payment_amount' => '<b>x</b>']));

        $this->assertFalse($result['success']);
        $this->assertStringNotContainsString('<b>', $result['message']);
        $this->assertStringContainsString('&lt;b&gt;', $result['message']);
    }

    public function testWithTheModuleOffNothingIsRegistered(): void
    {
        $this->setConfig('presales_enable', '0');

        $result = $this->postJson('presales/save', $this->form());

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->db->table('presales')->where('campaign_id', $this->campaign_id)->countAllResults());
    }

    public function testTheRegistrationPageOffersTheSellingCampaign(): void
    {
        $response = $this->getReq('presales/new');

        $response->assertStatus(200);
        $body = (string) $response->getBody();
        $this->assertStringContainsString('PRESALECTL-TEST Navidad', $body);
        $this->assertStringContainsString('presales/suggestCustomer', $body);
    }

    public function testThePreviewAddsUpTheLinesAndThePlan(): void
    {
        $result = $this->postJson('presales/preview', $this->form([
            'installments' => [['due_date' => $this->typed(0), 'amount' => '100000']],
        ]));

        $this->assertTrue($result['success']);
        $this->assertSame(to_currency('180000'), $result['total']);
        $this->assertFalse($result['plan']['matches']);
        $this->assertSame(esc(lang('Presales.plan_missing', [to_currency('80000')])), $result['plan']['message']);
    }

    public function testTheCustomerSearchFindsByName(): void
    {
        $response = $this->getReq('presales/suggestCustomer?term=PRESALECTL-TEST');

        $response->assertStatus(200);
        $this->assertContains($this->customer_id, array_map('intval', array_column(json_decode((string) $response->getJSON(), true), 'value')));
    }

    // ---------------------------------------------------------------------------------------------
    // The list
    // ---------------------------------------------------------------------------------------------

    public function testTheListFiltersByTheDerivedState(): void
    {
        $paid = $this->register('180000');
        $late = $this->register('60000', [
            ['due_date' => $this->day(-10), 'amount' => '60000'],
            ['due_date' => $this->day(-5), 'amount' => '60000'],
            ['due_date' => $this->day(30), 'amount' => '60000'],
        ]);
        $current = $this->register('60000');

        $this->assertSame([$paid], $this->listedIds(['states' => ['paid']]));
        $this->assertSame([$late], $this->listedIds(['states' => ['late']]));
        $this->assertSame([$current], $this->listedIds(['states' => ['up_to_date']]));
        $this->assertSame([$current, $late, $paid], $this->listedIds([]));

        $row = $this->listedRows(['states' => ['late']])[0];
        $this->assertGreaterThan(0, (int) $row['days_late']);
        $this->assertStringContainsString(esc(lang('Presales.state_late')), $row['state']);
    }

    public function testTheListFindsAPresaleByItsNumberOrTheCustomersPhone(): void
    {
        $id = $this->register('60000');
        $this->register('60000');

        $number = model(Presale::class)->number($id);

        $this->assertSame([$id], $this->listedIds(['search' => $number]));
        $this->assertCount(2, $this->listedIds(['search' => '3109998877']));
    }

    // ---------------------------------------------------------------------------------------------
    // One presale
    // ---------------------------------------------------------------------------------------------

    public function testDeliverIsOfferedOnlyWhenThePresaleIsPaid(): void
    {
        $paid    = $this->register('180000');
        $pending = $this->register('60000');

        $paidBody = (string) $this->getReq('presales/view/' . $paid)->getBody();
        $this->assertStringContainsString('sales/deliverPresale/' . $paid, $paidBody);
        $this->assertStringNotContainsString('id="presale_take_payment"', $paidBody);

        $pendingBody = (string) $this->getReq('presales/view/' . $pending)->getBody();
        $this->assertStringNotContainsString('sales/deliverPresale/', $pendingBody);
        $this->assertStringContainsString('id="presale_take_payment"', $pendingBody);
    }

    public function testTheDetailEscapesTheCustomersName(): void
    {
        $body = (string) $this->getReq('presales/view/' . $this->register('60000'))->getBody();

        $this->assertStringContainsString('&lt;i&gt;x&lt;/i&gt;', $body);
        $this->assertStringNotContainsString('<i>x</i>', $body);
    }

    public function testTakingAnInstalmentLeadsToItsReceipt(): void
    {
        $id = $this->register('60000');

        $result = $this->postJson('presales/addPayment/' . $id, ['amount' => '50000', 'payment_type_code' => 'bank_transfer', 'reference_code' => 'TRX-1']);

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertSame('110000.00', model(Presale_payment::class)->get_paid($id));
        $this->assertMatchesRegularExpression('#presales/paymentReceipt/\d+#', $result['receipt_url']);
    }

    public function testAnInstalmentAboveTheBalanceIsRefused(): void
    {
        $id = $this->register('60000');

        $result = $this->postJson('presales/addPayment/' . $id, ['amount' => '130000', 'payment_type_code' => 'cash']);

        $this->assertFalse($result['success']);
        $this->assertSame(esc(lang('Presales.payment_exceeds_balance')), $result['message']);
    }

    public function testAPaymentTypeOutsideTheListIsRefused(): void
    {
        $id = $this->register('60000');

        $result = $this->postJson('presales/addPayment/' . $id, ['amount' => '1000', 'payment_type_code' => 'due']);

        $this->assertFalse($result['success']);
        $this->assertSame(esc(lang('Presales.payment_type_invalid')), $result['message']);
    }

    // ---------------------------------------------------------------------------------------------
    // Documents
    // ---------------------------------------------------------------------------------------------

    /**
     * The conditions are the business's text, printed as text: line breaks kept, markup shown, never run.
     */
    public function testTheDocumentPrintsTheBusinessConditionsAsText(): void
    {
        $this->setConfig('presales_terms', "<b>Uno</b>\nDos");

        $id   = $this->register('60000');
        $body = (string) $this->getReq('presales/receipt/' . $id)->getBody();

        $this->assertStringContainsString(model(Presale::class)->number($id), $body);
        $this->assertStringContainsString('&lt;b&gt;Uno&lt;/b&gt;<br', $body);
        $this->assertStringNotContainsString('<b>Uno</b>', $body);
        $this->assertStringContainsString(to_currency('120000'), $body);
    }

    /**
     * The instalment receipt is as of that payment: a later payment does not change what it says.
     */
    public function testThePaymentReceiptShowsWhatWasPaidUpToThatPayment(): void
    {
        $id     = $this->register('60000');
        $first  = model(Presale::class)->add_payment($id, 'cash', '20000', 1);
        $second = model(Presale::class)->add_payment($id, 'cash', '30000', 1);

        $this->assertIsInt($first);
        $this->assertIsInt($second);

        $body = (string) $this->getReq('presales/paymentReceipt/' . $first)->getBody();

        $this->assertStringContainsString(to_currency('20000'), $body);
        $this->assertStringContainsString(to_currency('80000'), $body);   // accumulated: 60,000 + 20,000
        $this->assertStringContainsString(to_currency('100000'), $body);  // balance after it
        $this->assertStringNotContainsString(to_currency('50000'), $body);
    }

    public function testAMissingPresaleIsNotFound(): void
    {
        $this->getReq('presales/receipt/999999999')->assertStatus(404);
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------

    /**
     * A complete registration form: 2 units at 90,000, plan 60,000 today + 120,000 in a month, and the
     * initial 60,000 in cash.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'campaign_id'      => (string) $this->campaign_id,
            'customer_id'      => (string) $this->customer_id,
            'delivery_date_id' => (string) $this->date_id,
            'lines'            => [['item_id' => (string) $this->item_id, 'quantity' => '2']],
            'installments'     => [
                ['due_date' => $this->typed(0), 'amount' => '60000'],
                ['due_date' => $this->typed(30), 'amount' => '120000'],
            ],
            'payment_type_code' => 'cash',
            'payment_amount'    => '60000',
            'reference_code'    => '',
            'comment'           => '',
        ], $overrides);
    }

    /**
     * A presale of 2 units (180,000) registered through the model, with $initial paid now.
     *
     * @param list<array{due_date: string, amount: string}>|null $plan
     */
    private function register(string $initial, ?array $plan = null): int
    {
        $plan ??= $initial === '180000'
            ? [['due_date' => $this->day(0), 'amount' => '180000']]
            : [['due_date' => $this->day(0), 'amount' => $initial], ['due_date' => $this->day(30), 'amount' => bcsub('180000', $initial, 0)]];

        $id = model(Presale::class)->create([
            'campaign_id'      => $this->campaign_id,
            'customer_id'      => $this->customer_id,
            'delivery_date_id' => $this->date_id,
            'location_id'      => 1,
            'lines'            => [['item_id' => $this->item_id, 'quantity' => '2']],
            'installments'     => $plan,
            'payment'          => ['payment_type_code' => 'cash', 'amount' => $initial],
        ], 1, date('Y-m-d'));

        $this->assertIsInt($id, is_string($id) ? $id : '');

        return $id;
    }

    /**
     * @return list<int> the ids the list returns for this campaign, in its default order
     */
    private function listedIds(array $query): array
    {
        return array_map('intval', array_column($this->listedRows($query), 'presale_id'));
    }

    private function listedRows(array $query): array
    {
        $query += ['campaign_id' => $this->campaign_id, 'limit' => 50, 'offset' => 0];

        $response = $this->getReq('presales/search?' . http_build_query($query));
        $response->assertStatus(200);

        return json_decode((string) $response->getJSON(), true)['rows'];
    }

    /**
     * Y-m-d, $offset days from today.
     */
    private function day(int $offset): string
    {
        return date('Y-m-d', strtotime(sprintf('%+d days', $offset)));
    }

    /**
     * A date as the cashier types it, in the business's format.
     */
    private function typed(int $offset): string
    {
        return date(config(OSPOS::class)->settings['dateformat'], strtotime(sprintf('%+d days', $offset)));
    }

    private function getReq(string $path): TestResponse
    {
        $_SESSION = ['person_id' => 1, 'menu_group' => 'home'];
        $this->withSession($_SESSION);

        return $this->get($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function postJson(string $path, array $params): array
    {
        $_SESSION = ['person_id' => 1, 'menu_group' => 'home'];
        $this->withSession($_SESSION);

        $response = $this->post($path, $params);
        $response->assertStatus(200);

        return json_decode((string) $response->getJSON(), true);
    }

    private function grant(string $permission): void
    {
        $exists = $this->db->table('grants')->where('permission_id', $permission)->where('person_id', 1)->countAllResults() > 0;

        if (! $exists) {
            $this->db->table('grants')->insert(['permission_id' => $permission, 'person_id' => 1, 'menu_group' => 'home']);
            $this->grantedHere[] = $permission;
        }
    }

    private function setConfig(string $key, string $value): void
    {
        $this->db->table('app_config')->replace(['key' => $key, 'value' => $value]);
        config(OSPOS::class)->update_settings();
    }

    private function makeCustomer(string $first, string $last, string $phone): int
    {
        $this->db->table('people')->insert([
            'first_name'   => $first,
            'last_name'    => $last,
            'phone_number' => $phone,
            'email'        => '',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
        ]);

        $person_id = (int) $this->db->insertID();

        $this->db->table('customers')->insert(['person_id' => $person_id, 'taxable' => 1, 'deleted' => 0, 'employee_id' => 1]);

        return $person_id;
    }

    private function openShift(): int
    {
        $this->db->table('cash_up')->insert([
            'status'               => 'open',
            'open_date'            => '2099-01-01 08:00:00',
            'close_date'           => null,
            'open_amount_cash'     => '0.00',
            'transfer_amount_cash' => '0.00',
            'note'                 => 0,
            'closed_amount_cash'   => '0.00',
            'closed_amount_card'   => '0.00',
            'closed_amount_check'  => '0.00',
            'closed_amount_due'    => '0.00',
            'closed_amount_total'  => '0.00',
            'description'          => 'PresalesControllerTest fixture',
            'open_employee_id'     => 1,
            'close_employee_id'    => 1,
            'deleted'              => 0,
        ]);

        return (int) $this->db->insertID();
    }

    private function makeItem(string $name, string $price): int
    {
        $this->db->table('items')->insert([
            'name'                  => $name,
            'category'              => 'Test',
            'item_number'           => null,
            'description'           => '',
            'cost_price'            => '0.00',
            'unit_price'            => $price,
            'unit_of_measure'       => Item::UNIT_OF_MEASURE_UNIT,
            'reorder_level'         => '0',
            'receiving_quantity'    => '1',
            'allow_alt_description' => 0,
            'is_serialized'         => 0,
        ]);

        return (int) $this->db->insertID();
    }
}
