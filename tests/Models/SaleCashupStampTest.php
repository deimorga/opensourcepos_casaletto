<?php

namespace Tests\Models;

use App\Models\Cashup;
use App\Models\Sale;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Covers the seal Sale::save_value() puts on a sale: the cash-up shift that was open at the moment
 * the sale was completed, fixed from then on.
 *
 * Nothing here reads sale_time back to work out the shift, and that is the point. Shifts overlap and
 * sale_time can be edited afterwards, so the shift has to be recorded while it is still knowable.
 *
 * See docs/Funcional/cuadre-de-caja-y-origen-del-efectivo.md.
 */
class SaleCashupStampTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private string $itemNumber = 'TEST-CASHUP-ITEM';

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect();

        // See SalesControllerTest::setUp(): the pooled 'tests' connection can hold a table list
        // cached from before the migrations ran, which makes Config\OSPOS fall back to a handful of
        // hardcoded defaults.
        $db->resetDataCache();

        // Every test states for itself which shifts are open, so start from none.
        $db->table('cash_up')->where('cashup_id > 0')->delete();

        $this->createTestItem();
    }

    private function createTestItem(): void
    {
        $db = db_connect();

        $existing = $db->table('items')->select('item_id')->where('item_number', $this->itemNumber)->get()->getRow();

        if ($existing !== null) {
            return;
        }

        $db->table('items')->insert([
            'name'                  => 'Test Cashup Item',
            'category'              => 'Test',
            'item_number'           => $this->itemNumber,
            'description'           => 'Fixture item for SaleCashupStampTest',
            'cost_price'            => '1.00',
            'unit_price'            => '10.00',
            'reorder_level'         => '0',
            'receiving_quantity'    => '1',
            'allow_alt_description' => 0,
            'is_serialized'         => 0
        ]);

        // location_id 1 = the default 'stock' location seeded by sqlscripts/initial_schema.sql.
        $db->table('item_quantities')->insert([
            'item_id'     => (int) $db->insertID(),
            'location_id' => 1,
            'quantity'    => '1000'
        ]);
    }

    private function itemId(): int
    {
        return (int) db_connect()->table('items')->select('item_id')->where('item_number', $this->itemNumber)->get()->getRow()->item_id;
    }

    /**
     * A shift as Cashups::postSave() writes it when the drawer is opened: still open, and close_date
     * standing in as a placeholder equal to open_date.
     */
    private function openShift(string $openDate = '2026-08-14 08:00:00'): int
    {
        return $this->insertShift($openDate, $openDate, 'open', 0);
    }

    private function closedShift(string $openDate, string $closeDate): int
    {
        return $this->insertShift($openDate, $closeDate, 'closed', 0);
    }

    private function insertShift(string $openDate, string $closeDate, string $status, int $deleted): int
    {
        $db = db_connect();

        $db->table('cash_up')->insert([
            'status'               => $status,
            'open_date'            => $openDate,
            'close_date'           => $closeDate,
            'open_amount_cash'     => '0.00',
            'transfer_amount_cash' => '0.00',
            'note'                 => 0,
            'closed_amount_cash'   => '0.00',
            'closed_amount_card'   => '0.00',
            'closed_amount_check'  => '0.00',
            'closed_amount_due'    => '0.00',
            'closed_amount_total'  => '0.00',
            'description'          => 'SaleCashupStampTest fixture',
            'open_employee_id'     => 1,
            'close_employee_id'    => 1,
            'deleted'              => $deleted
        ]);

        return (int) $db->insertID();
    }

    /**
     * One line of cart, shaped the way Sale_lib::add_item() builds it.
     */
    private function cart(): array
    {
        return [
            0 => [
                'item_id'       => $this->itemId(),
                'item_location' => 1,
                'line'          => 0,
                'description'   => 'Test Cashup Item',
                'serialnumber'  => '',
                'quantity'      => 1,
                'discount'      => 0,
                'discount_type' => PERCENT,
                'price'         => 10.00,
                'cost_price'    => 1.00,
                'print_option'  => PRINT_YES
            ]
        ];
    }

    /**
     * @return int the sale id
     */
    private function saveSale(int $saleId, int $status): int
    {
        $saleStatus = (string) $status;
        $items      = $this->cart();
        $salesTaxes = [[], []];
        $payments   = [
            'Cash' => [
                'payment_type'    => 'Cash',
                'payment_amount'  => 10.00,
                'cash_refund'     => 0,
                'cash_adjustment' => CASH_ADJUSTMENT_FALSE,
                'employee_id'     => 1
            ]
        ];

        // dinner_table_id 1 is the seeded Delivery pseudo-table, which exists whether or not dinner
        // table mode is on, so the foreign key holds either way.
        return model(Sale::class)->save_value($saleId, $saleStatus, $items, NEW_ENTRY, 1, '', null, null, null, SALE_TYPE_POS, $payments, 1, $salesTaxes);
    }

    private function storedCashupId(int $saleId): ?int
    {
        $row = db_connect()->table('sales')->select('cashup_id')->where('sale_id', $saleId)->get()->getRow();

        return $row === null || $row->cashup_id === null ? null : (int) $row->cashup_id;
    }

    public function testCompletedSaleIsSealedWithTheShiftThatIsOpen(): void
    {
        $cashupId = $this->openShift();

        $saleId = $this->saveSale(NEW_ENTRY, COMPLETED);

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame($cashupId, $this->storedCashupId($saleId));
    }

    /**
     * No open shift is a real state, not a failure: the sale is recorded and shows up as a sale with
     * no shift. Guessing one would put cash in a drawer that never saw it.
     */
    public function testCompletedSaleWithNoOpenShiftIsRecordedWithoutOne(): void
    {
        $this->closedShift('2026-08-13 08:00:00', '2026-08-13 22:00:00');

        $saleId = $this->saveSale(NEW_ENTRY, COMPLETED);

        $this->assertGreaterThan(0, $saleId);
        $this->assertNull($this->storedCashupId($saleId));
    }

    /**
     * The rule in the user's words: if a sale is not closed in the shift it was rung up in, it
     * counts for whichever shift is alive when it is closed. Nothing is sealed while it is only
     * suspended, because no cash has moved yet.
     */
    public function testSuspendedSaleIsSealedWithTheShiftAliveWhenItIsFinallyCompleted(): void
    {
        $saleId = $this->saveSale(NEW_ENTRY, SUSPENDED);

        $this->assertGreaterThan(0, $saleId);
        $this->assertNull($this->storedCashupId($saleId), 'A suspended sale has not produced any cash yet, so it carries no shift.');

        $cashupId = $this->openShift('2026-08-15 08:00:00');

        $this->assertSame($saleId, $this->saveSale($saleId, COMPLETED));
        $this->assertSame($cashupId, $this->storedCashupId($saleId));
    }

    /**
     * Saving a completed sale again -- and a second shift being open by then -- must not move it.
     * The shift it happened in is a fact about the past.
     */
    public function testAlreadySealedSaleKeepsItsShiftWhenSavedAgain(): void
    {
        $firstShift = $this->openShift('2026-08-14 08:00:00');
        $saleId     = $this->saveSale(NEW_ENTRY, COMPLETED);

        $this->assertSame($firstShift, $this->storedCashupId($saleId));

        db_connect()->table('cash_up')->where('cashup_id', $firstShift)->update(['status' => 'closed', 'close_date' => '2026-08-14 22:00:00']);
        $secondShift = $this->openShift('2026-08-15 08:00:00');

        $this->saveSale($saleId, COMPLETED);

        $this->assertSame($firstShift, $this->storedCashupId($saleId), "Re-saving a sale must not move it to shift $secondShift.");
    }

    /**
     * Correcting a comment or a customer months later does not move the money.
     */
    public function testEditingASealedSaleDoesNotMoveItToAnotherShift(): void
    {
        $cashupId = $this->openShift();
        $saleId   = $this->saveSale(NEW_ENTRY, COMPLETED);

        $this->assertSame($cashupId, $this->storedCashupId($saleId));

        $otherShift = $this->closedShift('2026-01-01 08:00:00', '2026-01-01 22:00:00');

        model(Sale::class)->update($saleId, [
            'comment'   => 'Corrected after the fact',
            'sale_time' => '2026-01-01 12:00:00',
            'cashup_id' => $otherShift
        ]);

        $this->assertSame($cashupId, $this->storedCashupId($saleId), 'Neither the edited sale_time nor a cashup_id posted by the edit form may re-attribute the sale.');
    }

    public function testOpenCashupIdIsNullWhenNoShiftIsOpen(): void
    {
        $this->closedShift('2026-08-13 08:00:00', '2026-08-13 22:00:00');

        $this->assertNull(model(Cashup::class)->get_open_cashup_id());
    }

    public function testOpenCashupIdIgnoresDeletedShifts(): void
    {
        $this->insertShift('2026-08-14 08:00:00', '2026-08-14 08:00:00', 'open', 1);

        $this->assertNull(model(Cashup::class)->get_open_cashup_id());
    }

    /**
     * Until one-shift-at-a-time is enforced at the Cashups end, two can be open at once -- that is
     * how shifts 31 and 32 came to overlap. The drawer the cashier is standing at is the one opened
     * most recently.
     */
    public function testOpenCashupIdIsTheMostRecentlyOpenedWhenSeveralAreOpen(): void
    {
        $this->openShift('2026-08-13 08:00:00');
        $newest = $this->openShift('2026-08-14 13:08:00');

        $this->assertSame($newest, model(Cashup::class)->get_open_cashup_id());
    }
}
