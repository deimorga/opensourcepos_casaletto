<?php

namespace Tests\Models;

use App\Models\Expense;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\OSPOS;

/**
 * Covers the expense grid's category filter.
 *
 * The ids arrive as filter keys named category_<id>, because the grid hands every filter through
 * one multiselect and the query string is whatever the sender makes it. Anything not of that exact
 * shape has to be ignored rather than reaching the query.
 */
class ExpenseCategoryFilterTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $namespace   = 'App';

    private array $categoryIds = [];
    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect();
        $db->resetDataCache();
        config(OSPOS::class)->update_settings();

        // Rows from earlier tests in the class outlive them, so each test tags its own and searches
        // for that tag rather than trusting the table to be empty.
        $this->marker = 'catfilter-' . uniqid();

        foreach (['A', 'B', 'C'] as $name) {
            $db->table('expense_categories')->insert([
                'category_name'        => $this->marker . '-' . $name,
                'category_description' => 'fixture',
                'deleted'              => 0
            ]);
            $this->categoryIds[$name] = (int) $db->insertID();
        }

        foreach (['A' => 100.00, 'B' => 200.00, 'C' => 400.00] as $name => $amount) {
            $db->table('expenses')->insert([
                'date'                => '2026-05-01 12:00:00',
                'amount'              => $amount,
                'tax_amount'          => 0,
                'payment_type'        => 'Cash',
                'payment_type_code'   => 'cash',
                'cash_source'         => 'register',
                'expense_category_id' => $this->categoryIds[$name],
                'description'         => $this->marker,
                'employee_id'         => 1,
                'deleted'             => 0
            ]);
        }
    }

    private function search(array $extra): array
    {
        $filters = array_merge([
            'start_date' => '2026-05-01',
            'end_date'   => '2026-05-01',
            'is_deleted' => false
        ], $extra);

        $rows = model(Expense::class)->search($this->marker, $filters, 100, 0, 'expense_id', 'asc');
        $amounts = [];

        foreach ($rows->getResult() as $row) {
            $amounts[] = (float) $row->amount;
        }

        sort($amounts);

        return $amounts;
    }

    public function testNoCategoryTickedReturnsEverything(): void
    {
        $this->assertSame([100.0, 200.0, 400.0], $this->search([]));
    }

    public function testOneCategoryNarrowsToIt(): void
    {
        $this->assertSame([200.0], $this->search(['category_' . $this->categoryIds['B'] => true]));
    }

    /**
     * Ticking two means "either", the same reading the cash source filters use.
     */
    public function testTwoCategoriesReturnTheUnion(): void
    {
        $this->assertSame([100.0, 400.0], $this->search([
            'category_' . $this->categoryIds['A'] => true,
            'category_' . $this->categoryIds['C'] => true
        ]));
    }

    /**
     * A key of the right shape but switched off must not narrow anything, or unticking a box in the
     * grid would still filter by it.
     */
    public function testAnUncheckedCategoryIsNotApplied(): void
    {
        $this->assertSame([100.0, 200.0, 400.0], $this->search(['category_' . $this->categoryIds['B'] => false]));
    }

    /**
     * The query string is whatever the sender makes it. Only category_<digits> is honoured; nothing
     * else reaches the query, so no column name or fragment can be smuggled through.
     */
    public function testKeysThatAreNotTheExpectedShapeAreIgnored(): void
    {
        $everything = [100.0, 200.0, 400.0];

        $this->assertSame($everything, $this->search(['category_' => true]));
        $this->assertSame($everything, $this->search(['category_abc' => true]));
        $this->assertSame($everything, $this->search(['category_1 OR 1=1' => true]));
        $this->assertSame($everything, $this->search(['category_1; DROP TABLE ospos_expenses; --' => true]));
        $this->assertSame($everything, $this->search(['expense_category_id' => true]));

        $this->assertSame(
            3,
            db_connect()->table('expenses')->where('description', $this->marker)->countAllResults(),
            'The table is still standing and still holds this fixture.'
        );
    }

    /**
     * A category nobody filed anything under returns nothing rather than everything.
     */
    public function testACategoryWithNoExpensesReturnsNothing(): void
    {
        $this->assertSame([], $this->search(['category_999999' => true]));
    }
}
