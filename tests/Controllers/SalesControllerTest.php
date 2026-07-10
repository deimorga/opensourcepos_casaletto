<?php

namespace Tests\Controllers;

use App\Models\Appconfig;
use App\Models\Sale;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\OSPOS;

/**
 * Covers the parallel-sales-tabs feature (docs/Funcional and docs/Tecnico
 * ventas-en-paralelo-pestanas.md): opening a restaurant table autosaves it
 * as OPENED, switching tables loads the target table's own cart instead of
 * relabeling the current one (the suspected root cause of upstream issue
 * #1933), and completing a sale removes it from the open tabs list.
 */
class SalesControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;
    protected $namespace   = null;

    private int $tableA;
    private int $tableB;
    private string $itemNumberA = 'TEST-TAB-ITEM-A';
    private string $itemNumberB = 'TEST-TAB-ITEM-B';

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect();

        // DatabaseTestTrait migrates on a fresh connection, but the pooled
        // 'tests' group connection may have cached an empty table list from
        // before the migration ran (CI4's tableExists() caches per-connection
        // by default). Without this, Config\OSPOS::set_settings() sees
        // tableExists('app_config') === false and falls back to a handful of
        // hardcoded defaults missing keys like smtp_pass, which crashes
        // Email_lib's constructor the moment a controller boots.
        $db->resetDataCache();

        // Enable dinner table mode -- disabled by default (see
        // sqlscripts/3.0.2_to_3.1.1.sql). Use the real model so the cached
        // OSPOS config object gets refreshed too, since Sales::__construct()
        // reads config(OSPOS::class)->settings.
        model(Appconfig::class)->save(['dinner_table_enable' => '1']);
        config(OSPOS::class)->update_settings();

        // Table ids 1 and 2 are the reserved Delivery/Take Away rows (see
        // Dinner_table::occupy(), "$dinner_table_id > 2") -- create two real,
        // occupiable tables for this test.
        $db->table('dinner_tables')->insert(['name' => 'Test Table A', 'status' => 0, 'deleted' => 0]);
        $this->tableA = (int) $db->insertID();

        $db->table('dinner_tables')->insert(['name' => 'Test Table B', 'status' => 0, 'deleted' => 0]);
        $this->tableB = (int) $db->insertID();

        // One sellable, in-stock item per table so postAdd() succeeds.
        $this->createTestItem($this->itemNumberA, 'Test Tab Item A');
        $this->createTestItem($this->itemNumberB, 'Test Tab Item B');

        $this->loginAsAdmin();
    }

    private function createTestItem(string $itemNumber, string $name): void
    {
        $db = db_connect();

        $db->table('items')->insert([
            'name'                   => $name,
            'category'               => 'Test',
            'item_number'            => $itemNumber,
            'description'            => 'Fixture item for SalesControllerTest',
            'cost_price'             => '1.00',
            'unit_price'             => '10.00',
            'reorder_level'          => '0',
            'receiving_quantity'     => '1',
            'allow_alt_description'  => 0,
            'is_serialized'          => 0
        ]);
        $itemId = (int) $db->insertID();

        // location_id 1 = the default 'stock' location seeded by
        // sqlscripts/initial_schema.sql. Plenty of quantity so stock checks
        // never block the test.
        $db->table('item_quantities')->insert([
            'item_id'     => $itemId,
            'location_id' => 1,
            'quantity'    => '1000'
        ]);
    }

    private function loginAsAdmin(): void
    {
        // FeatureTestTrait::call() -> populateGlobals() overwrites $_SESSION
        // with $this->session on *every* request (see FeatureTestTrait.php,
        // "$_SESSION = $this->session;"). withSession() only sets that
        // property once, so without re-arming it before each call() below
        // (see postReq()/getReq()), every request after the first would wipe
        // out whatever the app itself just wrote to session (dinner_table,
        // sale_id, ...), silently resetting state mid-test.
        $this->withSession(['person_id' => 1, 'menu_group' => 'home']);

        // A real cashier always lands on Sales::getIndex() first, which seeds
        // sale_id/cart state in session via clear_all(). Sale_lib::get_sale_id()
        // is typed to always return int, so jumping straight to changeMode on a
        // session that never went through the index page throws a TypeError.
        $this->getReq('sales');
    }

    /**
     * GET wrapper that re-arms withSession() with the live $_SESSION left
     * behind by the previous request -- see loginAsAdmin() for why this is
     * necessary instead of calling $this->get() directly.
     */
    private function getReq(string $path): TestResponse
    {
        $this->withSession($_SESSION);

        return $this->get($path);
    }

    /**
     * POST wrapper -- see getReq().
     */
    private function postReq(string $path, array $params): TestResponse
    {
        $this->withSession($_SESSION);

        return $this->post($path, $params);
    }

    private function openTableWithItem(int $tableId, string $itemNumber): void
    {
        $this->postReq('sales/changeMode', ['mode' => 'sale', 'dinner_table' => $tableId]);
        $this->postReq('sales/add', ['item' => $itemNumber]);
    }

    public function testOpeningTableAutosavesAsOpenedStatus(): void
    {
        $this->openTableWithItem($this->tableA, $this->itemNumberA);

        $saleId = model(Sale::class)->get_open_sale_by_table($this->tableA);

        $this->assertNotNull($saleId, 'Adding an item to a table should immediately persist an OPENED sale row, not just the session cart.');

        $db     = db_connect();
        $status = $db->table('sales')->select('sale_status')->where('sale_id', $saleId)->get()->getRow()->sale_status;

        $this->assertEquals(OPENED, (int) $status);
    }

    public function testSwitchingTablesPreservesEachTabIndependently(): void
    {
        // Open table A and add its item.
        $this->openTableWithItem($this->tableA, $this->itemNumberA);
        $saleIdA = model(Sale::class)->get_open_sale_by_table($this->tableA);
        $this->assertNotNull($saleIdA);

        // Switch to table B (empty) and add a different item.
        $this->openTableWithItem($this->tableB, $this->itemNumberB);
        $saleIdB = model(Sale::class)->get_open_sale_by_table($this->tableB);
        $this->assertNotNull($saleIdB);
        $this->assertNotEquals($saleIdA, $saleIdB, 'Each table must get its own sale row, not share one.');

        // Switch back to table A without adding anything -- postChangeMode
        // should load table A's own persisted cart (copy_entire_sale), not
        // just relabel table B's cart with table A's id (the bug hypothesis
        // for upstream issue #1933).
        $response = $this->postReq('sales/changeMode', ['mode' => 'sale', 'dinner_table' => $this->tableA]);

        $response->assertOK();
        $response->assertSee($this->itemNumberA);
        $response->assertDontSee($this->itemNumberB);

        // Table B's row must still exist untouched, independent of A.
        $this->assertEquals($saleIdB, model(Sale::class)->get_open_sale_by_table($this->tableB));

        $db    = db_connect();
        $itemsInSaleB = $db->table('sales_items')->where('sale_id', $saleIdB)->countAllResults();
        $this->assertGreaterThan(0, $itemsInSaleB, 'Table B must keep its own items after switching away and back to table A.');
    }

    public function testAllThreeTablesStayIndependentWhenAlternatedRepeatedly(): void
    {
        // Regression test for the >2-table scenario called out in
        // docs/Tecnico/ventas-en-paralelo-pestanas.md section 4.3/6
        // (upstream issue #1933, "only works with 2 tables").
        $db = db_connect();
        $db->table('dinner_tables')->insert(['name' => 'Test Table C', 'status' => 0, 'deleted' => 0]);
        $tableC = (int) $db->insertID();
        $itemNumberC = 'TEST-TAB-ITEM-C';
        $this->createTestItem($itemNumberC, 'Test Tab Item C');

        $this->openTableWithItem($this->tableA, $this->itemNumberA);
        $this->openTableWithItem($this->tableB, $this->itemNumberB);
        $this->openTableWithItem($tableC, $itemNumberC);

        // Alternate back and forth a few times.
        $this->postReq('sales/changeMode', ['mode' => 'sale', 'dinner_table' => $this->tableA]);
        $this->postReq('sales/changeMode', ['mode' => 'sale', 'dinner_table' => $tableC]);
        $responseB = $this->postReq('sales/changeMode', ['mode' => 'sale', 'dinner_table' => $this->tableB]);
        $responseA = $this->postReq('sales/changeMode', ['mode' => 'sale', 'dinner_table' => $this->tableA]);
        $responseC = $this->postReq('sales/changeMode', ['mode' => 'sale', 'dinner_table' => $tableC]);

        $responseB->assertSee($this->itemNumberB);
        $responseB->assertDontSee($this->itemNumberA);
        $responseB->assertDontSee($itemNumberC);

        $responseA->assertSee($this->itemNumberA);
        $responseA->assertDontSee($this->itemNumberB);
        $responseA->assertDontSee($itemNumberC);

        $responseC->assertSee($itemNumberC);
        $responseC->assertDontSee($this->itemNumberA);
        $responseC->assertDontSee($this->itemNumberB);
    }

    public function testCompletingSaleRemovesItFromOpenTabs(): void
    {
        $this->openTableWithItem($this->tableA, $this->itemNumberA);
        $saleId = model(Sale::class)->get_open_sale_by_table($this->tableA);
        $this->assertNotNull($saleId);

        $this->postReq('sales/addPayment', ['payment_type' => 'Cash', 'amount_tendered' => '10.00']);
        $this->postReq('sales/complete', []);

        $this->assertNull(
            model(Sale::class)->get_open_sale_by_table($this->tableA),
            'Completing the sale must clear the table from the open tabs list.'
        );

        $openTabs = model(Sale::class)->get_all_opened();
        $openTableIds = array_column($openTabs, 'dinner_table_id');
        $this->assertNotContains($this->tableA, $openTableIds);
    }
}
