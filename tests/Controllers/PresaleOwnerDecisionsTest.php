<?php

declare(strict_types=1);

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
 * The owner's decisions of 2026-10-07 on presales, end to end through the REAL register
 * (docs/Funcional/venta-anticipada.md §6, docs/Tecnico/venta-anticipada.md §7.8):
 *
 * 1. The presale's total is exactly what the register charges at delivery for the same lines and
 *    customer: taxes added or included, the customer's tax situation, and the register's rounding of
 *    the total (not of each line). Proved by registering through Presale::create() and delivering
 *    through Sales: the delivery completes with the `presale` payment alone, no difference to charge.
 * 3. A lighter weight that hands back more than presales_weight_refund_limit % of the total needs
 *    presales_manage; who authorised it is on the quantity_adjusted event.
 * 4. No delivery without an open shift, neither sending it to the register nor completing it.
 *
 * (2, kits at the full campaign price, is in tests/Models/PresaleKitTest.php.)
 *
 * SHARED DATABASE: everything this file creates is removed by its own ids; every app_config key it
 * touches is put back as it was (TOUCHED_KEYS); grants on person 1 are put back as they were; the
 * shifts other files left open and a test closes are reopened in tearDown.
 *
 * Sessions are re-armed from the live $_SESSION before each request, as in PresaleDeliveryRegisterTest.
 *
 * @internal
 */
final class PresaleOwnerDecisionsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private const TOUCHED_KEYS = [
        'presales_enable',
        'dinner_table_enable',
        'tax_included',
        'currency_decimals',
        'tax_decimals',
        'use_destination_based_tax',
        'presales_weight_refund_limit',
        'presales_terms',
    ];

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    /**
     * @var array<string, string|null>
     */
    private array $configBefore = [];

    /**
     * @var list<string>
     */
    private array $grantedHere = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $revokedHere = [];

    /**
     * @var list<int> shifts of other files this test closed, reopened in tearDown
     */
    private array $closedHere = [];

    /**
     * @var list<int>
     */
    private array $items = [];

    /**
     * @var list<int>
     */
    private array $customers = [];

    private int $customerId;
    private int $cashupId;
    private int $campaignId = 0;
    private int $dateId     = 0;

    protected function setUp(): void
    {
        parent::setUp();

        db_connect()->resetDataCache();

        foreach (self::TOUCHED_KEYS as $key) {
            $row                      = $this->db->table('app_config')->where('key', $key)->get()->getRow();
            $this->configBefore[$key] = $row === null ? null : (string) $row->value;
        }

        $this->setConfig([
            'presales_enable'              => '1',
            'dinner_table_enable'          => '0',
            'tax_included'                 => '0',
            'currency_decimals'            => '2',
            'tax_decimals'                 => '2',
            'use_destination_based_tax'    => '0',
            'presales_weight_refund_limit' => '15',
        ]);

        $this->grant('presales');

        $this->customerId = $this->makeCustomer('Muñoz PRDEC-TEST', true);
        $this->cashupId   = $this->openShift();

        $this->loginAsCashier();
    }

    protected function tearDown(): void
    {
        $presales = array_column($this->db->table('presales')->select('presale_id')->whereIn('customer_id', $this->customers === [] ? [0] : $this->customers)->get()->getResultArray(), 'presale_id');

        if ($presales !== []) {
            foreach (['presale_events', 'presale_payments', 'presale_installments', 'presale_items', 'presales'] as $table) {
                $this->db->table($table)->whereIn('presale_id', $presales)->delete();
            }
        }

        $sales = array_column($this->db->table('sales')->select('sale_id')->whereIn('customer_id', $this->customers === [] ? [0] : $this->customers)->get()->getResultArray(), 'sale_id');

        if ($sales !== []) {
            foreach (['sales_payments', 'sales_items_taxes', 'sales_taxes', 'sales_items', 'sales'] as $table) {
                $this->db->table($table)->whereIn('sale_id', $sales)->delete();
            }
        }

        if ($this->campaignId > 0) {
            $this->db->table('presale_campaign_items')->where('campaign_id', $this->campaignId)->delete();
            $this->db->table('presale_campaign_dates')->where('campaign_id', $this->campaignId)->delete();
            $this->db->table('presale_campaigns')->where('campaign_id', $this->campaignId)->delete();
        }

        if ($this->items !== []) {
            $this->db->table('inventory')->whereIn('trans_items', $this->items)->delete();
            $this->db->table('items_taxes')->whereIn('item_id', $this->items)->delete();
            $this->db->table('item_quantities')->whereIn('item_id', $this->items)->delete();
            $this->db->table('items')->whereIn('item_id', $this->items)->delete();
        }

        $this->db->table('cash_up')->where('cashup_id', $this->cashupId ?? 0)->delete();

        if ($this->closedHere !== []) {
            $this->db->table('cash_up')->whereIn('cashup_id', $this->closedHere)->update(['status' => 'open']);
        }

        if ($this->customers !== []) {
            $this->db->table('customers')->whereIn('person_id', $this->customers)->delete();
            $this->db->table('people')->whereIn('person_id', $this->customers)->delete();
        }

        foreach ($this->grantedHere as $permission) {
            $this->db->table('grants')->where(['permission_id' => $permission, 'person_id' => 1])->delete();
        }

        foreach ($this->revokedHere as $grant) {
            $this->db->table('grants')->replace($grant);
        }

        foreach ($this->configBefore as $key => $value) {
            if ($value === null) {
                $this->db->table('app_config')->where('key', $key)->delete();
            } else {
                $this->db->table('app_config')->replace(['key' => $key, 'value' => $value]);
            }
        }

        config(OSPOS::class)->update_settings();

        $_SESSION = [];

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    // 1. The presale's total is what the register charges
    // ---------------------------------------------------------------------------------------------

    /**
     * Tax added on top of the price (tax_included off): 3 × 10.00 + 19 % = 35.70. The presale's
     * total carries the tax, and the delivery is covered by the presale payment alone.
     */
    public function testWithTaxAddedToThePriceTheTotalCarriesItAndTheDeliveryChargesNothingMore(): void
    {
        $item = $this->makeItem('PRDEC-TEST pavo', '10.00', Item::UNIT_OF_MEASURE_UNIT, '19');
        $this->makeCampaign([$item]);

        $presale = $this->registerPaid([[$item, '3']], '35.70');

        $this->assertSame('35.70', model(Presale::class)->get_info($presale)['total']);

        $sale = $this->deliverAndComplete($presale);

        $this->assertSame([['presale', '35.70', '0.00']], $this->salePayments($sale), 'Nothing more to charge, no change to give.');
    }

    /**
     * Tax included in the price: the register charges the sum of the lines, and so does the presale.
     */
    public function testWithTaxIncludedInThePriceTheTotalIsTheSumOfTheLines(): void
    {
        $this->setConfig(['tax_included' => '1']);

        $item = $this->makeItem('PRDEC-TEST pavo', '11.90', Item::UNIT_OF_MEASURE_UNIT, '19');
        $this->makeCampaign([$item]);

        $presale = $this->registerPaid([[$item, '2']], '23.80');

        $this->assertSame('23.80', model(Presale::class)->get_info($presale)['total']);

        $sale = $this->deliverAndComplete($presale);

        $this->assertSame([['presale', '23.80', '0.00']], $this->salePayments($sale));
    }

    /**
     * A customer marked as not taxable pays no tax at the register (Tax_lib), and the presale agrees.
     */
    public function testACustomerWhoPaysNoTaxIsNotChargedTax(): void
    {
        $exempt = $this->makeCustomer('Exento PRDEC-TEST', false);
        $item   = $this->makeItem('PRDEC-TEST pavo', '10.00', Item::UNIT_OF_MEASURE_UNIT, '19');
        $this->makeCampaign([$item]);

        $presale = $this->registerPaid([[$item, '3']], '30.00', $exempt);

        $this->assertSame('30.00', model(Presale::class)->get_info($presale)['total']);

        $sale = $this->deliverAndComplete($presale);

        $this->assertSame([['presale', '30.00', '0.00']], $this->salePayments($sale));
    }

    /**
     * The register sums line amounts unrounded and only the total is rounded. In a currency without
     * decimals, 1.256 kg × 10,010 = 12,572.56 twice is 25,145.12: the register takes 25,145. Rounding
     * each line, as presales did before, gave 25,146 -- one peso more than the register charges,
     * handed back as change at the counter.
     */
    public function testWeightLinesAreRoundedAsTheRegisterRoundsTheTotal(): void
    {
        $this->setConfig(['currency_decimals' => '0']);

        $first  = $this->makeItem('PRDEC-TEST pernil', '10010.00', Item::UNIT_OF_MEASURE_KG);
        $second = $this->makeItem('PRDEC-TEST lomo', '10010.00', Item::UNIT_OF_MEASURE_KG);
        $this->makeCampaign([$first, $second]);

        $presale = $this->registerPaid([[$first, '1.256'], [$second, '1.256']], '25145');

        $this->assertSame('25145.00', model(Presale::class)->get_info($presale)['total']);

        $sale = $this->deliverAndComplete($presale);

        $this->assertSame([['presale', '25145.00', '0.00']], $this->salePayments($sale), 'Exactly what the register charges: no difference, no change.');
    }

    /**
     * The registration form shows the total the presale will store, taxes included.
     */
    public function testThePreviewShowsTheTotalThePresaleWillStore(): void
    {
        $item = $this->makeItem('PRDEC-TEST pavo', '10.00', Item::UNIT_OF_MEASURE_UNIT, '19');
        $this->makeCampaign([$item]);

        $_SESSION = ['person_id' => 1, 'menu_group' => 'home'];
        $response = $this->postReq('presales/preview', [
            'campaign_id' => (string) $this->campaignId,
            'customer_id' => (string) $this->customerId,
            'lines'       => [['item_id' => (string) $item, 'quantity' => '3']],
        ]);

        $result = json_decode((string) $response->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame(to_currency('35.70'), $result['total']);
        $this->assertSame(to_currency('5.70'), $result['taxes']);
    }

    // ---------------------------------------------------------------------------------------------
    // 3. A lighter weight than agreed
    // ---------------------------------------------------------------------------------------------

    /**
     * 2 × 7 + 1.2 kg × 18 = 35.60 of 41.00 paid: 5.40 back, 13 % -- under the 15 % limit. Any cashier.
     */
    public function testUnderTheLimitAnyCashierCompletes(): void
    {
        $this->revoke('presales_manage');
        [$presale] = $this->paidWeightPresale();

        $this->deliver($presale);
        $this->editWeight('1.200');
        $this->postReq('sales/complete', [])->assertStatus(200);

        $row = model(Presale::class)->get_info($presale);
        $this->assertSame(Presale::STATUS_DELIVERED, $row['status']);
        $this->assertSame([['cash', '0.00', '5.40'], ['presale', '41.00', '0.00']], $this->salePayments((int) $row['sale_id']));

        $detail = $this->adjustment($presale);
        $this->assertSame('5.40', $detail['refund']);
        $this->assertNull($detail['authorized_by'], 'Under the limit nobody had to authorise it.');
    }

    /**
     * 1.0 kg: 9.00 back of 41.00, 22 % -- over the limit. A cashier without presales_manage is refused
     * and nothing is written.
     */
    public function testOverTheLimitWithoutTheGrantIsRefused(): void
    {
        $this->revoke('presales_manage');
        [$presale] = $this->paidWeightPresale();

        $this->deliver($presale);
        $this->editWeight('1.000');

        $this->postReq('sales/complete', [])->assertSee(esc(lang('Presale_register.weight_refund_needs_manager', ['15'])));

        $row = model(Presale::class)->get_info($presale);
        $this->assertSame(Presale::STATUS_OPEN, $row['status']);
        $this->assertSame(0, $this->db->table('sales')->where(['customer_id' => $this->customerId, 'sale_status' => COMPLETED])->countAllResults());
    }

    /**
     * Same weight, completed by someone who holds presales_manage: delivered, and the event says who
     * authorised the refund.
     */
    public function testOverTheLimitWithTheGrantCompletesAndRecordsWhoAuthorised(): void
    {
        $this->grant('presales_manage');
        [$presale, $weightItem] = $this->paidWeightPresale();

        $this->deliver($presale);
        $this->editWeight('1.000');
        $this->postReq('sales/complete', [])->assertStatus(200);

        $row  = model(Presale::class)->get_info($presale);
        $sale = (int) $row['sale_id'];
        $this->assertSame(Presale::STATUS_DELIVERED, $row['status']);
        $this->assertSame([['cash', '0.00', '9.00'], ['presale', '41.00', '0.00']], $this->salePayments($sale));

        $this->assertSame([
            'sale_id'       => $sale,
            'lines'         => [['line' => 2, 'item_id' => $weightItem, 'agreed' => '1.500', 'delivered' => '1.000']],
            'refund'        => '9.00',
            'authorized_by' => 1,
        ], $this->adjustment($presale));
    }

    /**
     * 0 means no limit.
     */
    public function testALimitOfZeroMeansNoLimit(): void
    {
        $this->setConfig(['presales_weight_refund_limit' => '0']);
        $this->revoke('presales_manage');
        [$presale] = $this->paidWeightPresale();

        $this->deliver($presale);
        $this->editWeight('0.100');
        $this->postReq('sales/complete', [])->assertStatus(200);

        $this->assertSame(Presale::STATUS_DELIVERED, model(Presale::class)->get_info($presale)['status']);
    }

    // ---------------------------------------------------------------------------------------------
    // 4. No delivery without an open shift
    // ---------------------------------------------------------------------------------------------

    public function testAPresaleIsNotSentToTheRegisterWithoutAnOpenShift(): void
    {
        [$presale] = $this->paidWeightPresale();
        $this->closeEveryOpenShift();

        $response = $this->postReq('sales/deliverPresale/' . $presale, []);

        $response->assertRedirect();
        $response->assertSessionHas('error', lang('Presale_register.no_open_cashup'));

        $this->assertNull(model(Presale::class)->get_info($presale)['sale_id']);
        $this->assertSame([], $_SESSION['sales_cart'] ?? []);
        $this->assertSame(0, (int) ($_SESSION['sales_presale_id'] ?? 0));
    }

    public function testADeliveryIsNotCompletedWithoutAnOpenShift(): void
    {
        [$presale] = $this->paidWeightPresale();
        $this->deliver($presale);
        $this->closeEveryOpenShift();

        $this->postReq('sales/complete', [])->assertSee(esc(lang('Presale_register.no_open_cashup')));

        $this->assertSame(Presale::STATUS_OPEN, model(Presale::class)->get_info($presale)['status']);
        $this->assertSame(0, $this->db->table('sales')->where(['customer_id' => $this->customerId, 'sale_status' => COMPLETED])->countAllResults());
    }

    // ---------------------------------------------------------------------------------------------
    // Accents (lane E's report of entities in a presales test)
    // ---------------------------------------------------------------------------------------------

    /**
     * The presale screen and its receipt print the customer's name and the conditions with their
     * accents, not as entities: esc() is htmlspecialchars, which leaves them alone. Checked on the raw
     * body -- the DOM parser behind assertSee() re-encodes non-ASCII text, which is where entities
     * showed up in a test before.
     */
    public function testTheScreenAndTheReceiptKeepTheAccents(): void
    {
        $this->setConfig(['presales_terms' => 'Señor José: se entrega con el pago completo.']);

        $item = $this->makeItem('PRDEC-TEST pavo', '10.00', Item::UNIT_OF_MEASURE_UNIT);
        $this->makeCampaign([$item]);
        $presale = $this->registerPaid([[$item, '1']], '10.00');

        foreach (['presales/view/' . $presale, 'presales/receipt/' . $presale] as $path) {
            $_SESSION = ['person_id' => 1, 'menu_group' => 'home'];
            $body     = (string) $this->getReq($path)->getBody();

            $this->assertStringContainsString('Muñoz PRDEC-TEST', $body, $path);
            $this->assertStringNotContainsString('&ntilde;', $body, $path);
            $this->assertStringNotContainsString('&eacute;', $body, $path);
        }

        $this->assertStringContainsString('Señor José', (string) $this->getReq('presales/receipt/' . $presale)->getBody());
    }

    // ---------------------------------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------------------------------

    /**
     * A presale registered through the model -- the real total computation -- and paid in full with
     * $paid in one instalment due today.
     *
     * @param list<array{0: int, 1: string}> $lines [item_id, quantity]
     */
    private function registerPaid(array $lines, string $paid, ?int $customer = null): int
    {
        $id = model(Presale::class)->create([
            'campaign_id'      => $this->campaignId,
            'customer_id'      => $customer ?? $this->customerId,
            'delivery_date_id' => $this->dateId,
            'location_id'      => 1,
            'lines'            => array_map(static fn (array $line): array => ['item_id' => $line[0], 'quantity' => $line[1]], $lines),
            'installments'     => [['due_date' => $this->day(0), 'amount' => $paid]],
            'payment'          => ['payment_type_code' => 'cash', 'amount' => $paid],
        ], 1, $this->day(0));

        $this->assertIsInt($id, is_string($id) ? $id : '');

        return $id;
    }

    /**
     * 2 units at 7.00 + 1.5 kg at 18.00/kg = 41.00, fully paid. Inserted directly: the weight tests
     * are about the delivery, not the registration. Returns [presale_id, weight item_id].
     *
     * @return array{0: int, 1: int}
     */
    private function paidWeightPresale(): array
    {
        $unit   = $this->makeItem('PRDEC-TEST unidad', '10.00', Item::UNIT_OF_MEASURE_UNIT);
        $weight = $this->makeItem('PRDEC-TEST pernil', '20.00', Item::UNIT_OF_MEASURE_KG);

        $this->db->table('presales')->insert([
            'campaign_id'      => 0,
            'customer_id'      => $this->customerId,
            'employee_id'      => 1,
            'location_id'      => 1,
            'created_at'       => date('Y-m-d H:i:s'),
            'delivery_date_id' => 0,
            'delivery_date'    => '2099-12-24',
            'status'           => Presale::STATUS_OPEN,
            'total'            => '41.00',
            'comment'          => null,
        ]);
        $presale = (int) $this->db->insertID();

        $line = 0;

        foreach ([[$unit, '2', '7.00'], [$weight, '1.5', '18.00']] as [$item, $quantity, $price]) {
            $this->db->table('presale_items')->insert([
                'presale_id'    => $presale,
                'line'          => ++$line,
                'item_id'       => $item,
                'description'   => null,
                'quantity'      => $quantity,
                'unit_price'    => $price,
                'discount'      => '0',
                'discount_type' => 0,
                'print_option'  => PRINT_YES,
                'item_type'     => ITEM,
            ]);
        }

        $this->db->table('presale_payments')->insert([
            'presale_id'        => $presale,
            'kind'              => 'payment',
            'payment_type_code' => 'cash',
            'amount'            => '41.00',
            'payment_time'      => date('Y-m-d H:i:s'),
            'employee_id'       => 1,
            'cashup_id'         => $this->cashupId,
        ]);

        return [$presale, $weight];
    }

    private function deliver(int $presale): void
    {
        $this->postReq('sales/deliverPresale/' . $presale, [])->assertRedirectTo(site_url('sales'));
        $this->getReq('sales')->assertSeeElement('#presale_delivery_banner');
    }

    private function deliverAndComplete(int $presale): int
    {
        $this->deliver($presale);
        $this->postReq('sales/complete', [])->assertStatus(200);

        $row = model(Presale::class)->get_info($presale);

        $this->assertSame(Presale::STATUS_DELIVERED, $row['status'], 'The delivery completed with what was paid.');

        return (int) $row['sale_id'];
    }

    private function editWeight(string $quantity): void
    {
        $this->postReq('sales/editItem/2', ['quantity' => $quantity, 'price' => '18.00', 'discount' => '0', 'description' => '', 'serialnumber' => '']);
    }

    /**
     * @return array<string, mixed>
     */
    private function adjustment(int $presale): array
    {
        $event = $this->db->table('presale_events')->where(['presale_id' => $presale, 'event_type' => 'quantity_adjusted'])->get()->getRowArray();

        $this->assertNotNull($event, 'A lighter weight is recorded on the presale.');

        return json_decode((string) $event['detail'], true);
    }

    /**
     * @param list<int> $items
     */
    private function makeCampaign(array $items): void
    {
        $campaigns = model(Presale_campaign::class);
        $campaign  = $campaigns->save_campaign([
            'name'                => 'PRDEC-TEST Navidad',
            'sale_starts'         => $this->day(-5),
            'sale_ends'           => $this->day(40),
            'discount_percent'    => '0',
            'min_initial_percent' => '0',
            'active'              => 1,
        ], NEW_ENTRY, 1);

        $this->assertIsInt($campaign, is_string($campaign) ? $campaign : '');
        $this->campaignId = $campaign;

        $this->assertTrue($campaigns->add_date($this->campaignId, $this->day(60)));
        $this->dateId = (int) $campaigns->get_dates($this->campaignId)[0]['date_id'];

        foreach ($items as $item) {
            $this->assertTrue($campaigns->add_item($this->campaignId, $item));
        }
    }

    private function makeCustomer(string $lastName, bool $taxable): int
    {
        $this->db->table('people')->insert([
            'first_name'   => 'José',
            'last_name'    => $lastName,
            'phone_number' => '',
            'email'        => '',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
        ]);
        $person = (int) $this->db->insertID();

        $this->db->table('customers')->insert([
            'person_id'   => $person,
            'taxable'     => $taxable ? 1 : 0,
            'deleted'     => 0,
            'employee_id' => 1,
        ]);

        $this->customers[] = $person;

        return $person;
    }

    private function openShift(): int
    {
        $this->db->table('cash_up')->insert([
            'status'               => 'open',
            'open_date'            => '2099-07-01 08:00:00',
            'close_date'           => null,
            'open_amount_cash'     => '0.00',
            'transfer_amount_cash' => '0.00',
            'note'                 => 0,
            'closed_amount_cash'   => '0.00',
            'closed_amount_card'   => '0.00',
            'closed_amount_check'  => '0.00',
            'closed_amount_due'    => '0.00',
            'closed_amount_total'  => '0.00',
            'description'          => 'PresaleOwnerDecisionsTest fixture',
            'open_employee_id'     => 1,
            'close_employee_id'    => 1,
            'deleted'              => 0,
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * Closes this file's shift and, for the length of the test, any shift another file left open.
     */
    private function closeEveryOpenShift(): void
    {
        $open = array_map('intval', array_column(
            $this->db->table('cash_up')->select('cashup_id')->where(['status' => 'open', 'deleted' => 0])->get()->getResultArray(),
            'cashup_id',
        ));

        if ($open === []) {
            return;
        }

        $this->db->table('cash_up')->whereIn('cashup_id', $open)->update(['status' => 'closed']);
        $this->closedHere = array_values(array_diff($open, [$this->cashupId]));
    }

    private function makeItem(string $name, string $price, string $unit, ?string $taxPercent = null): int
    {
        $this->db->table('items')->insert([
            'name'                  => $name,
            'category'              => 'Test',
            'item_number'           => null,
            'description'           => '',
            'cost_price'            => '1.00',
            'unit_price'            => $price,
            'unit_of_measure'       => $unit,
            'reorder_level'         => '0',
            'receiving_quantity'    => '1',
            'allow_alt_description' => 0,
            'is_serialized'         => 0,
            'item_type'             => ITEM,
            'stock_type'            => HAS_STOCK,
        ]);
        $id = (int) $this->db->insertID();

        $this->db->table('item_quantities')->insert(['item_id' => $id, 'location_id' => 1, 'quantity' => '100']);

        if ($taxPercent !== null) {
            $this->db->table('items_taxes')->insert(['item_id' => $id, 'name' => 'IVA', 'percent' => $taxPercent]);
        }

        $this->items[] = $id;

        return $id;
    }

    /**
     * @param array<string, string> $values
     */
    private function setConfig(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->db->table('app_config')->replace(['key' => $key, 'value' => $value]);
        }

        config(OSPOS::class)->update_settings();
    }

    private function grant(string $permission): void
    {
        if ($this->db->table('grants')->where(['permission_id' => $permission, 'person_id' => 1])->countAllResults() === 0) {
            $this->db->table('grants')->insert(['permission_id' => $permission, 'person_id' => 1, 'menu_group' => $permission === 'presales' ? 'home' : '--']);
            $this->grantedHere[] = $permission;
        }
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

    /**
     * @return list<array{0: string, 1: string, 2: string}> [code, amount, cash refund], by code
     */
    private function salePayments(int $sale): array
    {
        $rows = $this->db->table('sales_payments')->where('sale_id', $sale)->orderBy('payment_type_code', 'asc')->get()->getResultArray();

        return array_map(static fn (array $row): array => [
            (string) $row['payment_type_code'],
            bcadd((string) $row['payment_amount'], '0', 2),
            bcadd((string) $row['cash_refund'], '0', 2),
        ], $rows);
    }

    private function day(int $offset): string
    {
        return date('Y-m-d', strtotime(sprintf('%+d days', $offset)));
    }

    private function loginAsCashier(): void
    {
        $_SESSION = ['person_id' => 1, 'menu_group' => 'home'];
        $this->withSession($_SESSION);

        $this->getReq('sales');
    }

    private function getReq(string $path): TestResponse
    {
        $this->withSession($_SESSION);

        return $this->get($path);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function postReq(string $path, array $params): TestResponse
    {
        $this->withSession($_SESSION);

        return $this->post($path, $params);
    }
}
