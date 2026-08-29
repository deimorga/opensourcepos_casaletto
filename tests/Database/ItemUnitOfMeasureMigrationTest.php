<?php

namespace Tests\Database;

use App\Models\Item;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * The migration has to be a no-op for the business that is already selling.
 *
 * A tenant is in production with this same code and no interest in weight. When this migration runs
 * against its schema, every article it has must keep behaving exactly as it did the day before, and
 * every write path it already uses must keep working without ever mentioning the new column. That
 * is the claim these tests exist to hold, and it is the one claim that cannot be checked without a
 * real schema -- NOT NULL DEFAULT 'unit' is a promise the database keeps, not the application.
 *
 * Needs a live database. It was written against a host with the Docker daemon down, so it has not
 * been executed yet; run it before this reaches any tenant's schema.
 */
class ItemUnitOfMeasureMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $migrateOnce = true;
    protected $seed = '';
    protected $seedOnce = true;
    protected $refresh = true;
    protected $namespace = 'App';

    protected Item $item;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');

        // See the note in ItemsCsvImportTest: the seeder drops and recreates the database over its
        // own connection, so the shared one has to forget its cached table list.
        db_connect()->resetDataCache();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->item = model(Item::class);
    }

    /**
     * The whole safety argument in one assertion: the ALTER itself fills the column in, so there is
     * no window in which a pre-existing item has no unit and no backfill that could miss a row.
     */
    public function testItemsThatPredateTheColumnAreAllUnit(): void
    {
        $item_id = $this->givenAnItemSavedWithoutMentioningTheUnit();

        $this->seeInDatabase('items', [
            'item_id'         => $item_id,
            'unit_of_measure' => Item::UNIT_OF_MEASURE_UNIT,
        ]);
    }

    /**
     * The column is NOT NULL, so a write path that does not know about it must still succeed rather
     * than fail the INSERT. This is the shape of every save the existing tenant performs.
     */
    public function testSavingAnItemWithoutTheColumnStillWorks(): void
    {
        $item_id = $this->givenAnItemSavedWithoutMentioningTheUnit();

        $this->assertGreaterThan(0, $item_id, 'An item saved without a unit must still be created.');
    }

    /**
     * Editing an existing item without touching the unit must not reset it. save_value() sends the
     * whole array it is given, so a caller that omits the key must leave the stored value alone.
     */
    public function testEditingAnItemWithoutTheColumnKeepsItsUnit(): void
    {
        $item_id = $this->givenAnItemSavedWithoutMentioningTheUnit();

        $data = ['unit_of_measure' => Item::UNIT_OF_MEASURE_KG];
        $this->item->save_value($data, $item_id);

        $edit = ['unit_price' => '3500'];
        $this->item->save_value($edit, $item_id);

        $this->seeInDatabase('items', [
            'item_id'         => $item_id,
            'unit_of_measure' => Item::UNIT_OF_MEASURE_KG,
            'unit_price'      => '3500.00',
        ]);
    }

    /**
     * save_value() writes through the raw query builder and never consults $allowedFields, so the
     * model's field list is not what keeps the column clean. Reaching it through the normaliser --
     * which every controller path does -- is.
     */
    public function testTheNormaliserIsWhatKeepsJunkOutOfTheColumn(): void
    {
        $item_id = $this->givenAnItemSavedWithoutMentioningTheUnit();

        $data = ['unit_of_measure' => Item::normalize_unit_of_measure('kilogramo')];
        $this->item->save_value($data, $item_id);

        $this->seeInDatabase('items', [
            'item_id'         => $item_id,
            'unit_of_measure' => Item::UNIT_OF_MEASURE_UNIT,
        ]);
    }

    public function testAWeighedItemRoundTrips(): void
    {
        $item_id = $this->givenAnItemSavedWithoutMentioningTheUnit();

        $data = ['unit_of_measure' => Item::normalize_unit_of_measure('kg')];
        $this->item->save_value($data, $item_id);

        $this->assertSame(Item::UNIT_OF_MEASURE_KG, $this->item->get_info($item_id)->unit_of_measure);
    }

    /**
     * If the column is missing from the grid query the field is simply absent from every row, which
     * is how it would silently fail to appear in the items list.
     */
    public function testTheGridQuerySelectsTheColumn(): void
    {
        $this->givenAnItemSavedWithoutMentioningTheUnit();

        $reflection = new \ReflectionClass(Item::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString(
            'MAX(items.unit_of_measure) AS unit_of_measure',
            $source,
            'Without this the field never reaches the items grid.'
        );
    }

    /**
     * Exactly the array an existing tenant's save produces: no unit_of_measure key anywhere.
     */
    private function givenAnItemSavedWithoutMentioningTheUnit(): int
    {
        $data = [
            'name'                  => 'Aceite de oliva',
            'category'              => 'Despensa',
            'description'           => '',
            'cost_price'            => '1000',
            'unit_price'            => '2500',
            'reorder_level'         => '1',
            'receiving_quantity'    => '1',
            'allow_alt_description' => 0,
            'is_serialized'         => 0,
            'deleted'               => 0,
            'qty_per_pack'          => 1,
            'pack_name'             => 'Each',
            'hsn_code'              => '',
        ];

        $this->item->save_value($data);

        return (int)$data['item_id'];
    }
}
