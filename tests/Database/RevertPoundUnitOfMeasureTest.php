<?php

namespace Tests\Database;

use App\Database\Migrations\Migration_RevertPoundUnitOfMeasure;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Covers 20260907000000_RevertPoundUnitOfMeasure.
 *
 * The pound was a misreading -- the catalogue is priced per kilogram and always was -- so 'lb' left
 * App\Models\Item::ALLOWED_UNITS_OF_MEASURE. Staging had already run the migration that wrote it.
 * This one takes those rows back to a code the application still understands, and where each row
 * lands is the whole question: a weighed product has to stay weighed, and a packaged one has to
 * stop asking for a weight it never had.
 */
class RevertPoundUnitOfMeasureTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const PREFIX = 'REVERT-LB-';

    protected function setUp(): void
    {
        parent::setUp();

        // Composer excludes app/Database/Migrations from the classmap -- the file name carries the
        // timestamp and the class does not -- so the migration is required by hand, the same way
        // BackfillUnitOfMeasureTest does it.
        require_once APPPATH . 'Database/Migrations/20260907000000_RevertPoundUnitOfMeasure.php';

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

    private function seedItem(string $suffix, string $description, string $unit): string
    {
        $itemNumber = self::PREFIX . $suffix;

        db_connect()->table('items')->insert([
            'name'                  => 'Fixture ' . $suffix,
            'category'              => 'Test',
            'item_number'           => $itemNumber,
            'description'           => $description,
            'cost_price'            => '0.00',
            'unit_price'            => '26000.00',
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
    private function migration(): Migration_RevertPoundUnitOfMeasure
    {
        return new Migration_RevertPoundUnitOfMeasure(Database::forge('tests'));
    }

    private function runMigration(): void
    {
        $this->migration()->up();
    }

    /**
     * QUESO DE CABEZA. The import called it a kilogram, the price is per kilogram, and it is sold
     * by weight -- so it goes back to being weighed, not to being a unit.
     */
    public function testAPoundRowDescribedAsAKilogramGoesBackToKilograms(): void
    {
        $plain = $this->seedItem('KG', 'Unidad: kilogramo', 'lb');
        $net   = $this->seedItem('KGNET', 'Unidad: kilogramo neto', 'lb');

        $this->runMigration();

        $this->assertSame('kg', $this->unitOf($plain));
        $this->assertSame('kg', $this->unitOf($net));
    }

    /**
     * CAFÉ MAKOR LIBRA. "Unidad: libra" names the size of the bag; nobody weighs it. Sending it to
     * 'kg' would make the register demand a weight for a packaged good -- the more disruptive of
     * the two possible mistakes, and the one a cashier cannot work around at the till.
     */
    public function testEveryOtherPoundRowBecomesAPlainUnit(): void
    {
        $pound  = $this->seedItem('LIBRA', 'Unidad: libra', 'lb');
        $empty  = $this->seedItem('EMPTY', '', 'lb');
        $other  = $this->seedItem('OTHER', 'Vendido por libra', 'lb');

        $this->runMigration();

        foreach ([$pound, $empty, $other] as $itemNumber) {
            $this->assertSame('unit', $this->unitOf($itemNumber), $itemNumber . ' is not something anybody weighs.');
        }
    }

    public function testSurroundingWhitespaceDoesNotHideAKilogram(): void
    {
        $padded = $this->seedItem('PAD', '  Unidad: kilogramo  ', 'lb');

        $this->runMigration();

        $this->assertSame('kg', $this->unitOf($padded), 'The match is on the trimmed description.');
    }

    /**
     * The migration moves a code, never a price. Nothing converted on the way into 'lb' and nothing
     * converts on the way out -- $26.000 was the price of a kilogram the whole time, which is the
     * finding that sent the pound away in the first place.
     */
    public function testNoPriceIsTouched(): void
    {
        $item = $this->seedItem('PRICE', 'Unidad: kilogramo', 'lb');

        $this->runMigration();

        $this->assertSame(0, bccomp('26000.00', $this->priceOf($item), 2));
    }

    /**
     * Rows that were never on 'lb' are none of this migration's business, including the ones on the
     * code it hands out.
     */
    public function testRowsThatWereNeverPoundsAreLeftAlone(): void
    {
        $kg   = $this->seedItem('WASKG', 'Unidad: kilogramo', 'kg');
        $unit = $this->seedItem('WASUNIT', 'Unidad: libra', 'unit');

        $this->runMigration();

        $this->assertSame('kg', $this->unitOf($kg));
        $this->assertSame('unit', $this->unitOf($unit));
    }

    public function testRunningItTwiceChangesNothingTheSecondTime(): void
    {
        $kg    = $this->seedItem('TWICEKG', 'Unidad: kilogramo', 'lb');
        $other = $this->seedItem('TWICEUN', 'Unidad: libra', 'lb');

        $this->runMigration();
        $this->runMigration();

        $this->assertSame('kg', $this->unitOf($kg));
        $this->assertSame('unit', $this->unitOf($other));
    }

    /**
     * Production never ran the migration that created pounds, so this has to be a clean no-op there
     * -- and on every new business after it.
     */
    public function testATenantWithNoPoundsIsUntouched(): void
    {
        $kg   = $this->seedItem('CLEANKG', 'Unidad: kilogramo', 'kg');
        $unit = $this->seedItem('CLEANUN', 'Unidad: unidad', 'unit');

        $this->runMigration();

        $this->assertSame('kg', $this->unitOf($kg));
        $this->assertSame('unit', $this->unitOf($unit));
    }

    /**
     * down() is deliberately empty: 'lb' is not a code this application accepts any more, so
     * putting rows back on it would write data the model cannot read.
     */
    public function testDownDoesNotPutThePoundBack(): void
    {
        $item = $this->seedItem('DOWN', 'Unidad: kilogramo', 'lb');

        $this->runMigration();
        $this->assertSame('kg', $this->unitOf($item));

        $this->migration()->down();

        $this->assertSame('kg', $this->unitOf($item), 'Rolling back must not restore a code nothing understands.');
    }
}
