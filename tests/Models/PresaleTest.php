<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\Item;
use App\Models\Presale;
use App\Models\Presale_campaign;
use App\Models\Presale_event;
use App\Models\Presale_payment;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\OSPOS;

/**
 * Registering, paying, cancelling and delivering a presale, against the database.
 *
 * Every rule is the model's (docs/Tecnico/venta-anticipada.md §4.10). These tests are the only thing
 * standing between a rule and a screen that forgets it -- the register's Due payment, whose "a
 * customer is required" lives only in the view, is the example of what happens without them.
 *
 * SHARED DATABASE: this file creates its own customer, items, open shift and campaign, and removes
 * exactly those in tearDown. Never a truncate: other files use the same tables.
 *
 * The shift is opened with a far-future open_date so Cashup::get_open_cashup_id(), which picks the
 * most recently opened shift, picks this one even if another file left a shift open.
 *
 * @internal
 */
final class PresaleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    private const TODAY = '2026-11-10';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';
    private Presale $presales;
    private Presale_campaign $campaigns;
    private int $employee_id;
    private int $customer_id;
    private int $cashup_id;
    private int $campaign_id;
    private int $date_id;
    private int $turkey_id;
    private int $ham_id;
    private int $outside_id;
    private mixed $decimalsBefore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->presales  = model(Presale::class);
        $this->campaigns = model(Presale_campaign::class);

        $this->decimalsBefore                               = config(OSPOS::class)->settings['currency_decimals'] ?? null;
        config(OSPOS::class)->settings['currency_decimals'] = 0;

        $employee          = $this->db->table('employees')->select('person_id')->limit(1)->get()->getRow();
        $this->employee_id = $employee === null ? 1 : (int) $employee->person_id;

        $this->customer_id = $this->makeCustomer();
        $this->cashup_id   = $this->openShift();

        $this->turkey_id  = $this->makeItem('PRESALE-TEST pavo', '100000.00', Item::UNIT_OF_MEASURE_UNIT);
        $this->ham_id     = $this->makeItem('PRESALE-TEST pernil', '40000.00', Item::UNIT_OF_MEASURE_KG);
        $this->outside_id = $this->makeItem('PRESALE-TEST fuera', '5000.00', Item::UNIT_OF_MEASURE_UNIT);

        $campaign = $this->campaigns->save_campaign([
            'name'                => 'PRESALE-TEST Navidad',
            'sale_starts'         => '2026-11-01',
            'sale_ends'           => '2026-12-15',
            'discount_percent'    => '10',
            'min_initial_percent' => '30',
            'active'              => 1,
        ], NEW_ENTRY, $this->employee_id);

        $this->assertIsInt($campaign);
        $this->campaign_id = $campaign;

        $this->assertTrue($this->campaigns->add_date($this->campaign_id, '2026-12-23'));
        $this->date_id = (int) $this->campaigns->get_dates($this->campaign_id)[0]['date_id'];

        $this->assertTrue($this->campaigns->add_item($this->campaign_id, $this->turkey_id));
        $this->assertTrue($this->campaigns->add_item($this->campaign_id, $this->ham_id));
    }

    protected function tearDown(): void
    {
        $presale_ids = array_column(
            $this->db->table('presales')->select('presale_id')->where('campaign_id', $this->campaign_id ?? 0)->get()->getResultArray(),
            'presale_id',
        );

        if ($presale_ids !== []) {
            foreach (['presale_events', 'presale_payments', 'presale_installments', 'presale_items', 'presales'] as $table) {
                $this->db->table($table)->whereIn('presale_id', $presale_ids)->delete();
            }
        }

        $this->db->table('presale_campaign_items')->where('campaign_id', $this->campaign_id ?? 0)->delete();
        $this->db->table('presale_campaign_dates')->where('campaign_id', $this->campaign_id ?? 0)->delete();
        $this->db->table('presale_campaigns')->where('campaign_id', $this->campaign_id ?? 0)->delete();
        $this->db->table('items')->like('name', 'PRESALE-TEST', 'after')->delete();
        $this->db->table('cash_up')->where('cashup_id', $this->cashup_id ?? 0)->delete();
        $this->db->table('customers')->where('person_id', $this->customer_id ?? 0)->delete();
        $this->db->table('people')->where('person_id', $this->customer_id ?? 0)->delete();

        if ($this->decimalsBefore === null) {
            unset(config(OSPOS::class)->settings['currency_decimals']);
        } else {
            config(OSPOS::class)->settings['currency_decimals'] = $this->decimalsBefore;
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    // Registering
    // ---------------------------------------------------------------------------------------------

    public function testAPresaleIsRegisteredWithItsFirstInstalmentInTheOpenShift(): void
    {
        $id = $this->register();

        $this->assertIsInt($id);

        $presale = $this->presales->get_info($id);
        // 1 turkey at 90,000 (campaign 10%) + 2.5 kg of ham at 36,000/kg = 90,000 + 90,000.
        $this->assertSame('180000.00', $presale['total']);
        $this->assertSame(Presale::STATUS_OPEN, $presale['status']);
        $this->assertSame('2026-12-23', $presale['delivery_date']);

        $payments = model(Presale_payment::class)->get_for($id);
        $this->assertCount(1, $payments);
        $this->assertSame($this->cashup_id, (int) $payments[0]['cashup_id']);
        $this->assertSame('cash', $payments[0]['payment_type_code']);

        $this->assertSame(
            [Presale_event::CREATED, Presale_event::PAYMENT],
            array_column(model(Presale_event::class)->get_for($id), 'event_type'),
        );

        $summary = $this->presales->get_summary($id, self::TODAY);
        $this->assertSame('126000.00', $summary['balance']);
        $this->assertSame(Presale::STATE_UP_TO_DATE, $summary['state']);
    }

    /**
     * Prices come from the campaign and never from the form: a line that says it costs 1 still costs
     * what the campaign says.
     */
    public function testThePriceIsTheCampaignsWhateverTheFormSays(): void
    {
        $id = $this->register(['lines' => [['item_id' => $this->turkey_id, 'quantity' => '1', 'unit_price' => '1']], 'installments' => [['due_date' => '2026-11-10', 'amount' => '90000']], 'payment' => ['payment_type_code' => 'cash', 'amount' => '90000']]);

        $this->assertIsInt($id);
        $this->assertSame('90000.00', $this->presales->get_lines($id)[0]['unit_price']);
    }

    /**
     * The price is frozen on the presale: changing the campaign afterwards does not touch it (D8).
     */
    public function testChangingTheCampaignPriceLaterDoesNotChangeARegisteredPresale(): void
    {
        $id = $this->register();

        $this->campaigns->update_item($this->campaign_id, $this->turkey_id, '1000', null);

        $this->assertSame('180000.00', $this->presales->get_info($id)['total']);
        $this->assertSame('90000.00', $this->presales->get_lines($id)[0]['unit_price']);
    }

    public function testACampaignOutsideItsSellingWindowTakesNoNewPresale(): void
    {
        $this->assertSame('Presales.campaign_not_selling', $this->presales->create($this->input(), $this->employee_id, '2026-12-16'));
        $this->assertSame('Presales.campaign_not_selling', $this->presales->create($this->input(), $this->employee_id, '2026-10-31'));
    }

    public function testAProductOutsideTheCampaignIsRefused(): void
    {
        $result = $this->register(['lines' => [['item_id' => $this->outside_id, 'quantity' => '1']]]);

        $this->assertSame('Presales.item_not_in_campaign', $result);
    }

    public function testADeliveryDateOutsideTheCampaignIsRefused(): void
    {
        $this->assertSame('Presales.date_not_in_campaign', $this->register(['delivery_date_id' => 999_999]));
    }

    public function testAPresaleWithoutARegisteredCustomerIsRefused(): void
    {
        $this->assertSame('Presales.customer_required', $this->register(['customer_id' => 0]));
    }

    public function testInstalmentsThatDoNotAddUpToTheTotalAreRefused(): void
    {
        $result = $this->register(['installments' => [
            ['due_date' => '2026-11-10', 'amount' => '54000'],
            ['due_date' => '2026-12-01', 'amount' => '100000'],
        ]]);

        $this->assertSame('Presales.installments_must_add_up', $result);
    }

    public function testAnInstalmentAfterTheDeliveryDateIsRefused(): void
    {
        $result = $this->register(['installments' => [
            ['due_date' => '2026-11-10', 'amount' => '54000'],
            ['due_date' => '2026-12-24', 'amount' => '126000'],
        ]]);

        $this->assertSame('Presales.installment_after_delivery', $result);
    }

    /**
     * 30% of 180,000 is 54,000 (D21).
     */
    public function testAFirstInstalmentBelowTheCampaignMinimumIsRefused(): void
    {
        $result = $this->register([
            'installments' => [['due_date' => '2026-11-10', 'amount' => '53999'], ['due_date' => '2026-12-01', 'amount' => '126001']],
            'payment'      => ['payment_type_code' => 'cash', 'amount' => '53999'],
        ]);

        $this->assertSame('Presales.initial_below_minimum', $result);
    }

    public function testAPaymentTypeOutsideTheClosedListIsRefused(): void
    {
        $result = $this->register(['payment' => ['payment_type_code' => 'due', 'amount' => '54000']]);

        $this->assertSame('Presales.payment_type_invalid', $result);
    }

    public function testAProductSoldByTheUnitCannotTakeAFraction(): void
    {
        $result = $this->register(['lines' => [['item_id' => $this->turkey_id, 'quantity' => '1.5']]]);

        $this->assertSame('Presales.quantity_must_be_whole', $result);
    }

    public function testScientificNotationIsRefusedInsteadOfCrashingBcmath(): void
    {
        $this->assertSame('Presales.quantity_invalid', $this->register(['lines' => [['item_id' => $this->turkey_id, 'quantity' => '1e1']]]));
    }

    public function testNothingIsWrittenWhenARegistrationIsRefused(): void
    {
        $this->register(['installments' => [['due_date' => '2026-11-10', 'amount' => '1']]]);

        $this->assertSame(0, $this->db->table('presales')->where('campaign_id', $this->campaign_id)->countAllResults());
    }

    // ---------------------------------------------------------------------------------------------
    // Money after registration
    // ---------------------------------------------------------------------------------------------

    public function testAPaymentAboveTheBalanceIsRefusedAndTheExactBalanceMakesItPaid(): void
    {
        $id = $this->register();

        $this->assertSame('Presales.payment_exceeds_balance', $this->presales->add_payment($id, 'bank_transfer', '126001', $this->employee_id));
        $this->assertIsInt($this->presales->add_payment($id, 'bank_transfer', '126000', $this->employee_id, 'TRX-1'));

        $this->assertSame(Presale::STATE_PAID, $this->presales->get_summary($id, self::TODAY)['state']);
    }

    /**
     * The cash-up reads the net per payment type of the shift: instalments in, refunds out.
     */
    public function testTheShiftSeesItsPresaleMoneyNetPerPaymentType(): void
    {
        $paid     = $this->register();
        $canceled = $this->register();

        $this->presales->add_payment($paid, 'bank_transfer', '26000', $this->employee_id);
        $this->assertTrue($this->presales->cancel($canceled, $this->employee_id, 'Cliente desiste', '20000', 'cash'));

        $rows = array_column(model(Presale_payment::class)->get_by_cashup($this->cashup_id), 'trans_amount', 'payment_type_code');

        // Two initial instalments of 54,000 in cash, minus a 20,000 cash refund.
        $this->assertSame('88000.00', $rows['cash']);
        $this->assertSame('26000.00', $rows['bank_transfer']);
    }

    public function testCancellingRecordsWhatWasAgreedAndClosesThePresale(): void
    {
        $id = $this->register();

        $this->assertSame('Presales.cancel_reason_required', $this->presales->cancel($id, $this->employee_id, '  '));
        $this->assertSame('Presales.refund_exceeds_paid', $this->presales->cancel($id, $this->employee_id, 'Desiste', '54001', 'cash'));
        $this->assertTrue($this->presales->cancel($id, $this->employee_id, 'Desiste', '30000', 'cash'));

        $presale = $this->presales->get_info($id);
        $this->assertSame(Presale::STATUS_CANCELED, $presale['status']);
        $this->assertSame('Desiste', $presale['cancel_reason']);
        $this->assertSame('24000.00', model(Presale_payment::class)->get_paid($id), 'What the business kept.');

        $this->assertSame('Presales.not_open', $this->presales->add_payment($id, 'cash', '1000', $this->employee_id));
    }

    public function testCancellingWithoutRefundNeedsNoPaymentType(): void
    {
        $id = $this->register();

        $this->assertTrue($this->presales->cancel($id, $this->employee_id, 'Sin devolución acordada'));
        $this->assertSame('54000.00', model(Presale_payment::class)->get_paid($id));
    }

    // ---------------------------------------------------------------------------------------------
    // Delivery
    // ---------------------------------------------------------------------------------------------

    public function testAPresaleWithABalanceCannotBeMarkedDelivered(): void
    {
        $id = $this->register();

        $this->assertFalse($this->presales->mark_delivered($id, 990_001, $this->employee_id));
        $this->assertSame(Presale::STATUS_OPEN, $this->presales->get_info($id)['status']);
    }

    public function testAPaidPresaleIsDeliveredOnceAndOnlyOnce(): void
    {
        $id = $this->register();
        $this->presales->add_payment($id, 'cash', '126000', $this->employee_id);

        $this->assertTrue($this->presales->mark_delivered($id, 990_002, $this->employee_id));
        $this->assertFalse($this->presales->mark_delivered($id, 990_003, $this->employee_id), 'A second till must be refused.');

        $presale = $this->presales->get_info($id);
        $this->assertSame(Presale::STATUS_DELIVERED, $presale['status']);
        $this->assertSame(990_002, (int) $presale['sale_id']);
        $this->assertSame($id, (int) $this->presales->get_by_sale(990_002)['presale_id']);
    }

    // ---------------------------------------------------------------------------------------------
    // What can no longer be removed
    // ---------------------------------------------------------------------------------------------

    public function testWhatAnOpenPresaleUsesCannotBeRemoved(): void
    {
        $this->register();

        $this->assertFalse($this->campaigns->remove_item($this->campaign_id, $this->turkey_id));
        $this->assertFalse($this->campaigns->remove_date($this->campaign_id, $this->date_id));
        $this->assertFalse($this->campaigns->delete_campaign($this->campaign_id));
        $this->assertTrue($this->presales->item_in_use($this->turkey_id));
        $this->assertTrue($this->presales->customer_has_open($this->customer_id));
    }

    /**
     * A campaign that stopped selling no longer holds its products: they can be deleted from the
     * catalogue. Before, any campaign row blocked the product forever.
     */
    public function testAProductOfACampaignThatEndedCanBeDeleted(): void
    {
        // The campaign sells until 2026-12-15, both ends included.
        $this->assertTrue($this->presales->item_in_use($this->turkey_id, '2026-12-15'));
        $this->assertFalse($this->presales->item_in_use($this->turkey_id, '2026-12-16'));
    }

    /**
     * A deleted campaign takes its products and dates with it and blocks nothing.
     */
    public function testADeletedCampaignBlocksNothing(): void
    {
        $this->assertTrue($this->campaigns->delete_campaign($this->campaign_id));

        $this->assertFalse($this->presales->item_in_use($this->turkey_id, self::TODAY));
        $this->assertSame(0, $this->db->table('presale_campaign_items')->where('campaign_id', $this->campaign_id)->countAllResults());
        $this->assertSame(0, $this->db->table('presale_campaign_dates')->where('campaign_id', $this->campaign_id)->countAllResults());
    }

    /**
     * Campaigns deleted before delete_campaign() took its products along still have their rows; the
     * deleted flag alone has to be enough.
     */
    public function testAProductRowLeftByAnOldDeletedCampaignBlocksNothing(): void
    {
        $this->db->table('presale_campaigns')->where('campaign_id', $this->campaign_id)->update(['deleted' => 1]);

        $this->assertFalse($this->presales->item_in_use($this->turkey_id, self::TODAY));
    }

    /**
     * An open presale holds its products whatever happened to its campaign; once it is closed, it
     * does not.
     */
    public function testAnOpenPresaleHoldsItsProductsAfterItsCampaignEnded(): void
    {
        $id = $this->register();
        $this->assertIsInt($id);

        $this->assertTrue($this->presales->item_in_use($this->turkey_id, '2027-01-15'));
        $this->assertFalse($this->presales->item_in_use($this->outside_id, '2027-01-15'));

        $this->assertTrue($this->presales->cancel($id, $this->employee_id, 'El cliente desistió'));
        $this->assertFalse($this->presales->item_in_use($this->turkey_id, '2027-01-15'));
    }

    public function testADeliveryDateThatHasPassedIsRefused(): void
    {
        $this->assertTrue($this->campaigns->add_date($this->campaign_id, '2026-11-09'));
        $past = (int) $this->db->table('presale_campaign_dates')->where('campaign_id', $this->campaign_id)->where('delivery_date', '2026-11-09')->get()->getRow()->date_id;

        $this->assertSame('Presales.delivery_date_past', $this->register([
            'delivery_date_id' => $past,
            'installments'     => [['due_date' => '2026-11-09', 'amount' => '180000']],
            'payment'          => ['payment_type_code' => 'cash', 'amount' => '180000'],
        ]));
        $this->assertSame(0, $this->db->table('presales')->where('campaign_id', $this->campaign_id)->countAllResults());
    }

    /**
     * Delivering today is allowed: today has not passed.
     */
    public function testADeliveryToday(): void
    {
        $this->assertTrue($this->campaigns->add_date($this->campaign_id, self::TODAY));
        $today = (int) $this->db->table('presale_campaign_dates')->where('campaign_id', $this->campaign_id)->where('delivery_date', self::TODAY)->get()->getRow()->date_id;

        $this->assertIsInt($this->register([
            'delivery_date_id' => $today,
            'installments'     => [['due_date' => self::TODAY, 'amount' => '180000']],
            'payment'          => ['payment_type_code' => 'cash', 'amount' => '180000'],
        ]));
    }

    public function testAnInstalmentBeforeTodayIsRefused(): void
    {
        $this->assertSame('Presales.installment_in_past', $this->register([
            'installments' => [
                ['due_date' => '2026-11-09', 'amount' => '54000'],
                ['due_date' => '2026-12-01', 'amount' => '126000'],
            ],
        ]));
        $this->assertSame(0, $this->db->table('presales')->where('campaign_id', $this->campaign_id)->countAllResults());
    }

    /**
     * A caller pricing several times in one request hands over the campaign's products, read once:
     * price_lines() then reads nothing of the campaign itself (campaign 0 does not exist).
     */
    public function testPricingUsesTheCampaignsProductsWhenTheyAreHandedOver(): void
    {
        $items = $this->campaigns->get_items_by_id($this->campaign_id);

        $this->assertSame([$this->turkey_id, $this->ham_id], array_keys($items), 'Keyed by item, in the order get_items() lists them.');

        $lines = $this->presales->price_lines(0, [['item_id' => $this->turkey_id, 'quantity' => '1']], $items);

        $this->assertIsArray($lines);
        $this->assertSame('90000.00', $lines[0]['unit_price']);
        $this->assertSame('Presales.item_not_in_campaign', $this->presales->price_lines(0, [['item_id' => $this->turkey_id, 'quantity' => '1']]));
    }

    public function testAProductMissingFromTheCatalogueCannotJoinACampaign(): void
    {
        $this->assertSame('Presales.item_not_in_catalogue', $this->campaigns->add_item($this->campaign_id, 999_999_999));
    }

    public function testTheNumberCarriesTheBusinessPrefix(): void
    {
        $before                                           = config(OSPOS::class)->settings['presales_prefix'] ?? null;
        config(OSPOS::class)->settings['presales_prefix'] = 'NAV-';

        try {
            $this->assertSame('NAV-000042', $this->presales->number(42));
        } finally {
            if ($before === null) {
                unset(config(OSPOS::class)->settings['presales_prefix']);
            } else {
                config(OSPOS::class)->settings['presales_prefix'] = $before;
            }
        }
    }

    /**
     * 1 turkey (90,000) + 2.5 kg of ham (90,000) = 180,000; 30% now in cash, the rest on 1 December.
     *
     * @param array<string, mixed> $overrides
     */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'campaign_id'      => $this->campaign_id,
            'customer_id'      => $this->customer_id,
            'delivery_date_id' => $this->date_id,
            'location_id'      => 1,
            'comment'          => '',
            'lines'            => [
                ['item_id' => $this->turkey_id, 'quantity' => '1'],
                ['item_id' => $this->ham_id, 'quantity' => '2.5'],
            ],
            'installments' => [
                ['due_date' => '2026-11-10', 'amount' => '54000'],
                ['due_date' => '2026-12-01', 'amount' => '126000'],
            ],
            'payment' => ['payment_type_code' => 'cash', 'amount' => '54000'],
        ], $overrides);
    }

    private function register(array $overrides = []): int|string
    {
        return $this->presales->create($this->input($overrides), $this->employee_id, self::TODAY);
    }

    private function makeCustomer(): int
    {
        $this->db->table('people')->insert([
            'first_name'   => 'José',
            'last_name'    => 'Muñoz PRESALE-TEST',
            'phone_number' => '3000000000',
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

        $this->db->table('customers')->insert([
            'person_id'   => $person_id,
            'taxable'     => 1,
            'deleted'     => 0,
            'employee_id' => $this->employee_id,
        ]);

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
            'description'          => 'PresaleTest fixture',
            'open_employee_id'     => $this->employee_id,
            'close_employee_id'    => $this->employee_id,
            'deleted'              => 0,
        ]);

        return (int) $this->db->insertID();
    }

    private function makeItem(string $name, string $price, string $unit): int
    {
        $this->db->table('items')->insert([
            'name'                  => $name,
            'category'              => 'Test',
            'item_number'           => null,
            'description'           => '',
            'cost_price'            => '0.00',
            'unit_price'            => $price,
            'unit_of_measure'       => $unit,
            'reorder_level'         => '0',
            'receiving_quantity'    => '1',
            'allow_alt_description' => 0,
            'is_serialized'         => 0,
        ]);

        return (int) $this->db->insertID();
    }
}
