<?php

namespace Tests\Models;

use App\Models\Sale;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * What a shift is told it took in.
 *
 * Cancelling a sale that was already paid leaves the payment row and the shift seal in place -- on
 * purpose, a till needs the record. The bug was that the drawer reconciliation counted those rows
 * as income, so a shift that balanced to the peso reported a shortfall of exactly the cancelled
 * amount. Casaletto's 20 September 2026 close is the worked example: $75,200 voided, $75,200
 * "missing", drawer actually perfect.
 *
 * These tests pin both halves of the split, because dropping the money altogether would be its own
 * bug: what is not income still has to be reachable, or nobody can explain the surplus it creates.
 */
class SalePaymentsByCashupTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const CASHUP_ID = 910_001;

    private Sale $sale;

    private int $employee_id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sale = model(Sale::class);

        // sales.employee_id is a foreign key into employees, so the fixture has to name one that
        // is really there rather than assume the seeded admin kept id 1.
        $employee = $this->db->table('employees')->select('person_id')->limit(1)->get()->getRow();
        $this->employee_id = $employee === null ? 1 : (int)$employee->person_id;

        $this->clear_fixture();
    }

    protected function tearDown(): void
    {
        $this->clear_fixture();

        parent::tearDown();
    }

    /**
     * Only the rows this test made, never a truncate: the sales tables are shared with every other
     * test file in the run.
     */
    private function clear_fixture(): void
    {
        $sale_ids = array_column(
            $this->db->table('sales')->select('sale_id')->where('cashup_id', self::CASHUP_ID)->get()->getResultArray(),
            'sale_id'
        );

        if ($sale_ids !== []) {
            $this->db->table('sales_payments')->whereIn('sale_id', $sale_ids)->delete();
            $this->db->table('sales')->whereIn('sale_id', $sale_ids)->delete();
        }
    }

    private function seal_sale(int $sale_id, int $sale_status, string $payment_type, string $payment_type_code, float $amount, float $cash_refund = 0.0): void
    {
        $this->db->table('sales')->insert([
            'sale_id'     => $sale_id,
            'sale_time'   => '2026-09-20 18:00:00',
            'customer_id' => null,
            'employee_id' => $this->employee_id,
            'comment'     => '',
            'sale_status' => $sale_status,
            'cashup_id'   => self::CASHUP_ID
        ]);

        $this->db->table('sales_payments')->insert([
            'sale_id'           => $sale_id,
            'payment_type'      => $payment_type,
            'payment_type_code' => $payment_type_code,
            'payment_amount'    => $amount,
            'cash_refund'       => $cash_refund
        ]);
    }

    private function total_of(array $rows): float
    {
        return array_sum(array_map(static fn(array $row): float => (float)$row['trans_amount'], $rows));
    }

    public function testIncomeLeavesOutASaleThatWasCancelledAfterBeingPaid(): void
    {
        $this->seal_sale(910_101, COMPLETED, 'Efectivo', 'cash', 299_386.00);
        $this->seal_sale(910_102, CANCELED, 'Efectivo', 'cash', 75_200.00);

        $this->assertSame(299_386.00, $this->total_of($this->sale->get_payments_by_cashup(self::CASHUP_ID)));
    }

    /**
     * The other half: the voided money is not deleted or hidden, it is handed back separately so
     * the screen can name it.
     */
    public function testTheCancelledMoneyIsStillReachableOnItsOwn(): void
    {
        $this->seal_sale(910_101, COMPLETED, 'Efectivo', 'cash', 299_386.00);
        $this->seal_sale(910_102, CANCELED, 'Efectivo', 'cash', 75_200.00);

        $voided = $this->sale->get_voided_payments_by_cashup(self::CASHUP_ID);

        $this->assertSame(75_200.00, $this->total_of($voided));
        $this->assertSame('Efectivo', $voided[0]['payment_type']);
    }

    /**
     * A shift with nothing cancelled must come out of this change untouched -- that is every shift
     * of every business that has never voided a paid sale.
     */
    public function testAShiftWithNothingCancelledIsUnchanged(): void
    {
        $this->seal_sale(910_101, COMPLETED, 'Efectivo', 'cash', 299_386.00);
        $this->seal_sale(910_103, COMPLETED, 'Tarjeta de débito', 'debit', 325_450.00);

        $this->assertSame(624_836.00, $this->total_of($this->sale->get_payments_by_cashup(self::CASHUP_ID)));
        $this->assertSame([], $this->sale->get_voided_payments_by_cashup(self::CASHUP_ID));
    }

    /**
     * A return is a completed sale with a negative payment. That cash really does leave the drawer,
     * so narrowing income to completed sales must not drop it.
     */
    public function testAReturnStillCountsAgainstTheDrawer(): void
    {
        $this->seal_sale(910_101, COMPLETED, 'Efectivo', 'cash', 299_386.00);
        $this->seal_sale(910_104, COMPLETED, 'Efectivo', 'cash', -20_000.00);

        $this->assertSame(279_386.00, $this->total_of($this->sale->get_payments_by_cashup(self::CASHUP_ID)));
    }

    /**
     * Change handed back was never in the drawer, and the split must not have changed that.
     */
    public function testChangeGivenBackIsStillNettedOff(): void
    {
        $this->seal_sale(910_101, COMPLETED, 'Efectivo', 'cash', 100_000.00, 40_500.00);

        $this->assertSame(59_500.00, $this->total_of($this->sale->get_payments_by_cashup(self::CASHUP_ID)));
    }

    /**
     * Nothing sealed at all is a different situation from "everything sealed here was cancelled",
     * and both have to be distinguishable by the caller.
     */
    public function testAnEmptyShiftReportsNeitherIncomeNorVoids(): void
    {
        $this->assertSame([], $this->sale->get_payments_by_cashup(self::CASHUP_ID));
        $this->assertSame([], $this->sale->get_voided_payments_by_cashup(self::CASHUP_ID));
    }

    /**
     * The voided rows keep the payment-type breakdown, since only the cash part can still be
     * sitting in the drawer.
     */
    public function testVoidedMoneyKeepsItsPaymentTypes(): void
    {
        $this->seal_sale(910_102, CANCELED, 'Efectivo', 'cash', 75_200.00);
        $this->seal_sale(910_105, CANCELED, 'Transferencia Bancaria', 'bank_transfer', 100_100.00);

        $voided = $this->sale->get_voided_payments_by_cashup(self::CASHUP_ID);
        $by_code = array_column($voided, 'trans_amount', 'payment_type_code');

        $this->assertSame(75_200.00, (float)$by_code['cash']);
        $this->assertSame(100_100.00, (float)$by_code['bank_transfer']);
        $this->assertSame(175_300.00, $this->total_of($voided));
    }
}
