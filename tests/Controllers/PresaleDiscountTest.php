<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Libraries\Presale_register;
use App\Models\Item;
use App\Models\Presale;
use App\Models\Presale_campaign;
use App\Models\Presale_event;
use App\Models\Presale_report;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\OSPOS;

/**
 * The cashier's discount on a whole presale and the brand report (docs/Funcional/venta-anticipada.md
 * §4.16, owner's request of 2026-10-27): 0 to 40% in steps of 5, on top of the campaign price, marked
 * when the brand pays it back; changed afterwards only by an administrator; and what the brand owes.
 *
 * SHARED DATABASE: own customer, item (100,000, no tax: the same fixture PresaleNotificationsTest
 * proves the register charges at 100,000), open shift and campaign at 0%, removed by their ids. Person 1
 * gets `presales` and `presales_manage` only if it did not have them; a test that takes
 * presales_manage away puts it back. presales_enable is restored.
 *
 * @internal
 */
final class PresaleDiscountTest extends CIUnitTestCase
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
    private ?string $enableBefore = null;

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

        $row                = $this->db->table('app_config')->where('key', 'presales_enable')->get()->getRow();
        $this->enableBefore = $row === null ? null : (string) $row->value;
        $this->db->table('app_config')->replace(['key' => 'presales_enable', 'value' => '1']);
        config(OSPOS::class)->update_settings();

        foreach (['presales', 'presales_manage'] as $permission) {
            if ($this->db->table('grants')->where(['permission_id' => $permission, 'person_id' => 1])->countAllResults() === 0) {
                $this->db->table('grants')->insert(['permission_id' => $permission, 'person_id' => 1, 'menu_group' => $permission === 'presales' ? 'home' : '--']);
                $this->grantedHere[] = $permission;
            }
        }

        $this->customer_id = $this->makeCustomer();
        $this->cashup_id   = $this->openShift();
        $this->item_id     = $this->makeItem();

        $campaigns = model(Presale_campaign::class);
        $campaign  = $campaigns->save_campaign([
            'name'                => 'PRDISCOUNT-TEST Navidad',
            'sale_starts'         => $this->day(-5),
            'sale_ends'           => $this->day(40),
            'discount_percent'    => '0',
            'min_initial_percent' => '0',
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
            foreach (['presale_notifications', 'presale_events', 'presale_payments', 'presale_installments', 'presale_items', 'presales'] as $table) {
                $this->db->table($table)->whereIn('presale_id', $ids)->delete();
            }
        }

        $this->db->table('presale_campaign_items')->where('campaign_id', $this->campaign_id ?? 0)->delete();
        $this->db->table('presale_campaign_dates')->where('campaign_id', $this->campaign_id ?? 0)->delete();
        $this->db->table('presale_campaigns')->where('campaign_id', $this->campaign_id ?? 0)->delete();
        $this->db->table('items')->where('item_id', $this->item_id ?? 0)->delete();
        $this->db->table('cash_up')->where('cashup_id', $this->cashup_id ?? 0)->delete();
        $this->db->table('customers')->where('person_id', $this->customer_id ?? 0)->delete();
        $this->db->table('people')->where('person_id', $this->customer_id ?? 0)->delete();

        foreach ($this->grantedHere as $permission) {
            $this->db->table('grants')->where(['permission_id' => $permission, 'person_id' => 1])->delete();
        }

        foreach ($this->revokedHere as $grant) {
            $this->db->table('grants')->replace($grant);
        }

        if ($this->enableBefore === null) {
            $this->db->table('app_config')->where('key', 'presales_enable')->delete();
        } else {
            $this->db->table('app_config')->replace(['key' => 'presales_enable', 'value' => $this->enableBefore]);
        }

        config(OSPOS::class)->update_settings();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    // Registering with a discount
    // ---------------------------------------------------------------------------------------------

    public function testOnlyZeroToFortyInStepsOfFiveAreDiscounts(): void
    {
        $this->assertSame([0, 5, 10, 15, 20, 25, 30, 35, 40], Presale::discount_options());
        $this->assertSame('5.00', Presale::discount_or_null('5'));
        $this->assertSame('0.00', Presale::discount_or_null(''));
        $this->assertSame('40.00', Presale::discount_or_null('40.00'));

        foreach (['3', '45', '-5', '7.5', 'cinco'] as $refused) {
            $this->assertNull(Presale::discount_or_null($refused), $refused);
        }
    }

    public function testTheDiscountComesOffTheTotalAndIsKeptOnEveryLine(): void
    {
        $id      = $this->register('5', true, [['due_date' => $this->day(0), 'amount' => '30000'], ['due_date' => $this->day(30), 'amount' => '65000']], '30000');
        $presale = model(Presale::class)->get_info($id);

        $this->assertSame('95000.00', $presale['total']);
        $this->assertSame('100000.00', $presale['gross_total']);
        $this->assertSame('5.00', $presale['discount_percent']);
        $this->assertSame(1, (int) $presale['discount_reimbursable']);
        $this->assertSame('5.00', (string) $this->db->table('presale_items')->where('presale_id', $id)->get()->getRow()->discount);
    }

    /**
     * What makes the delivery right: the register, reading the stored lines, charges the agreed total.
     */
    public function testTheRegisterChargesTheDiscountedTotalFromTheStoredLines(): void
    {
        $id     = $this->register('15', false, [['due_date' => $this->day(0), 'amount' => '85000']], '85000');
        $charge = (new Presale_register())->charge_for(model(Presale::class)->get_lines($id), $this->customer_id);

        $this->assertSame(model(Presale::class)->get_info($id)['total'], $charge['charge']);
    }

    public function testWithoutADiscountNothingIsReimbursableWhateverTheBoxSays(): void
    {
        $id = $this->register('0', true, [['due_date' => $this->day(0), 'amount' => '100000']], '100000');

        $this->assertSame(0, (int) model(Presale::class)->get_info($id)['discount_reimbursable']);
    }

    public function testADiscountOutsideTheListIsRefused(): void
    {
        $result = model(Presale::class)->create($this->input('12', false, [['due_date' => $this->day(0), 'amount' => '88000']], '88000'), 1, date('Y-m-d'));

        $this->assertSame('Presales.discount_invalid', $result);
    }

    // ---------------------------------------------------------------------------------------------
    // Changing it afterwards (administrator only)
    // ---------------------------------------------------------------------------------------------

    public function testALargerDiscountComesOffTheLastInstalment(): void
    {
        $id = $this->register('0', false, [['due_date' => $this->day(0), 'amount' => '30000'], ['due_date' => $this->day(30), 'amount' => '70000']], '30000');

        $this->assertTrue(model(Presale::class)->change_discount($id, '10', true, 1));

        $presale = model(Presale::class)->get_info($id);
        $this->assertSame('90000.00', $presale['total']);
        $this->assertSame(1, (int) $presale['discount_reimbursable']);
        $this->assertSame(['30000.00', '60000.00'], array_column(model(Presale::class)->get_installments($id), 'amount'));
        $this->assertContains(Presale_event::DISCOUNT_CHANGED, array_column(model(Presale_event::class)->get_for($id), 'event_type'));
    }

    public function testRemovingTheDiscountPutsTheDifferenceOnTheLastInstalment(): void
    {
        $id = $this->register('10', false, [['due_date' => $this->day(0), 'amount' => '30000'], ['due_date' => $this->day(30), 'amount' => '60000']], '30000');

        $this->assertTrue(model(Presale::class)->change_discount($id, '0', true, 1));

        $presale = model(Presale::class)->get_info($id);
        $this->assertSame('100000.00', $presale['total']);
        $this->assertSame(0, (int) $presale['discount_reimbursable'], 'No discount, nothing to pay back.');
        $this->assertSame(['30000.00', '70000.00'], array_column(model(Presale::class)->get_installments($id), 'amount'));
    }

    public function testAnInstalmentLeftAtZeroIsRemoved(): void
    {
        $id = $this->register('0', false, [['due_date' => $this->day(0), 'amount' => '60000'], ['due_date' => $this->day(30), 'amount' => '40000']], '60000');

        $this->assertTrue(model(Presale::class)->change_discount($id, '40', false, 1));

        $this->assertSame(['60000.00'], array_column(model(Presale::class)->get_installments($id), 'amount'));
        $this->assertSame('60000.00', model(Presale::class)->get_info($id)['total']);
    }

    public function testTheTotalCannotFallBelowWhatWasPaid(): void
    {
        $id = $this->register('0', false, [['due_date' => $this->day(0), 'amount' => '100000']], '100000');

        $this->assertSame('Presales.discount_below_paid', model(Presale::class)->change_discount($id, '5', false, 1));
        $this->assertSame('100000.00', model(Presale::class)->get_info($id)['total']);
    }

    public function testOnlyAnAdministratorChangesTheDiscount(): void
    {
        $id = $this->register('0', false, [['due_date' => $this->day(0), 'amount' => '30000'], ['due_date' => $this->day(30), 'amount' => '70000']], '30000');

        $this->revoke('presales_manage');

        $refused = $this->postReq('presales/' . $id . '/changeDiscount', ['discount_percent' => '20']);
        $refused->assertStatus(403);
        $this->assertSame('100000.00', model(Presale::class)->get_info($id)['total']);

        $this->restoreRevoked();

        $json = $this->json($this->postReq('presales/' . $id . '/changeDiscount', ['discount_percent' => '20', 'discount_reimbursable' => '1']));
        $this->assertTrue($json['success']);
        $this->assertSame('80000.00', model(Presale::class)->get_info($id)['total']);
    }

    // ---------------------------------------------------------------------------------------------
    // The screens
    // ---------------------------------------------------------------------------------------------

    public function testThePreviewShowsTheDiscountedTotalAndTheDiscount(): void
    {
        $json = $this->json($this->postReq('presales/preview', [
            // Strings, as a browser posts them: the request layer checks the encoding of every value.
            'campaign_id'      => (string) $this->campaign_id,
            'customer_id'      => (string) $this->customer_id,
            'lines'            => [['item_id' => (string) $this->item_id, 'quantity' => '1']],
            'discount_percent' => '25',
            'installments'     => [['due_date' => to_date(strtotime($this->day(0))), 'amount' => '']],
        ]));

        $this->assertTrue($json['success']);
        $this->assertSame(to_currency('75000'), $json['total']);
        $this->assertSame(esc(lang('Presales.discount_amount', [to_currency('25000')])), $json['discount']);
        $this->assertSame(to_currency('100000'), $json['gross'], 'The subtotal before the discount, shown above it.');
        $this->assertSame('-' . to_currency('25000'), $json['discount_cell'], 'The discount as a negative amount in the column.');
        $this->assertNull($json['plan'], 'A row with a date and no amount yet is not an error.');
    }

    // ---------------------------------------------------------------------------------------------
    // What the brand owes
    // ---------------------------------------------------------------------------------------------

    public function testTheBrandReportCountsReimbursableOpenPresalesAndNotCanceledOnes(): void
    {
        $owed     = $this->register('10', true, [['due_date' => $this->day(0), 'amount' => '90000']], '90000');
        $canceled = $this->register('20', true, [['due_date' => $this->day(0), 'amount' => '80000']], '80000');
        $this->register('5', false, [['due_date' => $this->day(0), 'amount' => '95000']], '95000');

        $this->assertTrue(model(Presale::class)->cancel($canceled, 1, 'Prueba', '0'));

        $report = model(Presale_report::class)->brand_discounts($this->campaign_id);

        $this->assertSame([$owed], array_map('intval', array_column($report['rows'], 'presale_id')));
        $this->assertSame('10000.00', $report['totals']['discount']);
        $this->assertSame('10000.00', $report['totals']['open']);
        $this->assertSame('0.00', $report['totals']['delivered']);
    }

    public function testTheBrandReportNeedsAnAdministrator(): void
    {
        $this->getReq('presales/brandDiscounts')->assertStatus(200);

        $this->revoke('presales_manage');

        $this->getReq('presales/brandDiscounts')->assertRedirectTo(site_url('no_access/presales/presales_manage'));
    }

    /**
     * @param list<array{due_date: string, amount: string}> $plan
     */
    private function register(string $discount, bool $reimbursable, array $plan, string $initial): int
    {
        $id = model(Presale::class)->create($this->input($discount, $reimbursable, $plan, $initial), 1, date('Y-m-d'));

        $this->assertIsInt($id, is_string($id) ? $id : '');

        return $id;
    }

    /**
     * @param list<array{due_date: string, amount: string}> $plan
     *
     * @return array<string, mixed>
     */
    private function input(string $discount, bool $reimbursable, array $plan, string $initial): array
    {
        return [
            'campaign_id'           => $this->campaign_id,
            'customer_id'           => $this->customer_id,
            'delivery_date_id'      => $this->date_id,
            'location_id'           => 1,
            'lines'                 => [['item_id' => $this->item_id, 'quantity' => '1']],
            'installments'          => $plan,
            'payment'               => ['payment_type_code' => 'cash', 'amount' => $initial],
            'discount_percent'      => $discount,
            'discount_reimbursable' => $reimbursable,
        ];
    }

    private function revoke(string $permission): void
    {
        $row = $this->db->table('grants')->where(['permission_id' => $permission, 'person_id' => 1])->get()->getRowArray();

        if ($row === null) {
            return;
        }

        if (in_array($permission, $this->grantedHere, true)) {
            $this->grantedHere = array_values(array_diff($this->grantedHere, [$permission]));
        } else {
            $this->revokedHere[] = $row;
        }

        $this->db->table('grants')->where(['permission_id' => $permission, 'person_id' => 1])->delete();
    }

    private function restoreRevoked(): void
    {
        foreach ($this->revokedHere as $grant) {
            $this->db->table('grants')->replace($grant);
        }

        if ($this->revokedHere === []) {
            $this->db->table('grants')->insert(['permission_id' => 'presales_manage', 'person_id' => 1, 'menu_group' => '--']);
            $this->grantedHere[] = 'presales_manage';
        }

        $this->revokedHere = [];
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

    private function makeCustomer(): int
    {
        $this->db->table('people')->insert([
            'first_name'   => 'Ana',
            'last_name'    => 'PRDISCOUNT-TEST',
            'phone_number' => '3005550103',
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
            'description'          => 'PresaleDiscountTest fixture',
            'open_employee_id'     => 1,
            'close_employee_id'    => 1,
            'deleted'              => 0,
        ]);

        return (int) $this->db->insertID();
    }

    private function makeItem(): int
    {
        $this->db->table('items')->insert([
            'name'                  => 'PRDISCOUNT-TEST pavo',
            'category'              => 'Test',
            'item_number'           => null,
            'description'           => '',
            'cost_price'            => '0.00',
            'unit_price'            => '100000.00',
            'unit_of_measure'       => Item::UNIT_OF_MEASURE_UNIT,
            'reorder_level'         => '0',
            'receiving_quantity'    => '1',
            'allow_alt_description' => 0,
            'is_serialized'         => 0,
        ]);

        return (int) $this->db->insertID();
    }
}
