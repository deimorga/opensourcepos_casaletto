<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\Inventory;
use App\Models\Item_quantity;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\OSPOS;

/**
 * Inventory::record_write_off() -- taking stock out and saying why.
 *
 * The point of the whole feature is in these tests twice over:
 *
 *  - the reason survives as a code, so a week of write-offs can be added up and compared, which a
 *    hand-typed comment never allowed;
 *  - half a kilo stays half a kilo. Casaletto runs with quantity_decimals = 0 and
 *    currency_decimals = 0, so the ambient bcmath scale in production is 2 and the *display*
 *    setting is 0. Any arithmetic that reads either of those instead of
 *    Item_quantity::quantity_scale() turns 0.5 kg of cheese into 0 or 1 kg with nothing on screen
 *    to say it happened.
 */
class InventoryWriteOffTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $migrateOnce = true;
    protected $refresh = false;
    protected $namespace = 'App';

    private const ITEM_NUMBER = 'WRITEOFF-TEST-001';

    private Inventory $inventory;
    private int $itemId;
    private int $locationId;

    protected function setUp(): void
    {
        parent::setUp();

        // The pooled 'tests' connection can be holding a table list from before the migrations ran,
        // which leaves Config\OSPOS on a handful of hardcoded defaults. Same note as
        // SummaryTaxesTest::setUp().
        Database::connect()->resetDataCache();
        config(OSPOS::class)->update_settings();

        $this->inventory = model(Inventory::class);

        $this->seedItem();
    }

    protected function tearDown(): void
    {
        $this->deleteItem();

        parent::tearDown();
    }

    /**
     * Reproduces the tenant that is already selling every day with this code: quantities shown with
     * no decimals, money with no decimals, tax with two -- which is what puts the ambient bcmath
     * scale on 2. A regression that drops the explicit scale fails here instead of passing by luck.
     */
    private function useCasalettoSettings(): void
    {
        $settings = config(OSPOS::class)->settings;
        $settings['quantity_decimals'] = '0';
        $settings['currency_decimals'] = '0';
        $settings['tax_decimals'] = '2';

        $config = new OSPOS();
        $config->settings = $settings;

        Factories::injectMock('config', OSPOS::class, $config);

        bcscale(2);
    }

    /**
     * $refresh = false here, and items.item_number is not unique, so the fixture deletes before it
     * inserts. Otherwise every test method in the class stacks another copy of the same item.
     */
    private function seedItem(): void
    {
        $db = Database::connect();

        $this->deleteItem();

        $this->locationId = (int) $db->table('stock_locations')
            ->select('location_id')
            ->where('deleted', 0)
            ->limit(1)
            ->get()
            ->getRow()
            ->location_id;

        $db->table('items')->insert([
            'name'                => 'Queso de prueba',
            'category'            => 'Test',
            'supplier_id'         => null,
            'item_number'         => self::ITEM_NUMBER,
            'description'         => 'Fixture for the write-off tests',
            'cost_price'          => 12000.00,
            'unit_price'          => 20000.00,
            'reorder_level'       => 0,
            'receiving_quantity'  => 1,
            'stock_type'          => 0,
            'item_type'           => 0,
            'tax_category_id'     => 1,
            'deleted'             => 0
        ]);

        $this->itemId = (int) $db->insertID();

        $db->table('item_quantities')->insert([
            'item_id'     => $this->itemId,
            'location_id' => $this->locationId,
            'quantity'    => 10
        ]);
    }

    private function deleteItem(): void
    {
        $db = Database::connect();

        $ids = array_column(
            $db->table('items')->select('item_id')->where('item_number', self::ITEM_NUMBER)->get()->getResultArray(),
            'item_id'
        );

        if ($ids === []) {
            return;
        }

        $db->table('inventory')->whereIn('trans_items', $ids)->delete();
        $db->table('item_quantities')->whereIn('item_id', $ids)->delete();
        $db->table('items')->whereIn('item_id', $ids)->delete();
    }

    private function currentQuantity(): string
    {
        return (string) model(Item_quantity::class)
            ->get_item_quantity($this->itemId, $this->locationId)
            ->quantity;
    }

    private function lastMovement(): ?array
    {
        return Database::connect()->table('inventory')
            ->where('trans_items', $this->itemId)
            ->orderBy('trans_id', 'desc')
            ->limit(1)
            ->get()
            ->getRowArray();
    }

    public function testTheReasonIsStoredAsACodeOnTheMovementItself(): void
    {
        $this->assertTrue(
            $this->inventory->record_write_off($this->itemId, $this->locationId, '2', Inventory::REASON_DAMAGED, 'Se cayó la bandeja', 1)
        );

        $movement = $this->lastMovement();

        $this->assertSame('damaged', $movement['reason_code']);
        $this->assertSame('Se cayó la bandeja', $movement['trans_comment']);
        $this->assertSame(1, (int) $movement['trans_user']);
        $this->assertSame($this->locationId, (int) $movement['trans_location']);
    }

    public function testAWriteOffLeavesStockAsANegativeMovement(): void
    {
        $this->inventory->record_write_off($this->itemId, $this->locationId, '2', Inventory::REASON_SHRINKAGE, '', 1);

        $this->assertSame(0, bccomp('-2', (string) $this->lastMovement()['trans_inventory'], 3));
        $this->assertSame(0, bccomp('8', $this->currentQuantity(), 3));
    }

    /**
     * The reason this feature exists at all in a shop that sells cheese by weight.
     */
    public function testHalfAKiloIsWrittenOffAsHalfAKiloAndNotAsAWholeUnit(): void
    {
        $this->useCasalettoSettings();

        $this->assertTrue(
            $this->inventory->record_write_off($this->itemId, $this->locationId, '0.5', Inventory::REASON_DAMAGED, '', 1)
        );

        $this->assertSame(0, bccomp('-0.5', (string) $this->lastMovement()['trans_inventory'], 3));
        $this->assertSame(0, bccomp('9.5', $this->currentQuantity(), 3));
    }

    /**
     * Three decimals is what the column physically holds, and a gram is what a scale reports.
     */
    public function testTheThirdDecimalOfAWeightSurvives(): void
    {
        $this->useCasalettoSettings();

        $this->inventory->record_write_off($this->itemId, $this->locationId, '0.735', Inventory::REASON_SHRINKAGE, '', 1);

        $this->assertSame(0, bccomp('-0.735', (string) $this->lastMovement()['trans_inventory'], 3));
        $this->assertSame(0, bccomp('9.265', $this->currentQuantity(), 3));
    }

    /**
     * Stock that is already negative -- 63 items in production are -- must still be writable off.
     * A write-off records what left; it is not a validation of what was there.
     */
    public function testStockAlreadyInTheNegativeCanStillBeWrittenOff(): void
    {
        Database::connect()->table('item_quantities')
            ->where('item_id', $this->itemId)
            ->where('location_id', $this->locationId)
            ->update(['quantity' => -3]);

        $this->assertTrue(
            $this->inventory->record_write_off($this->itemId, $this->locationId, '1', Inventory::REASON_THEFT, '', 1)
        );

        $this->assertSame(0, bccomp('-4', $this->currentQuantity(), 3));
    }

    public function testAReasonNobodyCanReportOnIsRefusedAndNothingIsWritten(): void
    {
        $this->assertFalse(
            $this->inventory->record_write_off($this->itemId, $this->locationId, '1', 'se pudrio', '', 1)
        );

        $this->assertNull($this->lastMovement());
        $this->assertSame(0, bccomp('10', $this->currentQuantity(), 3));
    }

    public function testZeroAndNegativeQuantitiesAreRefused(): void
    {
        foreach (['0', '0.000', '-1'] as $quantity) {
            $this->assertFalse(
                $this->inventory->record_write_off($this->itemId, $this->locationId, $quantity, Inventory::REASON_DAMAGED, '', 1),
                "A write-off of \"$quantity\" should have been refused."
            );
        }

        $this->assertNull($this->lastMovement());
        $this->assertSame(0, bccomp('10', $this->currentQuantity(), 3));
    }

    /**
     * Reached straight from a form, so a blank field or a typed word has to come back as a refusal
     * rather than a ValueError out of bcmath.
     */
    public function testTextInTheQuantityFieldIsRefusedRatherThanFatal(): void
    {
        foreach (['', 'medio kilo', '1,5', '1e3', ' 1 '] as $quantity) {
            $this->assertFalse(
                $this->inventory->record_write_off($this->itemId, $this->locationId, $quantity, Inventory::REASON_DAMAGED, '', 1),
                "A write-off of \"$quantity\" should have been refused."
            );
        }

        $this->assertSame(0, bccomp('10', $this->currentQuantity(), 3));
    }

    /**
     * Movements that are not write-offs -- a sale, a receiving, a plain adjustment, and every row
     * written before this column existed -- carry no reason, and that is the correct state for
     * them rather than a gap to be filled in.
     */
    public function testAMovementThatIsNotAWriteOffCarriesNoReason(): void
    {
        $this->inventory->insert([
            'trans_date'      => date('Y-m-d H:i:s'),
            'trans_items'     => $this->itemId,
            'trans_user'      => 1,
            'trans_location'  => $this->locationId,
            'trans_comment'   => 'Ajuste manual como los de siempre',
            'trans_inventory' => -1
        ], false);

        $this->assertNull($this->lastMovement()['reason_code']);
    }

    public function testTheKnownReasonsAreExactlyTheFourTheBusinessAskedFor(): void
    {
        $this->assertSame(
            ['damaged', 'shrinkage', 'theft', 'data_entry'],
            Inventory::WRITE_OFF_REASON_CODES
        );

        foreach (Inventory::WRITE_OFF_REASON_CODES as $code) {
            $this->assertTrue(Inventory::is_write_off_reason($code));
        }

        $this->assertFalse(Inventory::is_write_off_reason(null));
        $this->assertFalse(Inventory::is_write_off_reason(''));
        $this->assertFalse(Inventory::is_write_off_reason('Damaged'));
    }
}
