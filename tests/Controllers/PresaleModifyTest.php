<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Libraries\Presale_register;
use App\Models\Item;
use App\Models\Presale;
use App\Models\Presale_campaign;
use App\Models\Presale_event;
use App\Models\Presale_notification;
use App\Models\Presale_payment;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\OSPOS;

/**
 * Changing an open presale (docs/Funcional/venta-anticipada.md §4.17, owner's decisions of
 * 2026-11-04): products and quantities, customer, delivery date and plan, by any cashier of the module,
 * always with a reason. A product it already had keeps its agreed price, a new one takes the
 * campaign's of today; a total below what was paid refunds the difference.
 *
 * SHARED DATABASE: own customers, two items (100,000 and 60,000, no tax), open shift and campaign at 0%,
 * removed by their ids. Person 1 gets `presales` only if it did not have it, and loses
 * `presales_manage` for the test that proves a plain cashier may change a presale; both are put back.
 * presales_enable and presales_notify_email are restored.
 *
 * @internal
 */
final class PresaleModifyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private const TOUCHED_KEYS = ['presales_enable', 'presales_notify_email'];

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';
    private int $customer_id;
    private int $other_customer_id;
    private int $cashup_id;
    private int $campaign_id;
    private int $date_id;
    private int $later_date_id;
    private int $pavo_id;
    private int $pernil_id;

    /**
     * @var array<string, string|null>
     */
    private array $keysBefore = [];

    /**
     * @var list<string>
     */
    private array $grantedHere = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $revokedHere = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::TOUCHED_KEYS as $key) {
            $row                    = $this->db->table('app_config')->where('key', $key)->get()->getRow();
            $this->keysBefore[$key] = $row === null ? null : (string) $row->value;
        }

        $this->db->table('app_config')->replace(['key' => 'presales_enable', 'value' => '1']);
        config(OSPOS::class)->update_settings();

        if ($this->db->table('grants')->where(['permission_id' => 'presales', 'person_id' => 1])->countAllResults() === 0) {
            $this->db->table('grants')->insert(['permission_id' => 'presales', 'person_id' => 1, 'menu_group' => 'home']);
            $this->grantedHere[] = 'presales';
        }

        $this->customer_id       = $this->makeCustomer('Ana');
        $this->other_customer_id = $this->makeCustomer('Luis');
        $this->cashup_id         = $this->openShift();
        $this->pavo_id           = $this->makeItem('PRMODIFY-TEST pavo', '100000.00');
        $this->pernil_id         = $this->makeItem('PRMODIFY-TEST pernil', '60000.00');

        $campaigns = model(Presale_campaign::class);
        $campaign  = $campaigns->save_campaign([
            'name'                => 'PRMODIFY-TEST Navidad',
            'sale_starts'         => $this->day(-5),
            'sale_ends'           => $this->day(40),
            'discount_percent'    => '0',
            'min_initial_percent' => '0',
            'active'              => 1,
        ], NEW_ENTRY, 1);

        $this->assertIsInt($campaign);
        $this->campaign_id = $campaign;

        $this->assertTrue($campaigns->add_date($this->campaign_id, $this->day(60)));
        $this->assertTrue($campaigns->add_date($this->campaign_id, $this->day(70)));
        $dates               = $campaigns->get_dates($this->campaign_id);
        $this->date_id       = (int) $dates[0]['date_id'];
        $this->later_date_id = (int) $dates[1]['date_id'];

        $this->assertTrue($campaigns->add_item($this->campaign_id, $this->pavo_id));
        $this->assertTrue($campaigns->add_item($this->campaign_id, $this->pernil_id));
    }

    protected function tearDown(): void
    {
        $ids = array_column($this->db->table('presales')->select('presale_id')->where('campaign_id', $this->campaign_id ?? 0)->get()->getResultArray(), 'presale_id');

        if ($ids !== []) {
            foreach (['presale_notifications', 'presale_events', 'presale_payments', 'presale_installments', 'presale_items', 'presales'] as $table) {
                $this->db->table($table)->whereIn('presale_id', $ids)->delete();
            }
        }

        $this->db->table('presale_campaign_items')->where('campaign_id', $this->campaign_id ?? 0)->delete();
        $this->db->table('presale_campaign_dates')->where('campaign_id', $this->campaign_id ?? 0)->delete();
        $this->db->table('presale_campaigns')->where('campaign_id', $this->campaign_id ?? 0)->delete();
        $this->db->table('items')->whereIn('item_id', [$this->pavo_id ?? 0, $this->pernil_id ?? 0])->delete();
        $this->db->table('cash_up')->where('cashup_id', $this->cashup_id ?? 0)->delete();

        foreach ([$this->customer_id ?? 0, $this->other_customer_id ?? 0] as $person_id) {
            $this->db->table('customers')->where('person_id', $person_id)->delete();
            $this->db->table('people')->where('person_id', $person_id)->delete();
        }

        foreach ($this->grantedHere as $permission) {
            $this->db->table('grants')->where(['permission_id' => $permission, 'person_id' => 1])->delete();
        }

        foreach ($this->revokedHere as $grant) {
            $this->db->table('grants')->replace($grant);
        }

        foreach ($this->keysBefore as $key => $value) {
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
    // Prices
    // ---------------------------------------------------------------------------------------------

    public function testAProductKeepsItsAgreedPriceAndANewOneTakesTodaysCampaignPrice(): void
    {
        $id = $this->registerPavo();

        // The campaign raises the pavo after the presale was agreed, and the pernil too.
        $this->assertTrue(model(Presale_campaign::class)->update_item($this->campaign_id, $this->pavo_id, '120000', ''));
        $this->assertTrue(model(Presale_campaign::class)->update_item($this->campaign_id, $this->pernil_id, '65000', ''));

        $result = model(Presale::class)->modify($id, $this->change([
            'lines'        => [['item_id' => $this->pavo_id, 'quantity' => '1'], ['item_id' => $this->pernil_id, 'quantity' => '2']],
            'installments' => [['due_date' => $this->day(0), 'amount' => '40000'], ['due_date' => $this->day(30), 'amount' => '190000']],
        ]), 1, date('Y-m-d'));

        $this->assertIsInt($result, is_string($result) ? $result : '');

        $presale = model(Presale::class)->get_info($id);
        $this->assertSame('230000.00', $presale['total']);
        $this->assertSame('230000.00', $presale['gross_total']);
        $this->assertSame(['100000.00', '65000.00'], array_column(model(Presale::class)->get_lines($id), 'unit_price'));
        $this->assertSame(['40000.00', '190000.00'], array_column(model(Presale::class)->get_installments($id), 'amount'));
    }

    public function testTheSellerDiscountGoesOnTheNewLinesToo(): void
    {
        $id = $this->registerPavo('10', [['due_date' => $this->day(0), 'amount' => '40000'], ['due_date' => $this->day(30), 'amount' => '50000']]);

        $result = model(Presale::class)->modify($id, $this->change([
            'lines'        => [['item_id' => $this->pavo_id, 'quantity' => '1'], ['item_id' => $this->pernil_id, 'quantity' => '1']],
            'installments' => [['due_date' => $this->day(0), 'amount' => '40000'], ['due_date' => $this->day(30), 'amount' => '104000']],
        ]), 1, date('Y-m-d'));

        $this->assertIsInt($result, is_string($result) ? $result : '');

        $presale = model(Presale::class)->get_info($id);
        $this->assertSame('144000.00', $presale['total']);
        $this->assertSame('160000.00', $presale['gross_total']);
        $this->assertSame(['10.00', '10.00'], array_column(model(Presale::class)->get_lines($id), 'discount'));

        // What makes the delivery right: the register charges the stored lines the new total.
        $charge = (new Presale_register())->charge_for(model(Presale::class)->get_lines($id), $this->customer_id);
        $this->assertSame($presale['total'], $charge['charge']);
    }

    // ---------------------------------------------------------------------------------------------
    // Money
    // ---------------------------------------------------------------------------------------------

    public function testATotalBelowWhatWasPaidRefundsTheDifferenceInTheOpenShift(): void
    {
        $id = $this->registerPavo();
        $this->assertIsInt(model(Presale::class)->add_payment($id, 'cash', '60000', 1));

        $result = model(Presale::class)->modify($id, $this->change([
            'lines'        => [['item_id' => $this->pernil_id, 'quantity' => '1']],
            'installments' => [['due_date' => $this->day(0), 'amount' => '60000']],
            'refund'       => ['payment_type_code' => 'cash'],
        ]), 1, date('Y-m-d'));

        $this->assertIsInt($result, is_string($result) ? $result : '');

        $refunds = array_values(array_filter(model(Presale_payment::class)->get_for($id), static fn (array $row): bool => $row['kind'] === Presale_payment::KIND_REFUND));
        $this->assertCount(1, $refunds);
        $this->assertSame('40000.00', $refunds[0]['amount']);
        $this->assertSame($this->cashup_id, (int) $refunds[0]['cashup_id']);
        $this->assertSame(Presale::STATE_PAID, model(Presale::class)->get_summary($id, date('Y-m-d'))['state']);
    }

    public function testWithoutARefundTypeTheChangeIsRefusedAndNothingIsWritten(): void
    {
        $id = $this->registerPavo();
        $this->assertIsInt(model(Presale::class)->add_payment($id, 'cash', '60000', 1));

        $result = model(Presale::class)->modify($id, $this->change([
            'lines'        => [['item_id' => $this->pernil_id, 'quantity' => '1']],
            'installments' => [['due_date' => $this->day(0), 'amount' => '60000']],
        ]), 1, date('Y-m-d'));

        $this->assertSame('Presales.modify_refund_type_required', $result);
        $this->assertSame('100000.00', model(Presale::class)->get_info($id)['total']);
        $this->assertSame([$this->pavo_id], array_map('intval', array_column(model(Presale::class)->get_lines($id), 'item_id')));
    }

    // ---------------------------------------------------------------------------------------------
    // Rules
    // ---------------------------------------------------------------------------------------------

    public function testTheReasonIsRequired(): void
    {
        $id = $this->registerPavo();

        $this->assertSame('Presales.modify_reason_required', model(Presale::class)->modify($id, $this->change(['reason' => '  ']), 1, date('Y-m-d')));
    }

    public function testThePlanMustAddUpToTheNewTotal(): void
    {
        $id = $this->registerPavo();

        $result = model(Presale::class)->modify($id, $this->change([
            'lines' => [['item_id' => $this->pavo_id, 'quantity' => '2']],
        ]), 1, date('Y-m-d'));

        $this->assertSame('Presales.installments_must_add_up', $result);
    }

    public function testAnInstalmentDatedBeforeTodayMustAlreadyBePaid(): void
    {
        $id = $this->registerPavo();

        $unpaid = model(Presale::class)->modify($id, $this->change([
            'installments' => [['due_date' => $this->day(-1), 'amount' => '50000'], ['due_date' => $this->day(30), 'amount' => '50000']],
        ]), 1, date('Y-m-d'));

        $this->assertSame('Presales.modify_past_installment_unpaid', $unpaid);

        // What came in on the first day may keep a date that has gone.
        $paid = model(Presale::class)->modify($id, $this->change([
            'installments' => [['due_date' => $this->day(-1), 'amount' => '40000'], ['due_date' => $this->day(30), 'amount' => '60000']],
        ]), 1, date('Y-m-d'));

        $this->assertIsInt($paid, is_string($paid) ? $paid : '');
    }

    public function testTheCustomerAndTheDeliveryDateChange(): void
    {
        $id = $this->registerPavo();

        $result = model(Presale::class)->modify($id, $this->change([
            'customer_id'      => $this->other_customer_id,
            'delivery_date_id' => $this->later_date_id,
            'installments'     => [['due_date' => $this->day(0), 'amount' => '40000'], ['due_date' => $this->day(65), 'amount' => '60000']],
        ]), 1, date('Y-m-d'));

        $this->assertIsInt($result, is_string($result) ? $result : '');

        $presale = model(Presale::class)->get_info($id);
        $this->assertSame($this->other_customer_id, (int) $presale['customer_id']);
        $this->assertSame($this->day(70), $presale['delivery_date']);
    }

    public function testADeliveryDateThatHasGoneIsKeptOnlyIfItWasThePresalesOwn(): void
    {
        $id = $this->registerPavo();

        // A presale whose delivery day went by unpaid, as PV-000001 of the first business.
        $this->db->table('presale_campaign_dates')->insert(['campaign_id' => $this->campaign_id, 'delivery_date' => $this->day(-3)]);
        $gone_id = (int) $this->db->insertID();
        $this->db->table('presales')->where('presale_id', $id)->update(['delivery_date_id' => $gone_id, 'delivery_date' => $this->day(-3)]);
        $this->db->table('presale_installments')->where('presale_id', $id)->update(['due_date' => $this->day(-3)]);

        // Kept: the products change, and what is still owed moves to today.
        $kept = model(Presale::class)->modify($id, $this->change([
            'delivery_date_id' => $gone_id,
            'installments'     => [['due_date' => $this->day(-3), 'amount' => '40000'], ['due_date' => $this->day(0), 'amount' => '60000']],
        ]), 1, date('Y-m-d'));

        $this->assertIsInt($kept, is_string($kept) ? $kept : '');

        $other = $this->registerPavo();

        $this->assertSame('Presales.delivery_date_past', model(Presale::class)->modify($other, $this->change(['delivery_date_id' => $gone_id]), 1, date('Y-m-d')));
    }

    public function testADeliveredPresaleCannotBeChanged(): void
    {
        $id = $this->registerPavo();
        $this->db->table('presales')->where('presale_id', $id)->update(['status' => Presale::STATUS_DELIVERED]);

        $this->assertSame('Presales.not_open', model(Presale::class)->modify($id, $this->change(), 1, date('Y-m-d')));
    }

    // ---------------------------------------------------------------------------------------------
    // History and e-mail
    // ---------------------------------------------------------------------------------------------

    public function testTheChangeIsInTheHistoryWithItsReasonAndQueuesTheEmail(): void
    {
        $this->db->table('app_config')->replace(['key' => 'presales_notify_email', 'value' => '1']);
        config(OSPOS::class)->update_settings();

        $id       = $this->registerPavo();
        $event_id = model(Presale::class)->modify($id, $this->change([
            'lines'        => [['item_id' => $this->pernil_id, 'quantity' => '1']],
            'installments' => [['due_date' => $this->day(0), 'amount' => '40000'], ['due_date' => $this->day(30), 'amount' => '20000']],
        ]), 1, date('Y-m-d'));

        $this->assertIsInt($event_id, is_string($event_id) ? $event_id : '');

        $event = $this->db->table('presale_events')->where('event_id', $event_id)->get()->getRowArray();
        $this->assertSame(Presale_event::MODIFIED, $event['event_type']);
        $this->assertSame('El cliente cambió de producto', $event['reason']);

        $detail = json_decode((string) $event['detail'], true);
        $this->assertSame('100000.00', $detail['before']['total']);
        $this->assertSame('60000.00', $detail['after']['total']);

        $this->assertNotNull(model(Presale_notification::class)->status_of($id, Presale_notification::KIND_MODIFIED, $event_id));
    }

    // ---------------------------------------------------------------------------------------------
    // The screens
    // ---------------------------------------------------------------------------------------------

    public function testAnyCashierOpensTheFormFilledIn(): void
    {
        $id = $this->registerPavo();
        $this->revoke('presales_manage');

        $response = $this->getReq('presales/edit/' . $id);

        $response->assertOK();
        $response->assertSee(lang('Presales.modify_title', [model(Presale::class)->number($id)]));
        $response->assertSee('name="reason"', null);
        $response->assertSee('presales/' . $id . '/update', null);
    }

    public function testThePreviewOfAChangeSaysWhatToRefund(): void
    {
        $id = $this->registerPavo();
        $this->assertIsInt(model(Presale::class)->add_payment($id, 'cash', '60000', 1));

        $json = $this->json($this->postReq('presales/preview', [
            'presale_id'  => (string) $id,
            'campaign_id' => (string) $this->campaign_id,
            'customer_id' => (string) $this->customer_id,
            'lines'       => [['item_id' => (string) $this->pernil_id, 'quantity' => '1']],
        ]));

        $this->assertTrue($json['success']);
        $this->assertTrue($json['has_refund']);
        $this->assertSame(to_currency('40000'), $json['refund']);
    }

    public function testTheChangeIsSavedFromTheForm(): void
    {
        $id = $this->registerPavo();
        $this->revoke('presales_manage');

        $json = $this->json($this->postReq('presales/' . $id . '/update', [
            'customer_id'      => (string) $this->customer_id,
            'delivery_date_id' => (string) $this->date_id,
            'reason'           => 'Pidió pernil en vez de pavo',
            'lines'            => [['item_id' => (string) $this->pernil_id, 'quantity' => '1']],
            'installments'     => [
                ['due_date' => to_date(strtotime($this->day(0))), 'amount' => '40000'],
                ['due_date' => to_date(strtotime($this->day(30))), 'amount' => '20000'],
            ],
        ]));

        $this->assertTrue($json['success'], $json['message'] ?? '');
        $this->assertSame('60000.00', model(Presale::class)->get_info($id)['total']);
    }

    // ---------------------------------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------------------------------

    /**
     * A pavo at 100,000: 40,000 paid today and 60,000 in 30 days, unless told otherwise.
     *
     * @param list<array{due_date: string, amount: string}>|null $plan
     */
    private function registerPavo(string $discount = '0', ?array $plan = null): int
    {
        $id = model(Presale::class)->create([
            'campaign_id'      => $this->campaign_id,
            'customer_id'      => $this->customer_id,
            'delivery_date_id' => $this->date_id,
            'location_id'      => 1,
            'lines'            => [['item_id' => $this->pavo_id, 'quantity' => '1']],
            'installments'     => $plan ?? [['due_date' => $this->day(0), 'amount' => '40000'], ['due_date' => $this->day(30), 'amount' => '60000']],
            'payment'          => ['payment_type_code' => 'cash', 'amount' => '40000'],
            'discount_percent' => $discount,
        ], 1, date('Y-m-d'));

        $this->assertIsInt($id, is_string($id) ? $id : '');

        return $id;
    }

    /**
     * A change that leaves the presale as registerPavo() made it, with what is given on top.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function change(array $overrides = []): array
    {
        return $overrides + [
            'customer_id'      => $this->customer_id,
            'delivery_date_id' => $this->date_id,
            'reason'           => 'El cliente cambió de producto',
            'lines'            => [['item_id' => $this->pavo_id, 'quantity' => '1']],
            'installments'     => [['due_date' => $this->day(0), 'amount' => '40000'], ['due_date' => $this->day(30), 'amount' => '60000']],
        ];
    }

    private function revoke(string $permission): void
    {
        $row = $this->db->table('grants')->where(['permission_id' => $permission, 'person_id' => 1])->get()->getRowArray();

        if ($row === null) {
            return;
        }

        $this->revokedHere[] = $row;
        $this->db->table('grants')->where(['permission_id' => $permission, 'person_id' => 1])->delete();
    }

    private function day(int $offset): string
    {
        return date('Y-m-d', strtotime(sprintf('%+d days', $offset)));
    }

    private function getReq(string $path): TestResponse
    {
        $_SESSION = ['person_id' => 1, 'menu_group' => 'home'];
        $this->withSession($_SESSION);

        return $this->get($path);
    }

    private function postReq(string $path, array $params): TestResponse
    {
        $_SESSION = ['person_id' => 1, 'menu_group' => 'home'];
        $this->withSession($_SESSION);

        return $this->post($path, $params);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(TestResponse $response): array
    {
        return json_decode((string) $response->getJSON(), true);
    }

    private function makeCustomer(string $first_name): int
    {
        $this->db->table('people')->insert([
            'first_name'   => $first_name,
            'last_name'    => 'PRMODIFY-TEST',
            'phone_number' => '3005550104',
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
            'description'          => 'PresaleModifyTest fixture',
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
