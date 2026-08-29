<?php

namespace Tests\Libraries;

use App\Libraries\Sale_lib;
use App\Models\Appconfig;
use App\Models\Item;
use CodeIgniter\Session\Handlers\ArrayHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\Mock\MockSession;
use Config\OSPOS;
use Config\Services;

/**
 * The missing link between the item's unit of measure and the sale.
 *
 * Sale_lib::add_item() builds every cart line from a literal list of keys, so
 * a column the list does not name does not exist inside a sale no matter how
 * correct the items table is. Until this test passed, `unit_of_measure` was a
 * column the register could not see.
 *
 * Both tenants are exercised on purpose (see the technical doc, section 7c.5):
 * the question that matters is not only "does weight work?" but "does
 * everything that already worked still work?".
 */
class SaleLibCartUnitOfMeasureTest extends CIUnitTestCase
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

        // See SalesControllerTest::setUp() -- the pooled 'tests' connection can
        // cache a pre-migration table list, which makes Config\OSPOS::set_settings()
        // fall back to hardcoded defaults.
        db_connect()->resetDataCache();

        $sessionConfig = config('Session');
        $session       = new MockSession(new ArrayHandler($sessionConfig, '0.0.0.0'), $sessionConfig);
        // Session::start() logs, and LoggerAwareTrait leaves $logger null until
        // something sets it.
        $session->setLogger(service('logger'));
        $session->start();
        Services::injectMock('session', $session);
    }

    private function useTenantSettings(string $quantityDecimals): void
    {
        // batch_save(), not save(): Appconfig::save() only ever stores the first
        // key of the array it is handed (see Appconfig.php).
        model(Appconfig::class)->batch_save([
            'quantity_decimals' => $quantityDecimals,
            'currency_decimals' => '0',
            'tax_decimals'      => '2'
        ]);
        config(OSPOS::class)->update_settings();

        bcscale(max(2, totals_decimals() + tax_decimals()));
    }

    private function createItem(string $itemNumber, string $unitOfMeasure): int
    {
        $db = db_connect();

        $db->table('items')->insert([
            'name'                  => 'Fixture ' . $itemNumber,
            'category'              => 'Test',
            'item_number'           => $itemNumber,
            'description'           => 'Fixture item for SaleLibCartUnitOfMeasureTest',
            'cost_price'            => '1000.00',
            'unit_price'            => '4500.00',
            'unit_of_measure'       => $unitOfMeasure,
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

    private function addToCart(Sale_lib $saleLib, int $itemId, string $quantity): void
    {
        $itemRef     = (string) $itemId;
        $discountRef = '0.00';

        $this->assertTrue(
            $saleLib->add_item($itemRef, self::LOCATION, $quantity, $discountRef),
            'The fixture item must be addable; a false here means the setup is wrong.'
        );
    }

    private function cartLineOf(Sale_lib $saleLib, int $itemId): array
    {
        foreach ($saleLib->get_cart() as $line) {
            if ((int) $line['item_id'] === $itemId) {
                return $line;
            }
        }

        $this->fail('Item ' . $itemId . ' is not in the cart.');
    }

    public function testAKilogramItemReachesTheCartCarryingItsUnit(): void
    {
        $this->useTenantSettings('3');
        $itemId  = $this->createItem('TEST-UOM-TOMATO', Item::UNIT_OF_MEASURE_KG);
        $saleLib = new Sale_lib();

        $this->addToCart($saleLib, $itemId, '0.735');
        $line = $this->cartLineOf($saleLib, $itemId);

        $this->assertSame(Item::UNIT_OF_MEASURE_KG, Sale_lib::line_unit_of_measure($line));
        $this->assertTrue(Sale_lib::line_sells_by_weight($line));
    }

    /**
     * Casaletto's world. Every one of its items is 'unit', so the register must
     * behave exactly as it does today.
     */
    public function testAUnitItemReachesTheCartAsAUnitItem(): void
    {
        $this->useTenantSettings('0');
        $itemId  = $this->createItem('TEST-UOM-EMPANADA', Item::UNIT_OF_MEASURE_UNIT);
        $saleLib = new Sale_lib();

        $this->addToCart($saleLib, $itemId, '1');
        $line = $this->cartLineOf($saleLib, $itemId);

        $this->assertSame(Item::UNIT_OF_MEASURE_UNIT, Sale_lib::line_unit_of_measure($line));
        $this->assertFalse(Sale_lib::line_sells_by_weight($line));
    }

    /**
     * The column defaults to 'unit', but an item row written before the column
     * existed can still hand back a null. normalize_unit_of_measure() is what
     * keeps that out of the cart.
     */
    public function testAnItemWithNoUnitOnFileIsSoldByTheUnit(): void
    {
        $this->useTenantSettings('0');
        $itemId = $this->createItem('TEST-UOM-NULL', Item::UNIT_OF_MEASURE_UNIT);
        db_connect()->table('items')->where('item_id', $itemId)->update(['unit_of_measure' => null]);

        $saleLib = new Sale_lib();
        $this->addToCart($saleLib, $itemId, '1');

        $this->assertSame(Item::UNIT_OF_MEASURE_UNIT, Sale_lib::line_unit_of_measure($this->cartLineOf($saleLib, $itemId)));
    }

    /**
     * Merging a repeat weighing takes the other branch of add_item() -- the one
     * that updates the existing line by reference instead of building a new
     * one. The unit has to survive that branch too.
     */
    public function testWeighingTheSameProductTwiceKeepsTheUnitOnTheMergedLine(): void
    {
        $this->useTenantSettings('3');
        $itemId  = $this->createItem('TEST-UOM-MERGE', Item::UNIT_OF_MEASURE_KG);
        $saleLib = new Sale_lib();

        $this->addToCart($saleLib, $itemId, '0.735');
        $this->addToCart($saleLib, $itemId, '0.740');

        $line = $this->cartLineOf($saleLib, $itemId);

        $this->assertSame(Item::UNIT_OF_MEASURE_KG, Sale_lib::line_unit_of_measure($line));
        $this->assertSame(0, bccomp('1.475', (string) $line['quantity'], 3), 'The merge must still keep the third decimal.');
    }
}
