<?php

namespace Tests\Models;

use App\Models\Reports\Income_expenses;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\OSPOS;

/**
 * Income vs expenses, cash mode, with presales (docs/Tecnico/venta-anticipada.md §7.5).
 *
 * Cash mode answers "what did we collect, and when". A presale is collected in instalments, on
 * their own dates; the delivery sale is paid with code 'presale' for money that came in earlier.
 * So the instalments count on their payment_time, and the 'presale' payment never counts.
 *
 * Accrual mode is untouched (D7): the delivery sale counts on the delivery date, like any sale.
 *
 * The fixture lives in February 2001, which no other test uses, and is deleted in tearDown.
 *
 * @internal
 */
final class IncomeExpensesPresaleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const SALE_ID = 931_001;
    private const PRESALE = 931_500;

    private int $employee_id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->resetDataCache();
        config(OSPOS::class)->update_settings();

        $employee          = $this->db->table('employees')->select('person_id')->limit(1)->get()->getRow();
        $this->employee_id = $employee === null ? 1 : (int) $employee->person_id;

        $this->clearFixture();
        $this->buildFixture();
    }

    protected function tearDown(): void
    {
        $this->clearFixture();

        parent::tearDown();
    }

    private function clearFixture(): void
    {
        $this->db->table('sales_payments')->where('sale_id', self::SALE_ID)->delete();
        $this->db->table('sales')->where('sale_id', self::SALE_ID)->delete();
        $this->db->table('presale_payments')->where('presale_id', self::PRESALE)->delete();
    }

    /**
     * Instalments: 50,000 cash on the 10th, 70,000 debit on the 15th, a 10,000 cash refund on the
     * 16th. Delivery on the 20th: 120,000 paid with 'presale' plus 5,000 cash for extra weight.
     */
    private function buildFixture(): void
    {
        foreach ([
            ['payment', 'cash', '50000.00', '2001-02-10 10:00:00'],
            ['payment', 'debit', '70000.00', '2001-02-15 10:00:00'],
            ['refund', 'cash', '10000.00', '2001-02-16 10:00:00'],
        ] as [$kind, $code, $amount, $time]) {
            $this->db->table('presale_payments')->insert([
                'presale_id'        => self::PRESALE,
                'kind'              => $kind,
                'payment_type_code' => $code,
                'amount'            => $amount,
                'payment_time'      => $time,
                'employee_id'       => $this->employee_id,
                'cashup_id'         => 931_001,
            ]);
        }

        $this->db->table('sales')->insert([
            'sale_id'     => self::SALE_ID,
            'sale_time'   => '2001-02-20 12:00:00',
            'customer_id' => null,
            'employee_id' => $this->employee_id,
            'comment'     => '',
            'sale_status' => COMPLETED,
        ]);

        foreach ([[lang('Sales.presale'), 'presale', 120_000.00], [lang('Sales.cash'), 'cash', 5_000.00]] as [$label, $code, $amount]) {
            $this->db->table('sales_payments')->insert([
                'sale_id'           => self::SALE_ID,
                'payment_type'      => $label,
                'payment_type_code' => $code,
                'payment_amount'    => $amount,
                'cash_refund'       => 0,
            ]);
        }
    }

    /**
     * @return array<string, float> income by day
     */
    private function incomeByDay(array $codes): array
    {
        $rows = model(Income_expenses::class)->getData([
            'start_date'      => '2001-02-01',
            'end_date'        => '2001-02-27',
            'granularity'     => 'day',
            'include_deleted' => false,
            'payment_codes'   => $codes,
        ]);

        return array_column($rows, 'income', 'period_key');
    }

    public function testInstalmentsCountOnTheirOwnDatesAndThePresalePaymentNever(): void
    {
        $income = $this->incomeByDay(['cash', 'debit', 'presale']);

        $this->assertEqualsWithDelta(50_000.0, $income['2001-02-10'] ?? 0.0, 0.001);
        $this->assertEqualsWithDelta(70_000.0, $income['2001-02-15'] ?? 0.0, 0.001);
        $this->assertEqualsWithDelta(-10_000.0, $income['2001-02-16'] ?? 0.0, 0.001);
        $this->assertEqualsWithDelta(5_000.0, $income['2001-02-20'] ?? 0.0, 0.001, 'Only the extra weight paid at delivery.');
    }

    /**
     * The payment-type filter applies to instalments as it does to sales.
     */
    public function testThePaymentTypeFilterAppliesToInstalments(): void
    {
        $income = $this->incomeByDay(['cash']);

        $this->assertEqualsWithDelta(50_000.0, $income['2001-02-10'] ?? 0.0, 0.001);
        $this->assertArrayNotHasKey('2001-02-15', $income);
        $this->assertEqualsWithDelta(-10_000.0, $income['2001-02-16'] ?? 0.0, 0.001);
        $this->assertEqualsWithDelta(5_000.0, $income['2001-02-20'] ?? 0.0, 0.001);
    }

    /**
     * Filtering by 'presale' alone collects nothing: that code is never money received.
     */
    public function testFilteringByThePresaleCodeAloneShowsNoIncome(): void
    {
        $this->assertSame([], array_filter($this->incomeByDay(['presale'])));
    }

    /**
     * Accrual mode (D7): the instalments are not invoiced income on their dates.
     */
    public function testAccrualModeDoesNotCountInstalments(): void
    {
        $income = $this->incomeByDay([]);

        $this->assertArrayNotHasKey('2001-02-10', $income);
        $this->assertArrayNotHasKey('2001-02-15', $income);
        $this->assertArrayNotHasKey('2001-02-16', $income);
    }
}
