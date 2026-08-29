<?php

namespace Tests\Libraries;

use App\Libraries\Sale_lib;
use App\Models\Appconfig;
use CodeIgniter\Session\Handlers\ArrayHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\Mock\MockSession;
use Config\OSPOS;
use Config\Services;

/**
 * Covers the defect in docs/Tecnico/venta-por-peso-y-hardware-de-caja.md
 * section 2.4: Sale_lib::add_item() summing an item that is already in the
 * cart with a bare bcadd() and no scale.
 *
 * The global bcmath scale is derived from *money* settings
 * (Load_config.php: max(2, currency_decimals + tax_decimals)), so for a
 * greengrocer it sits at 2 and every repeat weighing loses its third decimal.
 * Two bags of 735 g and 740 g were being stored as 1.47 kg instead of
 * 1.475 kg -- five grams per repetition, in the single most common operation
 * of the shop, with nothing on screen to show for it.
 */
class SaleLibQuantityPrecisionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private const LOCATION = 1;    // The 'stock' location seeded by initial_schema.sql

    protected function setUp(): void
    {
        parent::setUp();

        // See SalesControllerTest::setUp() -- the pooled 'tests' connection
        // can cache a pre-migration table list, which makes
        // Config\OSPOS::set_settings() fall back to hardcoded defaults.
        db_connect()->resetDataCache();

        // Sale_lib keeps the cart in the session. An in-memory session keeps
        // this test at the library boundary instead of dragging a full HTTP
        // request in just to get somewhere to put the cart.
        $sessionConfig = config('Session');
        $session       = new MockSession(new ArrayHandler($sessionConfig, '0.0.0.0'), $sessionConfig);
        // Session::start() logs, and LoggerAwareTrait leaves $logger null
        // until something sets it -- without this the very first start() dies
        // with "Call to a member function debug() on null".
        $session->setLogger(service('logger'));
        $session->start();
        Services::injectMock('session', $session);
    }

    /**
     * Casaletto: sells by the unit, quantity_decimals = 0.
     */
    private function useUnitTenant(): void
    {
        $this->useTenantSettings('0');
    }

    /**
     * The greengrocer: sells by weight, quantity_decimals = 3.
     */
    private function useWeightTenant(): void
    {
        $this->useTenantSettings('3');
    }

    private function useTenantSettings(string $quantityDecimals): void
    {
        // batch_save(), not save(): Appconfig::save() only ever stores the
        // first key of the array it is handed (see Appconfig.php), so passing
        // three settings to it would silently persist just one.
        model(Appconfig::class)->batch_save([
            'quantity_decimals' => $quantityDecimals,
            'currency_decimals' => '0',
            'tax_decimals'      => '2'
        ]);
        config(OSPOS::class)->update_settings();

        // Reproduce the global scale the pre_system event would have set
        // (Load_config.php) -- for this tenant it is 2, the very scale that
        // truncates the third decimal. Without this the test would run at
        // PHP's default scale of 0 and would not reproduce production.
        bcscale(max(2, totals_decimals() + tax_decimals()));
    }

    private function createItem(string $itemNumber): int
    {
        $db = db_connect();

        $db->table('items')->insert([
            'name'                  => 'Fixture ' . $itemNumber,
            'category'              => 'Test',
            'item_number'           => $itemNumber,
            'description'           => 'Fixture item for SaleLibQuantityPrecisionTest',
            'cost_price'            => '1000.00',
            'unit_price'            => '4500.00',
            'reorder_level'         => '0',
            'receiving_quantity'    => '1',
            'allow_alt_description' => 0,
            'is_serialized'         => 0,
            'stock_type'            => HAS_STOCK,
            'item_type'             => ITEM
        ]);
        $itemId = (int) $db->insertID();

        $db->table('item_quantities')->insert([
            'item_id'     => $itemId,
            'location_id' => self::LOCATION,
            'quantity'    => '1000'
        ]);

        return $itemId;
    }

    /**
     * add_item() takes $item_id and $discount by reference, so both need to
     * be variables.
     */
    private function addToCart(Sale_lib $saleLib, int $itemId, string $quantity): void
    {
        $itemRef     = (string) $itemId;
        $discountRef = '0.00';

        $added = $saleLib->add_item($itemRef, self::LOCATION, $quantity, $discountRef);

        $this->assertTrue($added, 'The fixture item must be addable to the cart; a false here means the test setup is wrong, not the arithmetic.');
    }

    private function cartQuantityOf(Sale_lib $saleLib, int $itemId): string
    {
        foreach ($saleLib->get_cart() as $line) {
            if ((int) $line['item_id'] === $itemId) {
                return (string) $line['quantity'];
            }
        }

        $this->fail('Item ' . $itemId . ' is not in the cart.');
    }

    /**
     * The headline case: two bags of tomatoes off the same scale.
     */
    public function testWeighingTheSameProductTwiceKeepsTheThirdDecimal(): void
    {
        $this->useWeightTenant();
        $itemId  = $this->createItem('TEST-CART-TOMATO');
        $saleLib = new Sale_lib();

        $this->addToCart($saleLib, $itemId, '0.735');
        $this->addToCart($saleLib, $itemId, '0.740');

        $this->assertSame(
            0,
            bccomp('1.475', $this->cartQuantityOf($saleLib, $itemId), 3),
            'Two bags of 735 g and 740 g must total 1.475 kg; 1.47 means the third decimal was truncated by the money-derived global scale.'
        );
    }

    public function testTheSameProductWeighedThreeTimesDoesNotAccumulateError(): void
    {
        $this->useWeightTenant();
        $itemId  = $this->createItem('TEST-CART-POTATO');
        $saleLib = new Sale_lib();

        $this->addToCart($saleLib, $itemId, '0.735');
        $this->addToCart($saleLib, $itemId, '0.740');
        $this->addToCart($saleLib, $itemId, '0.001');

        $this->assertSame(
            0,
            bccomp('1.476', $this->cartQuantityOf($saleLib, $itemId), 3),
            'The loss is per repetition, so a third weighing must not compound it.'
        );
    }

    public function testTheCartLineIsOnlyMergedOnceNotDuplicated(): void
    {
        $this->useWeightTenant();
        $itemId  = $this->createItem('TEST-CART-MERGE');
        $saleLib = new Sale_lib();

        $this->addToCart($saleLib, $itemId, '0.735');
        $this->addToCart($saleLib, $itemId, '0.740');

        $lines = array_filter($saleLib->get_cart(), fn ($line) => (int) $line['item_id'] === $itemId);

        $this->assertCount(1, $lines, 'A non-serialized item must stay on a single merged cart line.');
    }

    /**
     * Casaletto's world: whole units, quantity_decimals = 0. Scanning the
     * same product twice must still add up to exactly 2.
     */
    public function testScanningTheSameUnitItemTwiceStillTotalsTwo(): void
    {
        $this->useUnitTenant();
        $itemId  = $this->createItem('TEST-CART-UNIT');
        $saleLib = new Sale_lib();

        $this->addToCart($saleLib, $itemId, '1');
        $this->addToCart($saleLib, $itemId, '1');

        $this->assertSame(
            0,
            bccomp('2', $this->cartQuantityOf($saleLib, $itemId), 3),
            'The unit tenant must see the exact same total it sees today.'
        );
    }

    public function testUnitTenantLineTotalIsUnchangedAfterMerging(): void
    {
        $this->useUnitTenant();
        $itemId  = $this->createItem('TEST-CART-UNIT-TOTAL');
        $saleLib = new Sale_lib();

        $this->addToCart($saleLib, $itemId, '1');
        $this->addToCart($saleLib, $itemId, '1');

        $line = array_values(array_filter($saleLib->get_cart(), fn ($l) => (int) $l['item_id'] === $itemId))[0];

        // 2 units at 4,500 each. The quantity scale must not leak into money.
        $this->assertSame(0, bccomp('9000', (string) $line['total'], 2), 'The line total in money must be untouched by the quantity scale.');
    }
}
