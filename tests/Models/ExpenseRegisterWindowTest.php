<?php

namespace Tests\Models;

use App\Models\Expense;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\OSPOS;

/**
 * Covers the total the cash-up reconciliation subtracts from the drawer.
 *
 * The reconciliation used to read this through get_payments_summary(), which honours the
 * date_or_time_format setting: with the setting empty it compares DATE_FORMAT(date, '%Y-%m-%d')
 * against whatever bounds it is given. Handed a bound carrying a time, that compares strings of
 * different lengths -- '2026-08-23' sorts before '2026-08-23 15:49:52' -- so nothing from the
 * current day fell inside the window and the drawer reported zero expenses however many had been
 * paid from it. On staging that hid 28,886 pesos.
 */
class ExpenseRegisterWindowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect();
        $db->resetDataCache();
        config(OSPOS::class)->update_settings();

        $db->table('expense_categories')->insert([
            'category_name'        => 'Reconciliation window fixture',
            'category_description' => 'Fixture',
            'deleted'              => 0
        ]);
        $this->categoryId = (int) $db->insertID();
    }

    private function expense(string $date, float $amount, ?string $source, int $deleted = 0): void
    {
        db_connect()->table('expenses')->insert([
            'date'                => $date,
            'amount'              => $amount,
            'tax_amount'          => 0,
            'payment_type'        => 'Cash',
            'payment_type_code'   => $source === null ? 'bank_transfer' : 'cash',
            'cash_source'         => $source,
            'expense_category_id' => $this->categoryId,
            'description'         => 'fixture',
            'employee_id'         => 1,
            'deleted'             => $deleted
        ]);
    }

    /**
     * The case that shipped broken: a shift that opened and closed on the same day.
     */
    public function testCountsExpensesPaidWithinTheSameDayWindow(): void
    {
        $this->expense('2026-08-23 16:28:50', 5555.00, 'register');
        $this->expense('2026-08-23 16:32:17', 7777.00, 'register');

        $total = model(Expense::class)->get_register_total_between('2026-08-23 15:49:52', '2026-08-23 16:53:28');

        $this->assertSame(13332.00, $total);
    }

    public function testLeavesOutWhatFallsOutsideTheWindow(): void
    {
        $this->expense('2026-08-23 09:00:00', 1000.00, 'register');   // antes de abrir
        $this->expense('2026-08-23 16:30:00', 2000.00, 'register');   // dentro
        $this->expense('2026-08-23 22:00:00', 4000.00, 'register');   // después de cerrar

        $total = model(Expense::class)->get_register_total_between('2026-08-23 15:49:52', '2026-08-23 16:53:28');

        $this->assertSame(2000.00, $total);
    }

    /**
     * Money paid from cash already collected never sat in this drawer, so it must not be
     * subtracted from it. Nor must a non-cash expense, which carries no source at all.
     */
    public function testOnlyDrawerCashCounts(): void
    {
        $this->expense('2026-08-23 16:00:00', 3000.00, 'register');
        $this->expense('2026-08-23 16:05:00', 9000.00, 'collected');
        $this->expense('2026-08-23 16:10:00', 8000.00, null);

        $total = model(Expense::class)->get_register_total_between('2026-08-23 15:49:52', '2026-08-23 16:53:28');

        $this->assertSame(3000.00, $total);
    }

    public function testDeletedExpensesDoNotCount(): void
    {
        $this->expense('2026-08-23 16:00:00', 3000.00, 'register');
        $this->expense('2026-08-23 16:05:00', 5000.00, 'register', 1);

        $total = model(Expense::class)->get_register_total_between('2026-08-23 15:49:52', '2026-08-23 16:53:28');

        $this->assertSame(3000.00, $total);
    }

    public function testAWindowWithNothingInItReturnsZeroNotNull(): void
    {
        $total = model(Expense::class)->get_register_total_between('2026-01-01 00:00:00', '2026-01-02 00:00:00');

        $this->assertSame(0.0, $total);
    }

    /**
     * Both ends inclusive: an expense recorded at the exact moment the shift opened belongs to it.
     */
    public function testBoundsAreInclusive(): void
    {
        $this->expense('2026-08-23 15:49:52', 100.00, 'register');
        $this->expense('2026-08-23 16:53:28', 200.00, 'register');

        $total = model(Expense::class)->get_register_total_between('2026-08-23 15:49:52', '2026-08-23 16:53:28');

        $this->assertSame(300.00, $total);
    }
}
