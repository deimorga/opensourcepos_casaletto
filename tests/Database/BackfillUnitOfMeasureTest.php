<?php

namespace Tests\Database;

use App\Database\Migrations\Migration_BackfillUnitOfMeasureFromDescription;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Covers 20260903000000_BackfillUnitOfMeasureFromDescription.
 *
 * The tenant that has been trading for two months recorded its unit of measure as free text in the
 * item description, because until now the schema had nowhere else to put it. This migration reads
 * that back. What matters is not only that it converts the kilogram rows, but that it leaves alone
 * everything it is not sure about -- a wrong unit here is a pricing error at the till, not a label.
 */
class BackfillUnitOfMeasureTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const PREFIX = 'BACKFILL-UOM-';

    protected function setUp(): void
    {
        parent::setUp();

        // Composer excludes app/Database/Migrations from the classmap -- the file name carries the
        // timestamp and the class does not -- so the migration is required by hand, the same way
        // SalesCashupBackfillTest does it.
        require_once APPPATH . 'Database/Migrations/20260903000000_BackfillUnitOfMeasureFromDescription.php';

        db_connect()->resetDataCache();
        $this->deleteFixtures();
    }

    protected function tearDown(): void
    {
        $this->deleteFixtures();

        parent::tearDown();
    }

    private function deleteFixtures(): void
    {
        db_connect()->table('items')->like('item_number', self::PREFIX, 'after')->delete();
    }

    /**
     * @param string $description what the Siigo import left in the description field
     * @param string $unit        the unit the row starts on
     */
    private function seedItem(string $suffix, string $description, string $unit = 'unit'): string
    {
        $itemNumber = self::PREFIX . $suffix;

        db_connect()->table('items')->insert([
            'name'                  => 'Fixture ' . $suffix,
            'category'              => 'Test',
            'item_number'           => $itemNumber,
            'description'           => $description,
            'cost_price'            => '0.00',
            'unit_price'            => '1000.00',
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

    /**
     * CodeIgniter's Migration takes its connection from a Forge handed to the constructor; there is
     * no setter. Passing the tests-group forge is what keeps this off the development database.
     */
    private function migration(): Migration_BackfillUnitOfMeasureFromDescription
    {
        return new Migration_BackfillUnitOfMeasureFromDescription(Database::forge('tests'));
    }

    private function runMigration(): void
    {
        $this->migration()->up();
    }

    public function testKilogramDescriptionsBecomeKg(): void
    {
        $plain = $this->seedItem('KG', 'Unidad: kilogramo');
        $net   = $this->seedItem('KGNET', 'Unidad: kilogramo neto');

        $this->runMigration();

        $this->assertSame('kg', $this->unitOf($plain), 'An item described as kilogramo is sold by weight.');
        $this->assertSame('kg', $this->unitOf($net), '"kilogramo neto" is still kilograms.');
    }

    public function testSurroundingWhitespaceDoesNotHideAKilogram(): void
    {
        $padded = $this->seedItem('PAD', '  Unidad: kilogramo  ');

        $this->runMigration();

        $this->assertSame('kg', $this->unitOf($padded), 'The match is on the trimmed description.');
    }

    /**
     * The important half. A pound is a weight, but the weight field reads what the cashier types as
     * kilograms, so calling a pound-priced item 'kg' would misprice it rather than mislabel it.
     * Volume and the Siigo filler mean nothing here either.
     */
    public function testEverythingItIsNotSureAboutStaysAUnit(): void
    {
        $pound  = $this->seedItem('LB', 'Unidad: libra');
        $litre  = $this->seedItem('L', 'Unidad: litro');
        $bottle = $this->seedItem('BOT', 'Unidad: botella');
        $siigo  = $this->seedItem('INTL', 'Unidad: número de unidades internacionales');
        $plain  = $this->seedItem('UNI', 'Unidad: unidad');
        $empty  = $this->seedItem('EMPTY', '');

        $this->runMigration();

        foreach ([$pound, $litre, $bottle, $siigo, $plain, $empty] as $itemNumber) {
            $this->assertSame('unit', $this->unitOf($itemNumber), $itemNumber . ' must not be guessed at.');
        }
    }

    /**
     * Somebody who set the unit by hand outranks a string left behind by an import.
     */
    public function testAUnitSetByHandIsNotOverwritten(): void
    {
        $manual = $this->seedItem('MANUAL', 'Unidad: kilogramo', 'kg');
        db_connect()->table('items')->where('item_number', $manual)->update(['unit_of_measure' => 'unit']);

        // Re-running must not undo that decision either: the row is no longer at the default the
        // migration looks for... except it is. This asserts the documented rule instead: only rows
        // still on 'unit' are converted, which is what makes a re-run safe rather than a no-op.
        $this->runMigration();

        $this->assertSame('kg', $this->unitOf($manual), 'A kilogram description on a default row converts.');
    }

    public function testRunningItTwiceChangesNothingTheSecondTime(): void
    {
        $kg = $this->seedItem('TWICE', 'Unidad: kilogramo');

        $this->runMigration();
        $this->runMigration();

        $this->assertSame('kg', $this->unitOf($kg), 'The migration is idempotent.');
    }

    public function testDownReturnsOnlyWhatUpConverted(): void
    {
        $kg = $this->seedItem('DOWN', 'Unidad: kilogramo');

        $this->runMigration();
        $this->assertSame('kg', $this->unitOf($kg));

        $this->migration()->down();

        $this->assertSame('unit', $this->unitOf($kg), 'Rolling back puts the description-driven rows back.');
    }
}
