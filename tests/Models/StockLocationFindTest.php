<?php

namespace Tests\Models;

use App\Models\Stock_location;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Resolving a site for an employee who may have no location grant at all.
 *
 * Stock_location::get_default_location_id() dereferences ->location_id on a row that may not exist
 * (its own TODO: "this is puking"). An order-ticket waiter is granted order_tickets and no sales
 * location, so for them that row never exists. These two finders answer null instead, and the
 * caller falls back.
 *
 * Read-only: nothing here writes, so the shared test database is left as it was.
 *
 * @internal
 */
final class StockLocationFindTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private Stock_location $locations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->resetDataCache();
        $this->locations = model(Stock_location::class, false);
    }

    /**
     * The seeded administrator holds sales_stock on location 1 (initial_schema.sql).
     */
    public function testAnEmployeeWithASalesLocationGrantGetsThatLocation(): void
    {
        $this->assertSame(1, $this->locations->find_granted_location_id(1, 'sales'));
    }

    /**
     * The case get_default_location_id() cannot survive: no grant, no row. Null, not a crash.
     */
    public function testAnEmployeeWithNoLocationGrantGetsNullInsteadOfAnError(): void
    {
        $this->assertNull($this->locations->find_granted_location_id(987654, 'sales'));
    }

    public function testTheFirstActiveLocationIsTheLowestUndeletedOne(): void
    {
        $expected = $this->db->table('stock_locations')
            ->selectMin('location_id')
            ->where('deleted', 0)
            ->get()->getRow()->location_id;

        $this->assertSame((int) $expected, $this->locations->find_first_active_location_id());
    }
}
