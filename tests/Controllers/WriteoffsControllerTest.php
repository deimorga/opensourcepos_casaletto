<?php

declare(strict_types=1);

namespace Tests\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\OSPOS;

/**
 * The write-off screen end to end: the permission gate, and the save.
 *
 * The gate is not a formality here. The dominant constraint on this work is that the shop already
 * selling with this code must not notice a thing until somebody grants the permission, and an
 * employee who has not been granted it must not be able to reach the screen by typing the URL.
 */
class WriteoffsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $migrateOnce = true;
    protected $refresh = false;
    protected $namespace = 'App';

    private const ITEM_NUMBER = 'WRITEOFF-CTRL-001';

    private int $itemId;
    private int $locationId;

    protected function setUp(): void
    {
        parent::setUp();

        // The pooled 'tests' connection can be holding a pre-migration table list, which leaves
        // Config\OSPOS on a handful of hardcoded defaults and the views under test reading keys
        // that are not there. Same note as ConfigScaleTest/SummaryTaxesTest.
        Database::connect()->resetDataCache();
        config(OSPOS::class)->update_settings();

        $this->seedItem();
        $this->grantWriteoffs();
    }

    protected function tearDown(): void
    {
        $this->deleteItem();
        $this->revokeWriteoffs();

        parent::tearDown();
    }

    /**
     * See the long note in ConfigTest::resetSession(): FeatureTestTrait::call() overwrites $_SESSION
     * with its own property, and without this every request runs anonymous and Secure_Controller
     * calls a real exit() that kills the PHPUnit process with no output.
     */
    private function resetSession(): void
    {
        $session = Services::session();
        $session->destroy();
        $session->set('person_id', 1);
        $session->set('menu_group', 'office');

        $this->withSession(['person_id' => 1, 'menu_group' => 'office']);
    }

    private function grantWriteoffs(): void
    {
        $this->revokeWriteoffs();

        Database::connect()->table('grants')->insert([
            'permission_id' => 'writeoffs',
            'person_id'     => 1,
            'menu_group'    => 'office'
        ]);
    }

    private function revokeWriteoffs(): void
    {
        Database::connect()->table('grants')
            ->where('permission_id', 'writeoffs')
            ->where('person_id', 1)
            ->delete();
    }

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
            'name'               => 'Cebolla de prueba',
            'category'           => 'Test',
            'supplier_id'        => null,
            'item_number'        => self::ITEM_NUMBER,
            'description'        => 'Fixture for the write-off controller tests',
            'cost_price'         => 3000.00,
            'unit_price'         => 5000.00,
            'reorder_level'      => 0,
            'receiving_quantity' => 1,
            'stock_type'         => 0,
            'item_type'          => 0,
            'tax_category_id'    => 1,
            'deleted'            => 0
        ]);

        $this->itemId = (int) $db->insertID();

        $db->table('item_quantities')->insert([
            'item_id'     => $this->itemId,
            'location_id' => $this->locationId,
            'quantity'    => 20
        ]);
    }

    /**
     * $refresh = false and items.item_number is not unique, so the fixture deletes before inserting.
     */
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

    private function post_write_off(array $overrides = [])
    {
        $this->resetSession();

        return $this->post('/writeoffs/save', array_merge([
            'item_id'        => (string) $this->itemId,
            'item_name'      => 'Cebolla de prueba',
            'stock_location' => (string) $this->locationId,
            'quantity'       => '1',
            'reason_code'    => 'damaged',
            'comment'        => ''
        ], $overrides));
    }

    private function movementCount(): int
    {
        return Database::connect()->table('inventory')->where('trans_items', $this->itemId)->countAllResults();
    }

    public function testTheScreenOpensForSomebodyWhoHoldsThePermission(): void
    {
        $this->resetSession();

        $this->get('/writeoffs')->assertStatus(200);
    }

    /**
     * The constraint the whole piece of work is built around, as a request.
     */
    public function testWithoutTheGrantTheScreenIsNotReachableEvenByTypingTheUrl(): void
    {
        $this->revokeWriteoffs();
        $this->resetSession();

        $response = $this->get('/writeoffs');

        // Not assertFalse($response->isOK()): CI4 counts everything from 200 to 399 as OK, so a
        // redirect passes that check and the test would go green on a screen that was served.
        $response->assertRedirect();
        $this->assertSame(302, $response->response()->getStatusCode());
        $this->assertStringContainsString('no_access', (string) $response->getRedirectUrl());
    }

    /**
     * A smoke test, and not a pointless one: the date-range form pulls in partial/daterangepicker,
     * which reads a dozen settings keys straight out of Config\OSPOS. A missing key there is a
     * fatal on a page that no unit test would otherwise touch.
     */
    public function testTheReportAsksForADateRangeBeforeItRunsAnything(): void
    {
        $this->resetSession();

        $response = $this->get('/writeoffs/report');

        $response->assertStatus(200);
        $this->assertStringContainsString('daterangepicker', $response->getBody());
    }

    public function testThePickerAnswersWithItemsWithoutNeedingTheItemsPermission(): void
    {
        $this->resetSession();

        $response = $this->get('/writeoffs/suggest?term=Cebolla');

        $response->assertStatus(200);
        $this->assertStringContainsString('Cebolla de prueba', $response->getBody());
    }

    public function testAWriteOffIsRecordedWithItsReasonAndTakenOffTheStock(): void
    {
        $response = $this->post_write_off(['quantity' => '3', 'reason_code' => 'theft', 'comment' => 'Faltó en el conteo']);

        $response->assertStatus(200);

        $this->seeInDatabase('inventory', [
            'trans_items'  => $this->itemId,
            'reason_code'  => 'theft',
            'trans_comment' => 'Faltó en el conteo'
        ]);

        $quantity = Database::connect()->table('item_quantities')
            ->where('item_id', $this->itemId)
            ->where('location_id', $this->locationId)
            ->get()
            ->getRow()
            ->quantity;

        $this->assertSame(0, bccomp('17', (string) $quantity, 3));
    }

    /**
     * A dot is what a keypad types and what a scale in keyboard mode sends. Run through
     * parse_decimals() with number_locale = es_CO this would arrive as 735 kilos.
     */
    public function testHalfAKiloTypedWithADotIsStoredAsHalfAKilo(): void
    {
        $this->post_write_off(['quantity' => '0.5'])->assertStatus(200);

        $movement = Database::connect()->table('inventory')
            ->where('trans_items', $this->itemId)
            ->orderBy('trans_id', 'desc')
            ->limit(1)
            ->get()
            ->getRow();

        $this->assertSame(0, bccomp('-0.5', (string) $movement->trans_inventory, 3));
    }

    public function testACommaIsAcceptedAsADecimalPointToo(): void
    {
        $this->post_write_off(['quantity' => '0,735'])->assertStatus(200);

        $movement = Database::connect()->table('inventory')
            ->where('trans_items', $this->itemId)
            ->orderBy('trans_id', 'desc')
            ->limit(1)
            ->get()
            ->getRow();

        $this->assertSame(0, bccomp('-0.735', (string) $movement->trans_inventory, 3));
    }

    public function testAnUnknownReasonIsRefusedAndNothingIsRecorded(): void
    {
        $response = $this->post_write_off(['reason_code' => 'se_pudrio']);

        $response->assertStatus(200);
        $this->assertSame(0, $this->movementCount());
    }

    public function testAQuantityThatIsNotANumberIsRefusedAndNothingIsRecorded(): void
    {
        foreach (['', 'medio kilo', '0', '-2'] as $quantity) {
            $this->post_write_off(['quantity' => $quantity])->assertStatus(200);
        }

        $this->assertSame(0, $this->movementCount());
    }

    /**
     * The form posts the id the picker resolved, not the text in the box, so a name that matches
     * nothing has to be refused rather than guessed at.
     */
    public function testAnItemThatDoesNotExistIsRefusedAndNothingIsRecorded(): void
    {
        $this->post_write_off(['item_id' => '0', 'item_name' => 'Queso que no existe'])->assertStatus(200);
        $this->post_write_off(['item_id' => '999999999'])->assertStatus(200);

        $this->assertSame(0, $this->movementCount());
    }

    public function testTheReportRunsOverADateRangeAndAddsUpTheCost(): void
    {
        $this->post_write_off(['quantity' => '2', 'reason_code' => 'damaged'])->assertStatus(200);
        $this->post_write_off(['quantity' => '1', 'reason_code' => 'theft'])->assertStatus(200);

        // A refusal also answers 200 -- it re-renders the form with a message -- so the status says
        // nothing about whether anything was recorded.
        $this->assertSame(2, $this->movementCount());

        $this->resetSession();

        $today = date('Y-m-d 00:00:00');
        $tonight = date('Y-m-d 23:59:59');

        $response = $this->get('/writeoffs/report/' . rawurlencode($today) . '/' . rawurlencode($tonight) . '/all');

        $response->assertStatus(200);

        // assertSee() walks text nodes, and bootstrapTable receives its rows as JSON inside a
        // <script> block, so the row is in the page but not in any text node.
        $this->assertStringContainsString('Cebolla de prueba', $response->getBody());

        // 2 units damaged and 1 stolen at a cost of 3.000 each: the report has to reach 9.000
        // without anybody adding the rows up by hand.
        $report = model(\App\Models\Reports\Inventory_write_offs::class);

        $summary = $report->getSummaryData([
            'start_date'  => $today,
            'end_date'    => $tonight,
            'location_id' => 'all'
        ]);

        $this->assertSame(0, bccomp('9000', (string) $summary['total_cost'], 2));
        $this->assertSame(0, bccomp('6000', (string) $summary['by_reason']['damaged']['write_off_cost'], 2));
        $this->assertSame(0, bccomp('3000', (string) $summary['by_reason']['theft']['write_off_cost'], 2));
    }

    /**
     * Movements that carry no reason are not write-offs and must never appear in this report --
     * including every adjustment recorded before the classification existed.
     */
    public function testAPlainAdjustmentNeverShowsUpInTheWriteOffReport(): void
    {
        Database::connect()->table('inventory')->insert([
            'trans_date'      => date('Y-m-d H:i:s'),
            'trans_items'     => $this->itemId,
            'trans_user'      => 1,
            'trans_location'  => $this->locationId,
            'trans_comment'   => 'Ajuste manual de los de siempre',
            'trans_inventory' => -5
        ]);

        $report = model(\App\Models\Reports\Inventory_write_offs::class);

        $rows = $report->getData([
            'start_date'  => date('Y-m-d 00:00:00'),
            'end_date'    => date('Y-m-d 23:59:59'),
            'location_id' => 'all'
        ]);

        $this->assertNotContains(self::ITEM_NUMBER, array_column($rows, 'item_number'));

        // And the movement really is there, so the assertion above is about the report filtering it
        // out rather than about a fixture that never wrote anything.
        $this->seeInDatabase('inventory', ['trans_items' => $this->itemId, 'reason_code' => null]);
    }
}
