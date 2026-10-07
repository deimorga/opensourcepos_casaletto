<?php

namespace Tests\Models;

use App\Controllers\Cashups;
use App\Models\Sale;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\OSPOS;
use Config\Services;
use ReflectionMethod;
use stdClass;

/**
 * Presale money in the shift close (docs/Tecnico/venta-anticipada.md §6).
 *
 * Two things have to hold, and both are about the drawer of a real business:
 *
 *  - an instalment counts in the shift that took it, and in no other (D12);
 *  - the sale that delivers a presale is paid with code 'presale', money already counted in the
 *    instalments' shifts, so it must not be counted again in the delivery shift (T5).
 *
 * And a business that never used presales must see exactly the close it saw before.
 *
 * Cash-ups, sales and presale payments made here use ids in the 930_000 range and are deleted in
 * tearDown: the database is shared with every other test file. presales_enable is restored.
 *
 * @internal
 */
final class PresaleCashupReconciliationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const SHIFT_A = 930_001;
    private const SHIFT_B = 930_002;
    private const SHIFT_C = 930_003;
    private const PRESALE = 930_500;

    /**
     * A day nobody else's fixtures use, so expenses and collections of the window are zero.
     */
    private const OPEN_DATE  = '2001-01-10 08:00:00';
    private const CLOSE_DATE = '2001-01-10 20:00:00';

    private int $employee_id;

    private ?string $switchBefore = null;

    /** @var list<int> */
    private array $cashups = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->resetDataCache();
        config(OSPOS::class)->update_settings();

        $employee          = $this->db->table('employees')->select('person_id')->limit(1)->get()->getRow();
        $this->employee_id = $employee === null ? 1 : (int) $employee->person_id;

        $row                = $this->db->table('app_config')->where('key', 'presales_enable')->get()->getRow();
        $this->switchBefore = $row === null ? null : (string) $row->value;

        $this->clearFixture();
    }

    protected function tearDown(): void
    {
        $this->clearFixture();

        if ($this->switchBefore === null) {
            $this->db->table('app_config')->where('key', 'presales_enable')->delete();
        } else {
            $this->db->table('app_config')->replace(['key' => 'presales_enable', 'value' => $this->switchBefore]);
        }

        config(OSPOS::class)->update_settings();

        parent::tearDown();
    }

    private function clearFixture(): void
    {
        $shifts = [self::SHIFT_A, self::SHIFT_B, self::SHIFT_C];

        $sale_ids = array_column(
            $this->db->table('sales')->select('sale_id')->whereIn('cashup_id', $shifts)->get()->getResultArray(),
            'sale_id'
        );

        if ($sale_ids !== []) {
            $this->db->table('sales_payments')->whereIn('sale_id', $sale_ids)->delete();
            $this->db->table('sales')->whereIn('sale_id', $sale_ids)->delete();
        }

        $this->db->table('presale_payments')->where('presale_id', self::PRESALE)->delete();

        if ($this->cashups !== []) {
            $this->db->table('cash_up')->whereIn('cashup_id', $this->cashups)->delete();
            $this->cashups = [];
        }
    }

    private function switchTo(string $value): void
    {
        $this->db->table('app_config')->replace(['key' => 'presales_enable', 'value' => $value]);
        config(OSPOS::class)->update_settings();
    }

    private function sale(int $sale_id, int $cashup_id, int $status, array $payments): void
    {
        $this->db->table('sales')->insert([
            'sale_id'     => $sale_id,
            'sale_time'   => '2001-01-10 12:00:00',
            'customer_id' => null,
            'employee_id' => $this->employee_id,
            'comment'     => '',
            'sale_status' => $status,
            'cashup_id'   => $cashup_id,
        ]);

        foreach ($payments as [$label, $code, $amount, $refund]) {
            $this->db->table('sales_payments')->insert([
                'sale_id'           => $sale_id,
                'payment_type'      => $label,
                'payment_type_code' => $code,
                'payment_amount'    => $amount,
                'cash_refund'       => $refund,
            ]);
        }
    }

    private function instalment(int $cashup_id, string $code, string $amount, string $kind = 'payment', string $time = '2001-01-10 10:00:00'): void
    {
        $this->db->table('presale_payments')->insert([
            'presale_id'        => self::PRESALE,
            'kind'              => $kind,
            'payment_type_code' => $code,
            'amount'            => $amount,
            'payment_time'      => $time,
            'employee_id'       => $this->employee_id,
            'cashup_id'         => $cashup_id,
        ]);
    }

    /**
     * Cashups::_build_reconciliation() for a shift that opened with $open cash and declared $counted.
     */
    private function reconcile(int $cashup_id, float $open = 100_000.0, float $counted = 0.0): array
    {
        $session = Services::session();
        $session->set('person_id', 1);
        $session->set('menu_group', 'office');

        $controller = new Cashups();
        $method     = new ReflectionMethod($controller, '_build_reconciliation');

        $info                       = new stdClass();
        $info->cashup_id            = $cashup_id;
        $info->open_date            = self::OPEN_DATE;
        $info->close_date           = self::CLOSE_DATE;
        $info->open_amount_cash     = $open;
        $info->closed_amount_cash   = $counted;
        $info->transfer_amount_cash = 0;

        return $method->invoke($controller, $info);
    }

    public function testACashInstalmentRaisesTheExpectedOfTheShiftThatTookItAndNotTheNext(): void
    {
        $this->switchTo('1');
        $this->instalment(self::SHIFT_A, 'cash', '50000.00');

        $a = $this->reconcile(self::SHIFT_A);
        $b = $this->reconcile(self::SHIFT_B);

        $this->assertEqualsWithDelta(150_000.0, $a['expected'], 0.001);
        $this->assertEqualsWithDelta(50_000.0, $a['income_cash'], 0.001);
        $this->assertEqualsWithDelta(50_000.0, $a['income_total'], 0.001);
        $this->assertEqualsWithDelta(50_000.0, $a['presale_payments_total'], 0.001);

        $this->assertEqualsWithDelta(100_000.0, $b['expected'], 0.001);
        $this->assertEqualsWithDelta(0.0, $b['income_total'], 0.001);
        $this->assertSame([], $b['presale_payments']);
    }

    /**
     * A card or transfer instalment is income of the shift, in its own payment type, but it never
     * sat in the drawer.
     */
    public function testANonCashInstalmentIsIncomeButNotDrawerCash(): void
    {
        $this->switchTo('1');
        $this->instalment(self::SHIFT_A, 'debit', '30000.00');
        $this->instalment(self::SHIFT_A, 'bank_transfer', '20000.00');

        $a = $this->reconcile(self::SHIFT_A);

        $this->assertEqualsWithDelta(100_000.0, $a['expected'], 0.001);
        $this->assertEqualsWithDelta(50_000.0, $a['income_total'], 0.001);

        $by_code = array_column($a['presale_payments'], 'trans_amount', 'payment_type_code');
        $this->assertEqualsWithDelta(30_000.0, (float) $by_code['debit'], 0.001);
        $this->assertEqualsWithDelta(20_000.0, (float) $by_code['bank_transfer'], 0.001);
    }

    /**
     * A cash refund (cancellation, D10) leaves the drawer of the shift that paid it out.
     */
    public function testACashRefundLowersTheExpectedOfItsOwnShift(): void
    {
        $this->switchTo('1');
        $this->instalment(self::SHIFT_A, 'cash', '50000.00');
        $this->instalment(self::SHIFT_B, 'cash', '20000.00', 'refund');

        $a = $this->reconcile(self::SHIFT_A);
        $b = $this->reconcile(self::SHIFT_B);

        $this->assertEqualsWithDelta(150_000.0, $a['expected'], 0.001);
        $this->assertEqualsWithDelta(80_000.0, $b['expected'], 0.001);
        $this->assertEqualsWithDelta(-20_000.0, $b['income_total'], 0.001);
        $this->assertEqualsWithDelta(-20_000.0, $b['presale_payments_total'], 0.001);
    }

    /**
     * The delivery sale is paid with 'presale' for what was already paid in instalments. That money
     * was counted in the instalments' shifts; the delivery shift only takes the weight difference.
     */
    public function testTheDeliverySaleDoesNotInflateTheIncomeOfItsShift(): void
    {
        $this->switchTo('1');
        $this->sale(930_101, self::SHIFT_C, COMPLETED, [
            [lang('Sales.presale'), 'presale', 120_000.00, 0.00],
            [lang('Sales.cash'), 'cash', 5_000.00, 0.00],
        ]);

        $c = $this->reconcile(self::SHIFT_C);

        $this->assertEqualsWithDelta(5_000.0, $c['income_total'], 0.001);
        $this->assertEqualsWithDelta(5_000.0, $c['income_cash'], 0.001);
        $this->assertEqualsWithDelta(105_000.0, $c['expected'], 0.001);
        $this->assertEqualsWithDelta(120_000.0, $c['presale_deliveries_total'], 0.001);
        $this->assertNotContains('presale', array_column($c['income'], 'payment_type_code'));
        $this->assertTrue($c['sealed_sales']);
    }

    /**
     * A lighter real weight (T18): the change leaves the drawer on a cash row with no tender.
     */
    public function testChangeGivenOnADeliveryLeavesTheDrawer(): void
    {
        $this->switchTo('1');
        $this->sale(930_102, self::SHIFT_C, COMPLETED, [
            [lang('Sales.presale'), 'presale', 120_000.00, 0.00],
            [lang('Sales.cash'), 'cash', 0.00, 3_000.00],
        ]);

        $c = $this->reconcile(self::SHIFT_C);

        $this->assertEqualsWithDelta(97_000.0, $c['expected'], 0.001);
        $this->assertEqualsWithDelta(-3_000.0, $c['income_total'], 0.001);
    }

    /**
     * Section 6.3: a business that never used presales sees the close it saw before, with the
     * switch off or on. The figures are worked out here from the sales alone, the way the close
     * computed them before presales existed.
     */
    public function testWithoutPresaleDataTheCloseIsWhatItWasBefore(): void
    {
        $this->sale(930_103, self::SHIFT_A, COMPLETED, [[lang('Sales.cash'), 'cash', 299_386.00, 0.00]]);
        $this->sale(930_104, self::SHIFT_A, COMPLETED, [[lang('Sales.debit'), 'debit', 325_450.00, 0.00]]);
        $this->sale(930_105, self::SHIFT_A, CANCELED, [[lang('Sales.cash'), 'cash', 75_200.00, 0.00]]);

        $this->switchTo('0');
        $off = $this->reconcile(self::SHIFT_A, 100_000.0, 399_000.0);

        $this->switchTo('1');
        $on = $this->reconcile(self::SHIFT_A, 100_000.0, 399_000.0);

        $this->assertSame($off, $on);

        $sales = model(Sale::class)->get_payments_by_cashup(self::SHIFT_A);

        $this->assertSame($sales, $off['income']);
        $this->assertEqualsWithDelta(624_836.0, $off['income_total'], 0.001);
        $this->assertEqualsWithDelta(299_386.0, $off['income_cash'], 0.001);
        $this->assertEqualsWithDelta(399_386.0, $off['expected'], 0.001);
        $this->assertEqualsWithDelta(-386.0, $off['discrepancy'], 0.001);
        $this->assertEqualsWithDelta(75_200.0, $off['voided_total'], 0.001);
        $this->assertTrue($off['sealed_sales']);
        $this->assertSame([], $off['presale_payments']);
        $this->assertSame([], $off['presale_deliveries']);
    }

    /**
     * The switch decides whether presales can be used, not whether money already taken exists. An
     * instalment taken before the business switched the module off is still in the drawer.
     */
    public function testSwitchingTheModuleOffDoesNotHideMoneyAlreadyTaken(): void
    {
        $this->instalment(self::SHIFT_A, 'cash', '40000.00');

        $this->switchTo('1');
        $on = $this->reconcile(self::SHIFT_A);

        $this->switchTo('0');
        $off = $this->reconcile(self::SHIFT_A);

        $this->assertSame($on, $off);
        $this->assertEqualsWithDelta(140_000.0, $off['expected'], 0.001);
    }

    /**
     * A shift whose only movement was an instalment has something linked to it, so the screen
     * shows its income instead of the "no sales linked" notice.
     */
    public function testAShiftWithOnlyAnInstalmentIsNotReportedAsEmpty(): void
    {
        $this->switchTo('1');
        $this->instalment(self::SHIFT_A, 'cash', '10000.00');

        $this->assertTrue($this->reconcile(self::SHIFT_A)['sealed_sales']);
    }

    /**
     * What the cashier reads: the instalments block inside the income, and the delivery as an
     * informative line that is not income.
     */
    public function testTheCloseScreenNamesInstalmentsAndDeliveries(): void
    {
        $this->switchTo('1');
        $shift = $this->closedShift(self::SHIFT_C);
        $this->instalment($shift, 'cash', '10000.00');
        $this->sale(930_106, $shift, COMPLETED, [[lang('Sales.presale'), 'presale', 120_000.00, 0.00]]);

        $body = $this->viewShift($shift);

        $this->assertStringContainsString(esc(lang('Cashups.reconciliation_presale_payments')), $body);
        $this->assertStringContainsString(esc(lang('Cashups.reconciliation_presale_deliveries')), $body);
    }

    public function testTheCloseScreenOfABusinessWithoutPresalesShowsNoPresaleLines(): void
    {
        $this->switchTo('0');
        $shift = $this->closedShift(self::SHIFT_C);
        $this->sale(930_107, $shift, COMPLETED, [[lang('Sales.cash'), 'cash', 10_000.00, 0.00]]);

        $body = $this->viewShift($shift);

        $this->assertStringContainsString(esc(lang('Cashups.reconciliation_income')), $body);
        $this->assertStringNotContainsString(esc(lang('Cashups.reconciliation_presale_payments')), $body);
        $this->assertStringNotContainsString(esc(lang('Cashups.reconciliation_presale_deliveries')), $body);
    }

    /**
     * The closing autocomplete (§6.2): Efectivo, Datáfono and Banco include what instalments brought
     * in during the window. Measured as a difference, so other rows in the window do not matter.
     */
    public function testTheClosingAutocompleteAddsTheInstalmentsOfTheWindow(): void
    {
        $this->switchTo('1');
        $shift = $this->openShift(self::SHIFT_C);

        $before = $this->closingFields($this->viewShift($shift));

        $now = date('Y-m-d H:i:s', time() - 60);
        $this->instalment($shift, 'cash', '7000.00', 'payment', $now);
        $this->instalment($shift, 'cash', '1000.00', 'refund', $now);
        $this->instalment($shift, 'debit', '3000.00', 'payment', $now);
        $this->instalment($shift, 'credit', '500.00', 'payment', $now);
        $this->instalment($shift, 'bank_transfer', '2000.00', 'payment', $now);

        $after = $this->closingFields($this->viewShift($shift));

        $this->assertEqualsWithDelta(6_000.0, $after['closed_amount_cash'] - $before['closed_amount_cash'], 0.001);
        $this->assertEqualsWithDelta(3_500.0, $after['closed_amount_card'] - $before['closed_amount_card'], 0.001);
        $this->assertEqualsWithDelta(2_000.0, $after['closed_amount_check'] - $before['closed_amount_check'], 0.001);
    }

    /**
     * Found in the staging certification of 2026-10-07: with two shifts on the same day, the closing
     * autocomplete of the second prefilled the first one's cash instalment, because it read
     * instalments by date window. Instalments carry their shift, so the autocomplete reads by shift.
     */
    public function testTheClosingAutocompleteIgnoresAnotherShiftsInstalmentsOfTheSameDay(): void
    {
        $this->switchTo('1');
        $shift = $this->openShift(self::SHIFT_C);
        $other = $this->openShift(self::SHIFT_B);

        $before = $this->closingFields($this->viewShift($shift));

        $this->instalment($other, 'cash', '10000.00', 'payment', date('Y-m-d H:i:s', time() - 60));

        $after = $this->closingFields($this->viewShift($shift));

        $this->assertEqualsWithDelta(0.0, $after['closed_amount_cash'] - $before['closed_amount_cash'], 0.001);
    }

    private function closedShift(int $cashup_id): int
    {
        return $this->insertShift($cashup_id, 'closed', self::OPEN_DATE, self::CLOSE_DATE);
    }

    private function openShift(int $cashup_id): int
    {
        $open = date('Y-m-d H:i:s', time() - 3600);

        return $this->insertShift($cashup_id, 'open', $open, $open);
    }

    private function insertShift(int $cashup_id, string $status, string $open, string $close): int
    {
        $this->db->table('cash_up')->insert([
            'cashup_id'            => $cashup_id,
            'status'               => $status,
            'open_date'            => $open,
            'close_date'           => $close,
            'open_amount_cash'     => '0.00',
            'transfer_amount_cash' => '0.00',
            'note'                 => 0,
            'closed_amount_cash'   => '0.00',
            'closed_amount_card'   => '0.00',
            'closed_amount_check'  => '0.00',
            'closed_amount_due'    => '0.00',
            'closed_amount_total'  => '0.00',
            'description'          => 'PresaleCashupReconciliationTest fixture',
            'open_employee_id'     => $this->employee_id,
            'close_employee_id'    => $this->employee_id,
            'deleted'              => 0,
        ]);

        $this->cashups[] = $cashup_id;

        return $cashup_id;
    }

    private function viewShift(int $cashup_id): string
    {
        $session = Services::session();
        $session->destroy();
        $session->set('person_id', 1);
        $session->set('menu_group', 'office');
        $this->withSession(['person_id' => 1, 'menu_group' => 'office']);

        $response = $this->get('cashups/view/' . $cashup_id);
        $response->assertStatus(200);

        return (string) $response->getBody();
    }

    /**
     * @return array{closed_amount_cash: float, closed_amount_card: float, closed_amount_check: float}
     */
    private function closingFields(string $body): array
    {
        $fields = [];

        foreach (['closed_amount_cash', 'closed_amount_card', 'closed_amount_check'] as $name) {
            $this->assertMatchesRegularExpression('/<input[^>]*name="' . $name . '"[^>]*>/', $body);
            preg_match('/<input[^>]*name="' . $name . '"[^>]*>/', $body, $input);
            preg_match('/value="([^"]*)"/', $input[0], $value);

            $fields[$name] = (float) parse_decimals(html_entity_decode($value[1] ?? '0'));
        }

        return $fields;
    }
}
