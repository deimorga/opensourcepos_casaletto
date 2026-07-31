<?php

namespace Tests\Controllers;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\OSPOS;

/**
 * Covers Sale_lib::add_item_kit() expanding a "kit of kits" -- an item kit
 * that has another item kit's representative item as one of its own
 * components (e.g. Casaletto's "COMBO" party platters made of individual
 * sandwiches that are themselves item kits). Before this fix, add_item_kit()
 * added a single cart line for the inner kit's representative item and never
 * deducted any of its own raw ingredients (see nested-kits-future-enhancement
 * memory / docs/Funcional).
 */
class SalesKitControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;
    protected $namespace   = 'App';

    protected function setUp(): void
    {
        parent::setUp();

        // See SalesControllerTest::setUp() -- the pooled 'tests' connection
        // can cache a stale (pre-migration) table list otherwise, which makes
        // Config\OSPOS::set_settings() fall back to hardcoded defaults missing
        // keys like smtp_pass and crash Email_lib's constructor.
        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();

        $this->loginAsAdmin();
    }

    private function loginAsAdmin(): void
    {
        // getReq()/postReq() re-arm the session from the real $_SESSION
        // superglobal (see their docblocks) -- on the very first request of
        // the process that global is still empty, so it must be seeded here
        // directly or that first re-arm wipes out the values just set below.
        $_SESSION = ['person_id' => 1, 'menu_group' => 'home'];
        $this->withSession($_SESSION);

        // A real cashier always lands on Sales::getIndex() first, which seeds
        // sale_id/cart state in session via clear_all().
        $this->getReq('sales');
    }

    private function getReq(string $path): TestResponse
    {
        $this->withSession($_SESSION);

        return $this->get($path);
    }

    private function postReq(string $path, array $params): TestResponse
    {
        $this->withSession($_SESSION);

        return $this->post($path, $params);
    }

    /**
     * Creates a sellable item row. Returns its item_id.
     */
    private function createItem(string $itemNumber, string $name, int $itemType = ITEM, int $stockType = HAS_STOCK): int
    {
        $db = db_connect();

        $db->table('items')->insert([
            'name'                  => $name,
            'category'              => 'Test',
            'item_number'           => $itemNumber,
            'description'           => 'Fixture item for SalesKitControllerTest',
            'cost_price'            => '1.00',
            'unit_price'            => '10.00',
            'reorder_level'         => '0',
            'receiving_quantity'    => '1',
            'allow_alt_description' => 0,
            'is_serialized'         => 0,
            'item_type'             => $itemType,
            'stock_type'            => $stockType
        ]);
        $itemId = (int) $db->insertID();

        if ($stockType === HAS_STOCK) {
            $db->table('item_quantities')->insert([
                'item_id'     => $itemId,
                'location_id' => 1,
                'quantity'    => '1000'
            ]);
        }

        return $itemId;
    }

    /**
     * Creates an item kit: its representative item row plus the item_kits
     * row pointing at it. Returns [item_kit_id, representative item_id].
     *
     * @param array<array{item_id: int, quantity: string}> $components
     */
    private function createItemKit(string $itemNumber, string $name, array $components): array
    {
        $db = db_connect();

        $kitItemId = $this->createItem($itemNumber, $name, ITEM_KIT, HAS_NO_STOCK);

        $db->table('item_kits')->insert([
            'item_kit_number'   => $itemNumber,
            'name'              => $name,
            'description'       => $name,
            'item_id'           => $kitItemId,
            'kit_discount'      => '0.00',
            'kit_discount_type' => PERCENT,
            'price_option'      => PRICE_OPTION_ALL,
            'print_option'      => PRINT_ALL
        ]);
        $itemKitId = (int) $db->insertID();

        $sequence = 1;
        foreach ($components as $component) {
            $db->table('item_kit_items')->insert([
                'item_kit_id'  => $itemKitId,
                'item_id'      => $component['item_id'],
                'quantity'     => $component['quantity'],
                'kit_sequence' => $sequence++
            ]);
        }

        return [$itemKitId, $kitItemId];
    }

    public function testNestedKitDeductsInnerKitsIngredientsWithMultipliedQuantity(): void
    {
        $panId    = $this->createItem('TEST-PAN', 'Pan');
        $jamonId  = $this->createItem('TEST-JAMON', 'Jamon');

        // Inner kit: 1x Pan + 3x Jamon per sandwich.
        [, $sandwichItemId] = $this->createItemKit('TEST-SANDWICH', 'Test Sandwich', [
            ['item_id' => $panId, 'quantity' => '1'],
            ['item_id' => $jamonId, 'quantity' => '3'],
        ]);

        // Outer kit ("kit of kits"): 2x the sandwich kit.
        [$comboKitId, $comboItemId] = $this->createItemKit('TEST-COMBO', 'Test Combo', [
            ['item_id' => $sandwichItemId, 'quantity' => '2'],
        ]);

        $response = $this->postReq('sales/add', ['item' => 'KIT ' . $comboKitId]);
        $response->assertOK();

        $cart = $_SESSION['sales_cart'] ?? [];

        $findLine = fn (int $itemId) => array_values(array_filter($cart, fn ($line) => $line['item_id'] == $itemId))[0] ?? null;

        $panLine      = $findLine($panId);
        $jamonLine    = $findLine($jamonId);
        $sandwichLine = $findLine($sandwichItemId);
        $comboLine    = $findLine($comboItemId);

        $this->assertNotNull($panLine, 'The inner kit\'s raw ingredient (Pan) must be deducted, not just the sandwich line.');
        $this->assertNotNull($jamonLine, 'The inner kit\'s raw ingredient (Jamon) must be deducted, not just the sandwich line.');

        // 1 pan/sandwich * 2 sandwiches = 2. 3 jamon/sandwich * 2 sandwiches = 6.
        $this->assertEquals('2.0000', bcadd($panLine['quantity'], '0', 4));
        $this->assertEquals('6.0000', bcadd($jamonLine['quantity'], '0', 4));

        $this->assertNull($sandwichLine, 'The inner kit\'s own representative line should not appear -- it is flattened into its raw ingredients.');
        $this->assertNotNull($comboLine, 'The outer combo\'s own representative line should still be added once.');
    }

    public function testCircularKitReferenceDoesNotHangAndFailsGracefully(): void
    {
        $db = db_connect();

        // Kit A that contains itself as a component (the simplest cycle).
        [$kitAId, $kitAItemId] = $this->createItemKit('TEST-CYCLE-A', 'Test Cycle A', []);

        $db->table('item_kit_items')->insert([
            'item_kit_id'  => $kitAId,
            'item_id'      => $kitAItemId,
            'quantity'     => '1',
            'kit_sequence' => 1
        ]);

        $response = $this->postReq('sales/add', ['item' => 'KIT ' . $kitAId]);

        // Must return promptly (no infinite recursion) and report failure
        // instead of a fatal error / timeout.
        $response->assertOK();
    }
}
