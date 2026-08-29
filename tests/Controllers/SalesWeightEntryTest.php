<?php

namespace Tests\Controllers;

use App\Libraries\Sale_lib;
use App\Models\Appconfig;
use App\Models\Item;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\OSPOS;

/**
 * The register's side of selling by weight, end to end through the controller:
 * scan a kilo-priced item and the sale waits for a weight instead of guessing
 * one; send the weight and the line lands with the kilos on it.
 *
 * Every case is paired with the same case for an item sold by the unit, because
 * the regression that matters here is not "does weight work?" but "does the
 * register still behave exactly as it does today for the shop that is selling
 * with it right now?" (technical doc, section 7c.5).
 *
 * Needs a database. The parsing and session rules that can be checked without
 * one live in tests/Libraries/SaleLibWeightEntryTest.php.
 */
class SalesWeightEntryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private string $kgItem   = 'TEST-WEIGHT-TOMATO';
    private string $unitItem = 'TEST-WEIGHT-EMPANADA';

    protected function setUp(): void
    {
        parent::setUp();

        // See SalesControllerTest::setUp(): the pooled 'tests' connection can
        // cache a pre-migration table list, which makes OSPOS::set_settings()
        // fall back to hardcoded defaults and crash Email_lib's constructor.
        db_connect()->resetDataCache();

        // The greengrocer's settings. batch_save(), not save(): Appconfig::save()
        // only ever persists the first key of the array it is handed.
        model(Appconfig::class)->batch_save([
            'quantity_decimals' => '3',
            'currency_decimals' => '0',
            'tax_decimals'      => '2'
        ]);
        config(OSPOS::class)->update_settings();

        $this->createTestItem($this->kgItem, 'Tomate', Item::UNIT_OF_MEASURE_KG, '4500.00');
        $this->createTestItem($this->unitItem, 'Empanada', Item::UNIT_OF_MEASURE_UNIT, '5000.00');

        $this->loginAsAdmin();
    }

    private function createTestItem(string $itemNumber, string $name, string $unitOfMeasure, string $unitPrice): void
    {
        $db = db_connect();

        $db->table('items')->insert([
            'name'                  => $name,
            'category'              => 'Test',
            'item_number'           => $itemNumber,
            'description'           => 'Fixture item for SalesWeightEntryTest',
            'cost_price'            => '1000.00',
            'unit_price'            => $unitPrice,
            'unit_of_measure'       => $unitOfMeasure,
            'reorder_level'         => '0',
            'receiving_quantity'    => '1',
            'allow_alt_description' => 0,
            'is_serialized'         => 0
        ]);
        $itemId = (int) $db->insertID();

        // location_id 1 = the default 'stock' location seeded by initial_schema.sql.
        $db->table('item_quantities')->insert([
            'item_id'     => $itemId,
            'location_id' => 1,
            'quantity'    => '1000'
        ]);
    }

    /**
     * See SalesControllerTest::loginAsAdmin() for why $_SESSION has to be
     * seeded directly and re-armed before every request.
     */
    private function loginAsAdmin(): void
    {
        $_SESSION = ['person_id' => 1, 'menu_group' => 'home'];
        $this->withSession($_SESSION);

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

    private function cart(): array
    {
        return (new Sale_lib())->get_cart();
    }

    private function pendingWeightItem(): array
    {
        return (new Sale_lib())->get_weight_entry();
    }

    private function onlyCartLine(): array
    {
        $cart = $this->cart();

        $this->assertCount(1, $cart, 'Expected exactly one line in the cart.');

        return array_values($cart)[0];
    }

    public function testScanningAKiloPricedItemAddsNothingAndWaitsForTheWeight(): void
    {
        $this->postReq('sales/add', ['item' => $this->kgItem]);

        $this->assertSame([], $this->cart(), 'Nothing may reach the cart before there is a real weight: a line left at a default 1 kg is a sale of 1 kg nobody notices.');
        $this->assertSame($this->kgItem, $this->pendingWeightItem()['item_id_or_number'] ?? null);
        $this->assertSame('Tomate', $this->pendingWeightItem()['name'] ?? null, 'The prompt has to name the product the cashier just scanned.');
    }

    /**
     * Casaletto's entire catalogue takes this path. Nothing about it changes.
     */
    public function testScanningAUnitItemAddsItStraightAwayAsItDoesToday(): void
    {
        $this->postReq('sales/add', ['item' => $this->unitItem]);

        $line = $this->onlyCartLine();

        $this->assertSame(0, bccomp('1', (string) $line['quantity'], 3), 'A unit item still enters with quantity 1.');
        $this->assertSame([], $this->pendingWeightItem(), 'A unit item never puts the register into weight-entry mode.');
    }

    public function testTheWeightBecomesTheQuantityOnTheLine(): void
    {
        $this->postReq('sales/add', ['item' => $this->kgItem]);
        $this->postReq('sales/addWeight', ['weight' => '0,735']);

        $line = $this->onlyCartLine();

        $this->assertSame(0, bccomp('0.735', (string) $line['quantity'], 3));
        $this->assertSame(Item::UNIT_OF_MEASURE_KG, Sale_lib::line_unit_of_measure($line));
        $this->assertSame([], $this->pendingWeightItem(), 'Once weighed, the item stops being pending.');
    }

    public function testTheLineTotalIsTheWeightTimesThePricePerKilo(): void
    {
        $this->postReq('sales/add', ['item' => $this->kgItem]);
        $this->postReq('sales/addWeight', ['weight' => '0,735']);

        // 0.735 kg x 4,500/kg = 3,307.50
        $this->assertSame(0, bccomp('3307.50', (string) $this->onlyCartLine()['total'], 2));
    }

    /**
     * The trap this whole path exists to avoid. number_locale is es_CO, where
     * NumberFormatter reads a dot as the *thousands* separator -- and a dot is
     * what a scale in keyboard mode types.
     */
    public function testAWeightTypedWithADotIsKilosNotThousandsOfKilos(): void
    {
        $this->postReq('sales/add', ['item' => $this->kgItem]);
        $this->postReq('sales/addWeight', ['weight' => '0.735']);

        $this->assertSame(0, bccomp('0.735', (string) $this->onlyCartLine()['quantity'], 3), '"0.735" is 735 grams. Read through parse_decimals() it would be 735 kilos.');
    }

    public function testWeighingTheSameProductTwiceKeepsTheThirdDecimal(): void
    {
        $this->postReq('sales/add', ['item' => $this->kgItem]);
        $this->postReq('sales/addWeight', ['weight' => '0,735']);
        $this->postReq('sales/add', ['item' => $this->kgItem]);
        $this->postReq('sales/addWeight', ['weight' => '0,740']);

        $this->assertSame(0, bccomp('1.475', (string) $this->onlyCartLine()['quantity'], 3), 'Two bags must total 1.475 kg; 1.47 means five grams went missing in the merge.');
    }

    /**
     * A scanner firing a barcode into the weight field, a slipped keystroke, an
     * empty submit: none of them may become a quantity.
     */
    public function testAWeightThatIsNotANumberIsRefusedAndTheItemStaysPending(): void
    {
        $this->postReq('sales/add', ['item' => $this->kgItem]);
        $this->postReq('sales/addWeight', ['weight' => '7702001002344X']);

        $this->assertSame([], $this->cart(), 'Nothing may be added from an unreadable weight.');
        $this->assertSame($this->kgItem, $this->pendingWeightItem()['item_id_or_number'] ?? null, 'The prompt stays up so the cashier can retype instead of hunting for the product again.');
    }

    /**
     * The weight field holds the focus for as long as the register waits for a
     * weight, which is exactly when a cashier is most likely to reach for the
     * scanner instead. A bare barcode is a well-formed number.
     */
    public function testABarcodeFiredIntoTheWeightFieldDoesNotBecomeAQuantity(): void
    {
        $this->postReq('sales/add', ['item' => $this->kgItem]);
        $this->postReq('sales/addWeight', ['weight' => '7702001002344']);

        $this->assertSame([], $this->cart(), 'At 4.500 a kilo that would be a line worth more than the shop.');
        $this->assertNotSame([], $this->pendingWeightItem());
    }

    public function testAZeroWeightIsRefused(): void
    {
        $this->postReq('sales/add', ['item' => $this->kgItem]);
        $this->postReq('sales/addWeight', ['weight' => '0']);

        $this->assertSame([], $this->cart());
        $this->assertNotSame([], $this->pendingWeightItem());
    }

    public function testCancellingTheWeightLeavesTheSaleUntouched(): void
    {
        $this->postReq('sales/add', ['item' => $this->unitItem]);
        $this->postReq('sales/add', ['item' => $this->kgItem]);
        $this->postReq('sales/cancelWeight', []);

        $this->assertSame([], $this->pendingWeightItem());
        $this->assertCount(1, $this->cart(), 'Cancelling a weight drops the pending item, not the sale.');
    }

    /**
     * The cashier changed their mind mid-weighing and scanned something else.
     * The stale prompt must not survive to be filled in by accident.
     */
    public function testScanningAnotherItemReplacesThePendingWeightPrompt(): void
    {
        $this->postReq('sales/add', ['item' => $this->kgItem]);
        $this->postReq('sales/add', ['item' => $this->unitItem]);

        $this->assertSame([], $this->pendingWeightItem());
        $this->assertCount(1, $this->cart());
    }

    /**
     * A weight arriving with nothing waiting for it -- a double submit, or a
     * page left open while the sale was finished elsewhere -- must not invent
     * a line.
     */
    public function testAWeightWithNothingPendingIsIgnored(): void
    {
        $this->postReq('sales/addWeight', ['weight' => '0,735']);

        $this->assertSame([], $this->cart());
    }

    public function testTheWeightFieldOnlyRendersWhileAnItemIsWaitingForIt(): void
    {
        $before = $this->getReq('sales');
        $this->assertStringNotContainsString('id="weight"', $before->getBody(), 'A register with nothing to weigh must look exactly like it does today.');

        $this->postReq('sales/add', ['item' => $this->kgItem]);
        $during = $this->getReq('sales');
        $this->assertStringContainsString('id="weight"', $during->getBody());

        $this->postReq('sales/addWeight', ['weight' => '0,735']);
        $after = $this->getReq('sales');
        $this->assertStringNotContainsString('id="weight"', $after->getBody(), 'Once weighed, the field goes away and the scan field gets the focus back.');
    }

    /**
     * The isolation that keeps this invisible to a shop with no kilo-priced
     * items: it is the data that decides, so there is no setting to get wrong.
     */
    public function testAShopWithNoKiloPricedItemsNeverSeesTheWeightField(): void
    {
        db_connect()->table('items')->where('item_number', $this->kgItem)->update(['unit_of_measure' => Item::UNIT_OF_MEASURE_UNIT]);

        $this->postReq('sales/add', ['item' => $this->kgItem]);

        $this->assertSame([], $this->pendingWeightItem());
        $this->assertStringNotContainsString('id="weight"', $this->getReq('sales')->getBody());
    }

    /**
     * Correcting a line is where a weight is most likely to be retyped, so the
     * edit path has to read a dot the same way the weight field does. Through
     * parse_decimals() on this tenant "0.800" is 800 kilos.
     */
    public function testRetypingAWeightOnTheLineIsReadAsKilos(): void
    {
        $this->postReq('sales/add', ['item' => $this->kgItem]);
        $this->postReq('sales/addWeight', ['weight' => '0,735']);
        $line = array_key_first($this->cart());

        $this->postReq('sales/editItem/' . $line, ['quantity' => '0.800', 'price' => '4500', 'discount' => '0']);

        $this->assertSame(0, bccomp('0.800', (string) $this->onlyCartLine()['quantity'], 3));
    }

    public function testEditingAWeighedLineKeepsTheThirdDecimal(): void
    {
        $this->postReq('sales/add', ['item' => $this->kgItem]);
        $this->postReq('sales/addWeight', ['weight' => '0,735']);
        $line = array_key_first($this->cart());

        $this->postReq('sales/editItem/' . $line, ['quantity' => '1,475', 'price' => '4500', 'discount' => '0']);

        $this->assertSame(0, bccomp('1.475', (string) $this->onlyCartLine()['quantity'], 3));
    }

    /**
     * Refunding weighed goods is a real operation, so the negative quantity the
     * register itself renders has to survive being edited.
     */
    public function testAWeighedReturnLineCanStillBeEdited(): void
    {
        $this->postReq('sales/changeMode', ['mode' => 'return']);
        $this->postReq('sales/add', ['item' => $this->kgItem]);
        $this->postReq('sales/addWeight', ['weight' => '0,735']);
        $line = array_key_first($this->cart());

        $this->postReq('sales/editItem/' . $line, ['quantity' => '-0,800', 'price' => '4500', 'discount' => '0']);

        $this->assertSame(0, bccomp('-0.800', (string) $this->onlyCartLine()['quantity'], 3));
    }

    /**
     * The other half of the same rule: a line sold by the unit must keep
     * reading its quantity exactly as it did before, because on this tenant
     * parse_decimals() has always taken "1.5" to mean fifteen and a shop that
     * is already trading must not find that changed underneath it.
     */
    public function testAUnitLineStillReadsItsQuantityTheWayItAlwaysHas(): void
    {
        $this->postReq('sales/add', ['item' => $this->unitItem]);
        $line = array_key_first($this->cart());

        $this->postReq('sales/editItem/' . $line, ['quantity' => '1.5', 'price' => '5000', 'discount' => '0']);

        $this->assertSame(
            0,
            bccomp((string) parse_decimals('1.5'), (string) $this->onlyCartLine()['quantity'], 3),
            'Whatever parse_decimals() makes of "1.5" on this tenant is what a unit line must still get.'
        );
    }

    public function testReturningWeighedGoodsPutsTheWeightBackAsANegativeQuantity(): void
    {
        $this->postReq('sales/changeMode', ['mode' => 'return']);
        $this->postReq('sales/add', ['item' => $this->kgItem]);
        $this->postReq('sales/addWeight', ['weight' => '0,735']);

        $this->assertSame(0, bccomp('-0.735', (string) $this->onlyCartLine()['quantity'], 3), 'A return of 735 g is -0.735 kg, the same rule the scan path already applies.');
    }
}
