<?php

namespace Tests\Models;

use App\Models\Appconfig;
use App\Models\Item_quantity;
use App\Models\Receiving;
use App\Models\Sale;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\OSPOS;

/**
 * Covers the two silent decimal losses described in
 * docs/Tecnico/venta-por-peso-y-hardware-de-caja.md section 2.2.
 *
 * Item_quantity::change_quantity() used to take the amount as `int`, so the
 * two callers that hand it a weight -- Sale::delete() restocking a voided
 * sale, Receiving::delete_value() undoing a voided purchase -- had their
 * '0.735' coerced straight to 0 by PHP. The stock never moved, while the
 * audit row written into `inventory` two lines earlier did record 0.735.
 * Nothing warned: `item_quantities` and `inventory` simply started
 * disagreeing, and an inventory error is invisible because the sale still
 * balances in money.
 *
 * Every scenario runs twice, once per tenant, because the fix ships to a
 * store that is already selling by the unit every day (section 7c.5):
 *  - "unit" tenant  -- quantity_decimals = 0. Casaletto. Proves nothing moved.
 *  - "weight" tenant -- quantity_decimals = 3. The greengrocer.
 */
class QuantityPrecisionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private const LOCATION = 1;    // The 'stock' location seeded by initial_schema.sql
    private const EMPLOYEE = 1;

    protected function setUp(): void
    {
        parent::setUp();

        // See SalesControllerTest::setUp() -- the pooled 'tests' connection
        // can cache a pre-migration table list, which makes
        // Config\OSPOS::set_settings() fall back to hardcoded defaults.
        db_connect()->resetDataCache();
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

        // These tests drive the models directly, so the `pre_system` event
        // that normally sets the global scale never fires. Reproduce it
        // verbatim (Load_config.php: bcscale(max(2, totals_decimals() +
        // tax_decimals()))) -- for this tenant it lands on 2, which is
        // exactly the scale that used to eat the third decimal. Without this
        // line the suite would run at PHP's default scale of 0 and would not
        // reproduce production.
        bcscale(max(2, totals_decimals() + tax_decimals()));
    }

    /**
     * Creates a sellable, stocked item and returns its item_id.
     */
    private function createItem(string $itemNumber, string $startingStock): int
    {
        $db = db_connect();

        $db->table('items')->insert([
            'name'                  => 'Fixture ' . $itemNumber,
            'category'              => 'Test',
            'item_number'           => $itemNumber,
            'description'           => 'Fixture item for QuantityPrecisionTest',
            'cost_price'            => '1.00',
            'unit_price'            => '10.00',
            'reorder_level'         => '0',
            'receiving_quantity'    => '1',
            'allow_alt_description' => 0,
            'is_serialized'         => 0,
            'stock_type'            => HAS_STOCK
        ]);
        $itemId = (int) $db->insertID();

        $db->table('item_quantities')->insert([
            'item_id'     => $itemId,
            'location_id' => self::LOCATION,
            'quantity'    => $startingStock
        ]);

        return $itemId;
    }

    /**
     * Records a COMPLETED sale of $quantity straight into the tables, the way
     * a finished checkout leaves them, and returns its sale_id. Going through
     * the whole register flow would drag in the display formatting of section
     * 2.1, which is a different defect on a different track.
     */
    private function recordCompletedSale(int $itemId, string $quantity): int
    {
        $db = db_connect();

        $db->table('sales')->insert([
            'sale_time'   => date('Y-m-d H:i:s'),
            'customer_id' => null,
            'employee_id' => self::EMPLOYEE,
            'comment'     => 'Fixture sale for QuantityPrecisionTest',
            'sale_status' => COMPLETED
        ]);
        $saleId = (int) $db->insertID();

        $db->table('sales_items')->insert([
            'sale_id'            => $saleId,
            'item_id'            => $itemId,
            'line'               => 1,
            'description'        => '',
            'serialnumber'       => '',
            'quantity_purchased' => $quantity,
            'item_cost_price'    => '1.00',
            'item_unit_price'    => '10.00',
            'discount_percent'   => '0.00',
            'item_location'      => self::LOCATION
        ]);

        return $saleId;
    }

    /**
     * Records a received purchase of $quantity (with a per-line
     * receiving_quantity multiplier) and returns its receiving_id.
     */
    private function recordReceiving(int $itemId, string $quantity, string $receivingQuantity = '1'): int
    {
        $db = db_connect();

        $db->table('receivings')->insert([
            'receiving_time' => date('Y-m-d H:i:s'),
            'supplier_id'    => null,
            'employee_id'    => self::EMPLOYEE,
            'comment'        => 'Fixture receiving for QuantityPrecisionTest'
        ]);
        $receivingId = (int) $db->insertID();

        $db->table('receivings_items')->insert([
            'receiving_id'       => $receivingId,
            'item_id'            => $itemId,
            'line'               => 1,
            'description'        => '',
            'serialnumber'       => '',
            'quantity_purchased' => $quantity,
            'item_cost_price'    => '1.00',
            'item_unit_price'    => '10.00',
            'discount_percent'   => '0.00',
            'item_location'      => self::LOCATION,
            'receiving_quantity' => $receivingQuantity
        ]);

        return $receivingId;
    }

    private function stockOf(int $itemId): string
    {
        return (string) model(Item_quantity::class)->get_item_quantity($itemId, self::LOCATION)->quantity;
    }

    /**
     * Everything the `inventory` audit trail says happened to this item.
     */
    private function auditedMovementOf(int $itemId): string
    {
        $rows = db_connect()->table('inventory')
            ->select('trans_inventory')
            ->where('trans_items', $itemId)
            ->get()
            ->getResultArray();

        $total = '0';
        foreach ($rows as $row) {
            $total = bcadd($total, (string) $row['trans_inventory'], 3);
        }

        return $total;
    }

    private function assertQuantityEquals(string $expected, string $actual, string $message): void
    {
        $this->assertSame(0, bccomp($expected, $actual, 3), $message . ' (expected ' . $expected . ', got ' . $actual . ')');
    }

    // ---------------------------------------------------------------------
    // change_quantity() itself
    // ---------------------------------------------------------------------

    public function testChangeQuantityAddsAFractionalWeightToStock(): void
    {
        $this->useWeightTenant();
        $itemId = $this->createItem('TEST-QTY-FRACTION', '10.000');

        model(Item_quantity::class)->change_quantity($itemId, self::LOCATION, '0.735');

        $this->assertQuantityEquals('10.735', $this->stockOf($itemId), 'A 735 g change must land in item_quantities as 735 g, not as 0 and not as 730 g.');
    }

    public function testChangeQuantitySubtractsAFractionalWeightFromStock(): void
    {
        $this->useWeightTenant();
        $itemId = $this->createItem('TEST-QTY-FRACTION-NEG', '10.000');

        model(Item_quantity::class)->change_quantity($itemId, self::LOCATION, '-0.735');

        $this->assertQuantityEquals('9.265', $this->stockOf($itemId), 'Subtracting 735 g must move the stock by exactly 735 g.');
    }

    public function testChangeQuantityWithWholeNumbersIsUnchangedForTheUnitTenant(): void
    {
        $this->useUnitTenant();
        $itemId = $this->createItem('TEST-QTY-WHOLE', '10');

        model(Item_quantity::class)->change_quantity($itemId, self::LOCATION, '2');

        $this->assertQuantityEquals('12', $this->stockOf($itemId), 'Whole-number stock movements must behave exactly as they did before the fix.');
    }

    /**
     * quantity_decimals = 0 is a display setting, but item_quantities.quantity
     * is decimal(15,3): a unit tenant can still have half a unit on file.
     * Rounding it away while restocking would be a new defect shipped under
     * cover of a fix.
     */
    public function testUnitTenantKeepsFractionalStockItAlreadyHad(): void
    {
        $this->useUnitTenant();
        $itemId = $this->createItem('TEST-QTY-HALF', '5.500');

        model(Item_quantity::class)->change_quantity($itemId, self::LOCATION, '2');

        $this->assertQuantityEquals('7.500', $this->stockOf($itemId), 'A tenant with quantity_decimals = 0 must not have its existing fractional stock rounded off.');
    }

    // ---------------------------------------------------------------------
    // Voiding a sale (Sale::delete)
    // ---------------------------------------------------------------------

    public function testVoidingAWeighedSaleRestocksToTheGram(): void
    {
        $this->useWeightTenant();
        $itemId = $this->createItem('TEST-VOID-SALE-KG', '10.000');
        $saleId = $this->recordCompletedSale($itemId, '0.735');

        model(Sale::class)->delete($saleId, false, true, self::EMPLOYEE);

        $this->assertQuantityEquals('10.735', $this->stockOf($itemId), 'Voiding a 735 g sale must put 735 g back on the shelf.');
    }

    public function testVoidingAWeighedSaleLeavesStockAndAuditTrailAgreeing(): void
    {
        $this->useWeightTenant();
        $itemId = $this->createItem('TEST-VOID-SALE-AUDIT', '10.000');
        $saleId = $this->recordCompletedSale($itemId, '0.735');

        model(Sale::class)->delete($saleId, false, true, self::EMPLOYEE);

        $stockDelta = bcsub($this->stockOf($itemId), '10.000', 3);

        $this->assertQuantityEquals(
            $this->auditedMovementOf($itemId),
            $stockDelta,
            'item_quantities and the inventory audit trail must tell the same story -- this is the disagreement that makes the defect invisible.'
        );
    }

    public function testVoidingAUnitSaleRestocksExactlyAsBefore(): void
    {
        $this->useUnitTenant();
        $itemId = $this->createItem('TEST-VOID-SALE-UNIT', '10');
        $saleId = $this->recordCompletedSale($itemId, '2');

        model(Sale::class)->delete($saleId, false, true, self::EMPLOYEE);

        $this->assertQuantityEquals('12', $this->stockOf($itemId), 'Voiding a 2-unit sale must restock 2 units, exactly as it does today.');
        $this->assertQuantityEquals('2', $this->auditedMovementOf($itemId), 'The audit trail must match the stock movement for the unit tenant too.');
    }

    // ---------------------------------------------------------------------
    // Voiding a receiving (Receiving::delete_value)
    // ---------------------------------------------------------------------

    public function testVoidingAWeighedReceivingRemovesTheExactWeight(): void
    {
        $this->useWeightTenant();
        $itemId      = $this->createItem('TEST-VOID-RECV-KG', '10.735');
        $receivingId = $this->recordReceiving($itemId, '0.735');

        model(Receiving::class)->delete_value($receivingId, self::EMPLOYEE);

        $this->assertQuantityEquals('10.000', $this->stockOf($itemId), 'Undoing a 735 g purchase must take exactly 735 g back off the shelf.');
    }

    public function testVoidingAWeighedReceivingLeavesStockAndAuditTrailAgreeing(): void
    {
        $this->useWeightTenant();
        $itemId      = $this->createItem('TEST-VOID-RECV-AUDIT', '10.735');
        $receivingId = $this->recordReceiving($itemId, '0.735');

        model(Receiving::class)->delete_value($receivingId, self::EMPLOYEE);

        $stockDelta = bcsub($this->stockOf($itemId), '10.735', 3);

        $this->assertQuantityEquals(
            $this->auditedMovementOf($itemId),
            $stockDelta,
            'The inventory row and the stock change must be the same number, to the gram.'
        );
    }

    /**
     * receiving_quantity is a per-line multiplier (a case of 12, a crate of
     * 20). The two decimals multiply, so the caller hands change_quantity()
     * a product, not a stored column value.
     */
    public function testVoidingAWeighedReceivingHonoursTheLineMultiplier(): void
    {
        $this->useWeightTenant();
        $itemId      = $this->createItem('TEST-VOID-RECV-MULT', '15.000');
        $receivingId = $this->recordReceiving($itemId, '0.735', '2');

        model(Receiving::class)->delete_value($receivingId, self::EMPLOYEE);

        // 0.735 kg x 2 = 1.470 kg removed.
        $this->assertQuantityEquals('13.530', $this->stockOf($itemId), 'The receiving_quantity multiplier must be applied at full precision.');
    }

    /**
     * Both operands are decimal(15,3), so their product can be as small as
     * 1e-6. PHP renders that float as the string "1.0E-6", which bcadd()
     * rejects outright with a ValueError -- voiding the purchase would die
     * mid-transaction instead of doing nothing. Guard the edge explicitly.
     */
    public function testVoidingAReceivingOfAnUnrepresentablyTinyAmountDoesNotThrow(): void
    {
        $this->useWeightTenant();
        $itemId      = $this->createItem('TEST-VOID-RECV-TINY', '10.000');
        $receivingId = $this->recordReceiving($itemId, '0.001', '0.001');

        model(Receiving::class)->delete_value($receivingId, self::EMPLOYEE);

        // 0.001 x 0.001 = 0.000001, below what the column can hold: the stock
        // does not move, but nothing blows up either.
        $this->assertQuantityEquals('10.000', $this->stockOf($itemId), 'An amount smaller than a milligram must round to no movement, not to a fatal error.');
    }

    public function testVoidingAUnitReceivingRemovesExactlyAsBefore(): void
    {
        $this->useUnitTenant();
        $itemId      = $this->createItem('TEST-VOID-RECV-UNIT', '12');
        $receivingId = $this->recordReceiving($itemId, '2');

        model(Receiving::class)->delete_value($receivingId, self::EMPLOYEE);

        $this->assertQuantityEquals('10', $this->stockOf($itemId), 'Undoing a 2-unit purchase must remove 2 units, exactly as it does today.');
        $this->assertQuantityEquals('-2', $this->auditedMovementOf($itemId), 'The audit trail must match the stock movement for the unit tenant too.');
    }
}
