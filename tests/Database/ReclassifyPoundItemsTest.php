<?php

namespace Tests\Database;

use App\Database\Migrations\Migration_ReclassifyPoundItemsUnitOfMeasure;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Covers 20260905000000_ReclassifyPoundItemsUnitOfMeasure.
 *
 * Two corrections to what the Siigo backfill left behind: the pounds it deliberately would not
 * guess at, and the one item it guessed wrong -- QUESO DE CABEZA, described by the import as
 * kilograms and sold by the business by the pound. That one reached production.
 *
 * What is asserted just as hard is the other half: everything a person may have set by hand stays
 * exactly where they put it, and a second run changes nothing.
 */
class ReclassifyPoundItemsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const PREFIX = 'RECLASS-LB-';

    protected function setUp(): void
    {
        parent::setUp();

        // Composer excludes app/Database/Migrations from the classmap -- the file name carries the
        // timestamp and the class does not -- so the migration is required by hand, the same way
        // BackfillUnitOfMeasureTest does it.
        require_once APPPATH . 'Database/Migrations/20260905000000_ReclassifyPoundItemsUnitOfMeasure.php';

        db_connect()->resetDataCache();
        $this->deleteFixtures();
    }

    protected function tearDown(): void
    {
        $this->deleteFixtures();

        parent::tearDown();
    }

    /**
     * Fixtures are deleted before AND after every test. items.item_number is not unique and the
     * table is not truncated between methods, so leftovers accumulate and the lookups below start
     * resolving to the oldest copy -- which passes in isolation and fails in a full run.
     */
    private function deleteFixtures(): void
    {
        db_connect()->table('items')->like('item_number', self::PREFIX, 'after')->delete();
    }

    private function seedItem(string $suffix, string $name, string $description, string $unit): string
    {
        $itemNumber = self::PREFIX . $suffix;

        db_connect()->table('items')->insert([
            'name'                  => $name,
            'category'              => 'Test',
            'item_number'           => $itemNumber,
            'description'           => $description,
            'cost_price'            => '0.00',
            'unit_price'            => '9500.00',
            'unit_of_measure'       => $unit,
            'reorder_level'         => '0',
            'receiving_quantity'    => '1',
            'allow_alt_description' => 0,
            'is_serialized'         => 0
        ]);

        return $itemNumber;
    }

    private function unitOf(string $itemNumber): string
    {
        return (string) db_connect()->table('items')
            ->select('unit_of_measure')
            ->where('item_number', $itemNumber)
            ->get()
            ->getRow()
            ->unit_of_measure;
    }

    private function priceOf(string $itemNumber): string
    {
        return (string) db_connect()->table('items')
            ->select('unit_price')
            ->where('item_number', $itemNumber)
            ->get()
            ->getRow()
            ->unit_price;
    }

    /**
     * CodeIgniter's Migration takes its connection from a Forge handed to the constructor; there is
     * no setter. Passing the tests-group forge is what keeps this off the development database.
     */
    private function migration(): Migration_ReclassifyPoundItemsUnitOfMeasure
    {
        return new Migration_ReclassifyPoundItemsUnitOfMeasure(Database::forge('tests'));
    }

    // ========== The pounds the backfill would not guess at ==========

    public function testAnItemDescribedAsAPoundBecomesLb(): void
    {
        $pound = $this->seedItem('LIBRA', 'COSTILLA DE CERDO', 'Unidad: libra', 'unit');

        $this->migration()->up();

        $this->assertSame('lb', $this->unitOf($pound));
    }

    public function testSurroundingWhitespaceDoesNotHideAPound(): void
    {
        $padded = $this->seedItem('PAD', 'COSTILLA DE CERDO', '  Unidad: libra  ', 'unit');

        $this->migration()->up();

        $this->assertSame('lb', $this->unitOf($padded), 'The match is on the trimmed description.');
    }

    // ========== The one it got wrong, and which reached production ==========

    public function testQuesoDeCabezaMovesFromKgToLb(): void
    {
        // Exactly the row 20260903000000 produced: described as a kilogram, therefore set to 'kg'.
        $queso = $this->seedItem('QUESO', 'QUESO DE CABEZA', 'Unidad: kilogramo', 'kg');

        $this->migration()->up();

        $this->assertSame('lb', $this->unitOf($queso), 'The business sells it by the pound.');
    }

    public function testQuesoDeCabezaIsFoundByNameNotById(): void
    {
        // The ids differ between local, staging and production, so the rule cannot lean on one.
        // A name with the product inside it still has to be caught.
        $suffixed = $this->seedItem('QUESO2', 'QUESO DE CABEZA X LIBRA', 'Unidad: kilogramo neto', 'kg');

        $this->migration()->up();

        $this->assertSame('lb', $this->unitOf($suffixed));
    }

    public function testTheUnitChangesButThePriceDoesNot(): void
    {
        // No conversion, here or anywhere: the price is the price of one pound and stays that way.
        // Multiplying by 0.4536 would be the pricing bug this whole change exists to avoid.
        $queso = $this->seedItem('NOCONV', 'QUESO DE CABEZA', 'Unidad: kilogramo', 'kg');

        $this->migration()->up();

        $this->assertSame('lb', $this->unitOf($queso));
        $this->assertSame('9500.00', $this->priceOf($queso));
    }

    // ========== What it must not touch ==========

    public function testItemsThatSayKilogramAndAreNotQuesoStayKg(): void
    {
        $kilo = $this->seedItem('KG', 'TOMATE CHONTO', 'Unidad: kilogramo', 'kg');

        $this->migration()->up();

        $this->assertSame('kg', $this->unitOf($kilo), 'A kilogram is still a kilogram.');
    }

    public function testVolumeAndFillerDescriptionsAreStillNotGuessedAt(): void
    {
        $litre  = $this->seedItem('L', 'ACEITE', 'Unidad: litro', 'unit');
        $bottle = $this->seedItem('BOT', 'GASEOSA', 'Unidad: botella', 'unit');
        $siigo  = $this->seedItem('INTL', 'VARIOS', 'Unidad: número de unidades internacionales', 'unit');
        $plain  = $this->seedItem('UNI', 'PAN', 'Unidad: unidad', 'unit');

        $this->migration()->up();

        foreach ([$litre, $bottle, $siigo, $plain] as $itemNumber) {
            $this->assertSame('unit', $this->unitOf($itemNumber), $itemNumber . ' must not be guessed at.');
        }
    }

    public function testAPoundDescriptionSomebodyAlreadyOverrodeIsLeftAlone(): void
    {
        // Somebody looked at this one and decided it is sold by the unit despite the description.
        // A migration does not get to overrule that.
        $overridden = $this->seedItem('MANUAL', 'CHICHARRON', 'Unidad: libra', 'kg');

        $this->migration()->up();

        $this->assertSame('kg', $this->unitOf($overridden));
    }

    public function testAQuesoSomebodyPutBackOnUnitIsLeftAlone(): void
    {
        $overridden = $this->seedItem('QUESOMAN', 'QUESO DE CABEZA', 'Unidad: kilogramo', 'unit');

        $this->migration()->up();

        $this->assertSame('unit', $this->unitOf($overridden), 'A hand edit outranks a rule in a file.');
    }

    public function testAQuesoWhoseDescriptionIsNotTheImportsIsLeftAlone(): void
    {
        // The description predicate is what makes this a correction of a known migration rather
        // than a guess about an item somebody set up themselves.
        $handMade = $this->seedItem('QUESODESC', 'QUESO DE CABEZA', 'Vendido por kilo, en serio', 'kg');

        $this->migration()->up();

        $this->assertSame('kg', $this->unitOf($handMade));
    }

    // ========== Running it more than once ==========

    public function testRunningItTwiceChangesNothingTheSecondTime(): void
    {
        $pound = $this->seedItem('TWICE', 'COSTILLA DE CERDO', 'Unidad: libra', 'unit');
        $queso = $this->seedItem('TWICEQ', 'QUESO DE CABEZA', 'Unidad: kilogramo', 'kg');

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame('lb', $this->unitOf($pound));
        $this->assertSame('lb', $this->unitOf($queso));
    }

    public function testATenantWithNoneOfTheseDescriptionsIsANoOp(): void
    {
        $ordinary = $this->seedItem('CLEAN', 'CAFE MOLIDO', '', 'unit');

        $this->migration()->up();

        $this->assertSame('unit', $this->unitOf($ordinary));
    }

    // ========== Rolling back ==========

    public function testDownReturnsOnlyWhatUpConverted(): void
    {
        $pound = $this->seedItem('DOWN', 'COSTILLA DE CERDO', 'Unidad: libra', 'unit');
        $queso = $this->seedItem('DOWNQ', 'QUESO DE CABEZA', 'Unidad: kilogramo', 'kg');

        $this->migration()->up();
        $this->assertSame('lb', $this->unitOf($pound));
        $this->assertSame('lb', $this->unitOf($queso));

        $this->migration()->down();

        $this->assertSame('unit', $this->unitOf($pound), 'Back to where the backfill left it.');
        $this->assertSame('kg', $this->unitOf($queso), 'Back to what 20260903000000 produced.');
    }

    public function testDownLeavesAPoundSomebodySetByHandAlone(): void
    {
        $handMade = $this->seedItem('DOWNMAN', 'CARNE MOLIDA', 'Vendida por libra', 'lb');

        $this->migration()->up();
        $this->migration()->down();

        $this->assertSame('lb', $this->unitOf($handMade), 'Not this migration\'s to revert.');
    }
}
