<?php

namespace Tests\Models;

use App\Models\Item;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Covers which item a code resolves to when it could mean two things.
 *
 * items.item_number is free text, so a shop whose codes are short numbers -- 56, 214, 800 -- will
 * sooner or later have one that equals some OTHER item's item_id. Upstream ran a single query with
 * `item_number = X OR item_id = X` and a LIMIT 1, with no ORDER BY, so which row survived was
 * whatever the database returned first.
 *
 * That is how it was found: Paraiso de la Canasta imported 1.184 references, 212 of them with short
 * numeric codes, and ALL 212 collided. Typing 56 for an avocado rang up a cherry jelly -- and a
 * wrong-product sale is silent unless the cashier reads the line.
 */
class ItemCodeResolutionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const PREFIX = 'RESOL-';

    protected function setUp(): void
    {
        parent::setUp();
        $this->deleteFixtures();
    }

    protected function tearDown(): void
    {
        $this->deleteFixtures();
        parent::tearDown();
    }

    private function deleteFixtures(): void
    {
        db_connect()->table('items')->like('name', self::PREFIX, 'after')->delete();
    }

    /**
     * @return int the item_id the database assigned
     */
    private function seed(string $nameSuffix, string $itemNumber): int
    {
        $db = db_connect();
        $db->table('items')->insert([
            'name'                  => self::PREFIX . $nameSuffix,
            'category'              => 'Test',
            'item_number'           => $itemNumber,
            'cost_price'            => '0.00',
            'unit_price'            => '0.00',
            'reorder_level'         => '0',
            'receiving_quantity'    => '1',
            'allow_alt_description' => 0,
            'is_serialized'         => 0
        ]);

        return (int) $db->insertID();
    }

    /**
     * The defect, stated as an assertion.
     *
     * Two items: one whose PRINTED code is the number, another that merely happens to have been
     * given that number as its internal id. The printed code has to win.
     */
    public function testThePrintedCodeWinsOverAnotherItemsInternalId(): void
    {
        $item = model(Item::class);

        // Whatever id this one gets, a second item will carry it as its item_number.
        $victimId = $this->seed('VICTIMA', 'CODIGO-LARGO-7702354955236');
        $this->seed('EL-QUE-SE-BUSCA', (string) $victimId);

        $info = $item->get_info_by_id_or_number((string) $victimId);

        $this->assertNotSame('', $info, 'The code resolves to something.');
        $this->assertSame(
            self::PREFIX . 'EL-QUE-SE-BUSCA',
            $info->name,
            'A code typed at the till is an item_number, not somebody else\'s surrogate key.'
        );
    }

    public function testGetItemIdFollowsTheSameRule(): void
    {
        $item = model(Item::class);

        $victimId = $this->seed('VICTIMA2', 'CODIGO-LARGO-7702354955243');
        $wantedId = $this->seed('EL-QUE-SE-BUSCA2', (string) $victimId);

        $this->assertSame(
            $wantedId,
            $item->get_item_id((string) $victimId),
            'Both lookups have to agree about what a code means.'
        );
    }

    /**
     * The id is still a valid way in -- plenty of internal callers already hold one -- it is just
     * the second answer, not the first.
     */
    public function testAnIdStillResolvesWhenNoItemNumberClaimsIt(): void
    {
        $item = model(Item::class);
        $id = $this->seed('SOLO-POR-ID', 'CODIGO-QUE-NADIE-TECLEA');

        $info = $item->get_info_by_id_or_number((string) $id);

        $this->assertNotSame('', $info);
        $this->assertSame(self::PREFIX . 'SOLO-POR-ID', $info->name);
    }

    /**
     * A barcode with a leading zero is a barcode, never an id -- 00012345 is not item 12345.
     */
    public function testALeadingZeroIsNeverReadAsAnId(): void
    {
        $item = model(Item::class);
        $id = $this->seed('CERO-ADELANTE', 'no-lo-encuentra-asi');

        $this->assertSame(
            '',
            $item->get_info_by_id_or_number('0' . $id),
            'A leading zero marks a barcode; it must not fall through to the id.'
        );
    }

    public function testAnUnknownCodeResolvesToNothing(): void
    {
        $this->assertSame('', model(Item::class)->get_info_by_id_or_number('NO-EXISTE-ESTE-CODIGO'));
    }
}
