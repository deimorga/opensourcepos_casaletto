<?php

namespace Tests\Models;

use App\Models\Item;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Covers whether saving an existing item updates it or quietly creates a second one.
 *
 * Item::exists() used to ask `item_id = X OR item_number = X` and then require EXACTLY one row.
 * With short item codes -- 56, 214, 800 -- an item's id sooner or later equals some OTHER item's
 * code, two rows come back, and the function answered "does not exist" about an item that plainly
 * does. save_value() reads that as "create it", so **editing the item duplicated it**.
 *
 * Found in production at Paraiso de la Canasta: 212 of 1.184 items were affected, and the duplicate
 * is invisible in the items grid because it is born with no stock rows -- so the shop saw a save
 * that appeared to do nothing, and then a "code already in the database" error blocking every
 * later attempt. It is the same collision that made a typed 56 ring up the wrong product; that one
 * was fixed in get_info_by_id_or_number() and this caller was missed.
 *
 * @internal
 */
final class ItemSaveDuplicatesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const PREFIX = 'DUPSAVE-';

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->item = model(Item::class);
        $this->deleteFixtures();
    }

    protected function tearDown(): void
    {
        $this->deleteFixtures();
        parent::tearDown();
    }

    /**
     * Fixtures delete before inserting: $refresh is off in this suite, so leftovers from a previous
     * method accumulate and a stale row would answer the query instead of the intended one.
     */
    private function deleteFixtures(): void
    {
        db_connect()->table('items')->like('name', self::PREFIX, 'after')->delete();
    }

    private function seed(string $nameSuffix, string $itemNumber): int
    {
        $db = db_connect();
        $db->table('items')->insert([
            'name'        => self::PREFIX . $nameSuffix,
            'category'    => 'Test',
            'item_number' => $itemNumber,
            'cost_price'  => '0.00',
            'unit_price'  => '0.00',
            'deleted'     => 0
        ]);

        return (int)$db->insertID();
    }

    private function countWithName(string $nameSuffix): int
    {
        return db_connect()->table('items')
            ->where('name', self::PREFIX . $nameSuffix)
            ->where('deleted', 0)
            ->countAllResults();
    }

    /**
     * The regression. A second item whose CODE equals the first item's ID must not stop the first
     * one from being saved.
     */
    public function testSavingAnItemWhoseIdEqualsAnotherItemsCodeUpdatesItInsteadOfDuplicating(): void
    {
        $victim = $this->seed('VICTIMA', 'DUPSAVE-CODIGO-LARGO');

        // The landmine: another item whose item_number is, literally, the first one's id.
        $this->seed('MINA', (string)$victim);

        $data = ['unit_price' => '5400.00'];
        $this->assertTrue($this->item->save_value($data, $victim), 'el guardado falló');

        $this->assertSame(1, $this->countWithName('VICTIMA'), 'editar el artículo creó un duplicado');
        $this->assertSame(
            '5400.00',
            db_connect()->table('items')->where('item_id', $victim)->get()->getRow()->unit_price,
            'el precio no llegó al artículo original'
        );
    }

    public function testAnItemIsFoundByItsIdEvenWhenAnotherItemUsesThatNumberAsItsCode(): void
    {
        $victim = $this->seed('EXISTE', 'DUPSAVE-OTRO-CODIGO');
        $this->seed('MINA2', (string)$victim);

        $this->assertTrue($this->item->exists((string)$victim, true));
    }

    /**
     * The flip side: an id that belongs to nobody must still answer "no", even when some item
     * happens to carry that number as its code. Otherwise the import validation would accept a
     * row pointing at an item that does not exist.
     */
    public function testAnIdThatBelongsToNobodyIsStillReportedMissing(): void
    {
        $inventado = 999999999;
        $this->seed('SOLO-CODIGO', (string)$inventado);

        $this->assertFalse($this->item->exists((string)$inventado, true));
    }
}
