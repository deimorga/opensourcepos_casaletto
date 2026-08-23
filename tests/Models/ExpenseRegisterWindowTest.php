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
 * paid from it. On staging that hid 28,886 pesos behind a "-$0.00".
 *
 * Each test works on a day of its own. $migrateOnce keeps the class's rows between tests, and a
 * shared day would let one test's fixtures land inside another's window -- which is precisely the
 * thing under test here, so it cannot be left to chance.
 */
class ExpenseRegisterWindowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $namespace   = 'App';

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect();
        $db->resetDataCache();
        config(OSPOS::class)->update_settings();

        // category_name is a unique key and the class's rows outlive each test, so a fixed name
        // would collide on the second one.
        $db->table('expense_categories')->insert([
            'category_name'        => 'Reconciliation window ' . uniqid(),
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
            'payment_type'        => $source === null ? 'Bank Transfer' : 'Cash',
            'payment_type_code'   => $source === null ? 'bank_transfer' : 'cash',
            'cash_source'         => $source,
            'expense_category_id' => $this->categoryId,
            'description'         => 'fixture',
            'employee_id'         => 1,
            'deleted'             => $deleted
        ]);
    }

    private function total(string $day): float
    {
        return model(Expense::class)->get_register_total_between("$day 15:49:52", "$day 16:53:28");
    }

    /**
     * The case that shipped broken: a shift that opened and closed on the same day.
     */
    public function testCountsExpensesPaidWithinTheSameDayWindow(): void
    {
        $this->expense('2026-03-01 16:28:50', 5555.00, 'register');
        $this->expense('2026-03-01 16:32:17', 7777.00, 'register');

        $this->assertSame(13332.00, $this->total('2026-03-01'));
    }

    public function testLeavesOutWhatFallsOutsideTheWindow(): void
    {
        $this->expense('2026-03-02 09:00:00', 1000.00, 'register');   // antes de abrir
        $this->expense('2026-03-02 16:30:00', 2000.00, 'register');   // dentro
        $this->expense('2026-03-02 22:00:00', 4000.00, 'register');   // después de cerrar

        $this->assertSame(2000.00, $this->total('2026-03-02'));
    }

    /**
     * Money paid from cash already collected never sat in this drawer, so it must not be
     * subtracted from it. Nor must a non-cash expense, which carries no source at all.
     */
    public function testOnlyDrawerCashCounts(): void
    {
        $this->expense('2026-03-03 16:00:00', 3000.00, 'register');
        $this->expense('2026-03-03 16:05:00', 9000.00, 'collected');
        $this->expense('2026-03-03 16:10:00', 8000.00, null);

        $this->assertSame(3000.00, $this->total('2026-03-03'));
    }

    public function testDeletedExpensesDoNotCount(): void
    {
        $this->expense('2026-03-04 16:00:00', 3000.00, 'register');
        $this->expense('2026-03-04 16:05:00', 5000.00, 'register', 1);

        $this->assertSame(3000.00, $this->total('2026-03-04'));
    }

    public function testAWindowWithNothingInItReturnsZeroNotNull(): void
    {
        $this->assertSame(0.0, $this->total('2026-03-05'));
    }

    /**
     * Both ends inclusive: an expense recorded at the exact moment the shift opened belongs to it.
     */
    public function testBoundsAreInclusive(): void
    {
        $this->expense('2026-03-06 15:49:52', 100.00, 'register');
        $this->expense('2026-03-06 16:53:28', 200.00, 'register');

        $this->assertSame(300.00, $this->total('2026-03-06'));
    }
}
