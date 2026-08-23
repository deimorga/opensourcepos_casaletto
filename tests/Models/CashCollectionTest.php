<?php

namespace Tests\Models;

use App\Database\Migrations\Migration_AddCashCollections;
use App\Models\Cash_collection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The register of cash taken out of the drawer.
 *
 * Most of what is worth proving here is about the window: a collection has to land in the shift it
 * actually happened in, and the total the reconciliation subtracts has to agree with the list the
 * shift shows.
 */
class CashCollectionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private Cash_collection $collections;

    protected function setUp(): void
    {
        parent::setUp();

        $this->collections = model(Cash_collection::class);
        $this->db->table('cash_collections')->truncate();
    }

    /**
     * The bug this guards against does not raise anything: CodeIgniter drops a field missing from
     * $allowedFields in silence, and here the field it would drop is money or the time the money
     * left. Reading the columns from the database rather than from a list in the test means a
     * column added later without touching the model fails here instead of in production.
     */
    public function testAllowedFieldsCoverEveryWritableColumnOfTheTable(): void
    {
        $columns = $this->db->getFieldNames('cash_collections');
        $writable = array_values(array_diff($columns, ['collection_id']));

        sort($writable);
        $allowed = $this->collections->allowedFields;
        sort($allowed);

        $this->assertSame($writable, $allowed);
    }

    /**
     * The migration's own list has to say the same thing, since it is what the next reader will
     * compare against when adding a column.
     */
    public function testMigrationAndModelAgreeOnTheWritableColumns(): void
    {
        $expected = Migration_AddCashCollections::WRITABLE_COLUMNS;
        sort($expected);

        $allowed = $this->collections->allowedFields;
        sort($allowed);

        $this->assertSame($expected, $allowed);
    }

    public function testTotalCollectedIsZeroWhenNothingWasCollected(): void
    {
        $total = $this->collections->get_total_collected_between('2026-08-17 14:26:25', '2026-08-17 20:54:56');

        $this->assertSame(0.0, $total);
    }

    public function testTotalCollectedAddsUpEveryCollectionInsideTheWindow(): void
    {
        $this->insertCollection('2026-08-17 15:00:00', 1000000.00);
        $this->insertCollection('2026-08-17 18:30:00', 800000.00);

        $total = $this->collections->get_total_collected_between('2026-08-17 14:26:25', '2026-08-17 20:54:56');

        $this->assertSame(1800000.0, $total);
    }

    public function testTotalCollectedIgnoresDeletedCollections(): void
    {
        $this->insertCollection('2026-08-17 15:00:00', 1000000.00);
        $this->insertCollection('2026-08-17 18:30:00', 800000.00, 1);

        $total = $this->collections->get_total_collected_between('2026-08-17 14:26:25', '2026-08-17 20:54:56');

        $this->assertSame(1000000.0, $total);
    }

    public function testTotalCollectedLeavesOutWhatHappenedBeforeOrAfterTheWindow(): void
    {
        $this->insertCollection('2026-08-17 14:26:24', 500000.00);
        $this->insertCollection('2026-08-17 20:54:57', 300000.00);

        $total = $this->collections->get_total_collected_between('2026-08-17 14:26:25', '2026-08-17 20:54:56');

        $this->assertSame(0.0, $total);
    }

    /**
     * Shift 39 in production ran from 21:58:10 to 21:58:54 -- 44 seconds. A window that dropped its
     * own edges would have almost nothing left to catch a collection in.
     */
    public function testTotalCollectedCountsBothEndsOfTheWindow(): void
    {
        $this->insertCollection('2026-08-21 21:58:10', 800000.00);
        $this->insertCollection('2026-08-21 21:58:54', 100000.00);

        $total = $this->collections->get_total_collected_between('2026-08-21 21:58:10', '2026-08-21 21:58:54');

        $this->assertSame(900000.0, $total);
    }

    /**
     * A shift that is still open has no close_date, and the reconciliation is shown while the
     * cashier is closing it.
     */
    public function testTotalCollectedWithoutAnEndDateHasNoUpperBound(): void
    {
        $this->insertCollection('2026-08-17 14:26:24', 500000.00);
        $this->insertCollection('2026-08-17 15:00:00', 1000000.00);
        $this->insertCollection('2027-01-01 09:00:00', 250000.00);

        $total = $this->collections->get_total_collected_between('2026-08-17 14:26:25');

        $this->assertSame(1250000.0, $total);
    }

    public function testCollectedBetweenReturnsExactlyWhatTheTotalCounted(): void
    {
        $this->insertCollection('2026-08-17 15:00:00', 1000000.00);
        $this->insertCollection('2026-08-17 18:30:00', 800000.00);
        $this->insertCollection('2026-08-17 19:00:00', 700000.00, 1);
        $this->insertCollection('2026-08-17 21:00:00', 600000.00);

        $rows = $this->collections->get_collected_between('2026-08-17 14:26:25', '2026-08-17 20:54:56')->getResult();
        $listed = array_sum(array_map(static fn ($row) => (float)$row->amount, $rows));

        $this->assertCount(2, $rows);
        $this->assertSame(
            $this->collections->get_total_collected_between('2026-08-17 14:26:25', '2026-08-17 20:54:56'),
            $listed
        );
    }

    public function testCollectedBetweenComesBackInChronologicalOrder(): void
    {
        $this->insertCollection('2026-08-17 18:30:00', 800000.00);
        $this->insertCollection('2026-08-17 15:00:00', 1000000.00);

        $rows = $this->collections->get_collected_between('2026-08-17 14:26:25', '2026-08-17 20:54:56')->getResult();

        $this->assertSame('2026-08-17 15:00:00', $rows[0]->collected_at);
        $this->assertSame('2026-08-17 18:30:00', $rows[1]->collected_at);
    }

    /**
     * The join onto people is LEFT so that a collection never disappears from the shift it belongs
     * to because the person row behind it changed. The money moved either way.
     */
    public function testCollectedBetweenKeepsCollectionsWhoseEmployeeIsUnknown(): void
    {
        $this->insertCollection('2026-08-17 15:00:00', 1000000.00);

        $rows = $this->collections->get_collected_between('2026-08-17 14:26:25', '2026-08-17 20:54:56')->getResult();

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->collected_by_first_name);
    }

    public function testSaveValueHandsBackTheNewCollectionId(): void
    {
        $collection = [
            'amount'        => 1000000.00,
            'collected_at'  => '2026-08-17 17:40:40',
            'collected_by'  => 5,
            'registered_by' => 5,
            'note'          => 'Recogida de prueba',
            'deleted'       => 0,
        ];

        $saved = $this->collections->save_value($collection);

        $this->assertTrue($saved);
        $this->assertArrayHasKey('collection_id', $collection);
        $this->assertGreaterThan(0, $collection['collection_id']);
    }

    /**
     * Every field the caller sends has to survive the save. This is the shape the silent
     * $allowedFields drop takes when it happens.
     */
    public function testSaveValueStoresEveryFieldItWasGiven(): void
    {
        $collection = [
            'amount'        => 800000.00,
            'collected_at'  => '2026-08-21 21:58:32',
            'collected_by'  => 5,
            'registered_by' => 7,
            'note'          => 'Recogida confirmada',
            'deleted'       => 0,
        ];

        $this->collections->save_value($collection);
        $stored = $this->collections->get_info((int)$collection['collection_id']);

        $this->assertSame(800000.0, (float)$stored->amount);
        $this->assertSame('2026-08-21 21:58:32', $stored->collected_at);
        $this->assertSame(5, (int)$stored->collected_by);
        $this->assertSame(7, (int)$stored->registered_by);
        $this->assertSame('Recogida confirmada', $stored->note);
    }

    /**
     * MySQL gives the first TIMESTAMP column of a table an implicit "ON UPDATE CURRENT_TIMESTAMP"
     * when it is declared without a default, which would rewrite the time the money left every time
     * somebody fixed a typo in the note -- and that time is the only thing tying the collection to
     * a shift. The migration names a default to switch that off; this proves it stayed off.
     */
    public function testEditingACollectionDoesNotMoveTheTimeTheMoneyLeft(): void
    {
        $id = $this->insertCollection('2026-08-17 17:40:40', 1000000.00);

        $edit = ['note' => 'Nota corregida'];
        $this->collections->save_value($edit, $id);

        $this->assertSame('2026-08-17 17:40:40', $this->collections->get_info($id)->collected_at);
    }

    public function testGetInfoReturnsABlankObjectCarryingEveryFieldWhenTheCollectionIsUnknown(): void
    {
        $unknown = $this->collections->get_info(999999);

        foreach ($this->db->getFieldNames('cash_collections') as $field) {
            $this->assertObjectHasProperty($field, $unknown);
            $this->assertSame('', $unknown->{$field});
        }
    }

    public function testGetInfoTreatsADeletedCollectionAsUnknown(): void
    {
        $id = $this->insertCollection('2026-08-17 15:00:00', 1000000.00, 1);

        $this->assertSame('', $this->collections->get_info($id)->collection_id);
    }

    /**
     * A collection that turns out to be wrong still has to be auditable: it moved real money out of
     * a drawer that was counted afterwards.
     */
    public function testDeleteListMarksCollectionsDeletedWithoutRemovingTheRow(): void
    {
        $first = $this->insertCollection('2026-08-17 15:00:00', 1000000.00);
        $second = $this->insertCollection('2026-08-17 18:30:00', 800000.00);

        $this->collections->delete_list([$first, $second]);

        $this->assertSame(2, $this->db->table('cash_collections')->countAllResults());
        $this->assertSame(0.0, $this->collections->get_total_collected_between('2026-08-17 00:00:00', '2026-08-17 23:59:59'));
    }

    /**
     * exists() deliberately ignores the deleted flag, because save_value uses it to tell an update
     * from an insert: a deleted collection that gets corrected has to be updated, not duplicated.
     */
    public function testSaveValueUpdatesADeletedCollectionInsteadOfDuplicatingIt(): void
    {
        $id = $this->insertCollection('2026-08-17 15:00:00', 1000000.00, 1);

        $revival = ['deleted' => 0, 'amount' => 900000.00];
        $this->collections->save_value($revival, $id);

        $this->assertSame(1, $this->db->table('cash_collections')->countAllResults());
        $this->assertSame(900000.0, $this->collections->get_total_collected_between('2026-08-17 00:00:00', '2026-08-17 23:59:59'));
    }

    private function insertCollection(string $collected_at, float $amount, int $deleted = 0): int
    {
        $this->db->table('cash_collections')->insert([
            'amount'        => $amount,
            'collected_at'  => $collected_at,
            'collected_by'  => 5,
            'registered_by' => 5,
            'note'          => '',
            'deleted'       => $deleted,
        ]);

        return (int)$this->db->insertID();
    }
}
