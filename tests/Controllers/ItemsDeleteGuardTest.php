<?php

namespace Tests\Controllers;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;

/**
 * Deleting an item from the Items screen (2026-09-24, docs/Tecnico/articulos-e-ingredientes.md).
 *
 * Casaletto's team replaced products by deleting the old item and creating a new one. Nothing warned
 * them that the old one was an ingredient: thirteen recipes kept pointing at nine deleted items, and
 * every sale of them failed to add -- and to discount -- the missing ingredient for weeks. Finding
 * who had deleted them meant reconstructing it from sales, because a deletion of an item with no
 * stock left no trace.
 *
 * Pinned here: an ingredient of an active recipe is not deleted, and the refusal names the recipe;
 * an ingredient of a deleted recipe can be; and every deletion leaves who and when in the item's
 * inventory history. Everything this file creates is removed in tearDown (shared test database).
 *
 * @internal
 */
final class ItemsDeleteGuardTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    /** @var list<int> */
    private array $items = [];

    /** @var list<int> */
    private array $kits = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->resetDataCache();
    }

    protected function tearDown(): void
    {
        if ($this->kits !== []) {
            $this->db->table('item_kit_items')->whereIn('item_kit_id', $this->kits)->delete();
            $this->db->table('item_kits')->whereIn('item_kit_id', $this->kits)->delete();
        }

        if ($this->items !== []) {
            $this->db->table('inventory')->whereIn('trans_items', $this->items)->delete();
            $this->db->table('item_quantities')->whereIn('item_id', $this->items)->delete();
            $this->db->table('items')->whereIn('item_id', $this->items)->delete();
        }

        parent::tearDown();
    }

    public function testAnIngredientOfAnActiveRecipeIsNotDeletedAndTheRecipeIsNamed(): void
    {
        $ingredient = $this->createItem('TEST-DEL-PECHUGA', 'Pechuga de prueba');
        $this->createKit('TEST-DEL-SANDWICH', 'Sandwich de prueba', $ingredient);

        $result = json_decode($this->deleteItems([$ingredient])->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Pechuga de prueba', $result['message']);
        $this->assertStringContainsString('Sandwich de prueba', $result['message']);
        $this->assertSame(0, (int) $this->db->table('items')->where('item_id', $ingredient)->get()->getRow()->deleted);
    }

    /**
     * A selection with one blocked item deletes nothing: all or nothing, as the screen shows it.
     */
    public function testASelectionWithOneBlockedItemDeletesNothing(): void
    {
        $ingredient = $this->createItem('TEST-DEL-QUESO', 'Queso de prueba');
        $free       = $this->createItem('TEST-DEL-LIBRE', 'Articulo libre');
        $this->createKit('TEST-DEL-TABLA', 'Tabla de prueba', $ingredient);

        $result = json_decode($this->deleteItems([$free, $ingredient])->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertSame(0, (int) $this->db->table('items')->where('item_id', $free)->get()->getRow()->deleted);
    }

    public function testAnIngredientOfADeletedRecipeCanBeDeleted(): void
    {
        $ingredient = $this->createItem('TEST-DEL-VIEJO', 'Ingrediente de receta vieja');
        $kitItem    = $this->createKit('TEST-DEL-RECETA-VIEJA', 'Receta retirada', $ingredient);
        $this->db->table('items')->where('item_id', $kitItem)->update(['deleted' => 1]);

        $result = json_decode($this->deleteItems([$ingredient])->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame(1, (int) $this->db->table('items')->where('item_id', $ingredient)->get()->getRow()->deleted);
    }

    /**
     * Before, an item with zero or negative stock left nothing behind when deleted.
     */
    public function testEveryDeletionRecordsWhoAndWhenEvenWithNoStock(): void
    {
        $item = $this->createItem('TEST-DEL-SIN-STOCK', 'Articulo sin existencias', '-3');

        $this->assertTrue(json_decode($this->deleteItems([$item])->getJSON(), true)['success']);

        $row = $this->db->table('inventory')->where('trans_items', $item)->where('trans_comment', lang('Items.deletion_record'))->get()->getRow();

        $this->assertNotNull($row, 'The deletion is in the item\'s inventory history.');
        $this->assertSame(1, (int) $row->trans_user);
        $this->assertSame('0.000', (string) $row->trans_inventory, 'It records the act, it changes no quantity.');
    }

    private function deleteItems(array $ids): TestResponse
    {
        $_SESSION = ['person_id' => 1, 'menu_group' => 'home'];
        $this->withSession($_SESSION);

        return $this->post('items/delete', ['ids' => array_map('strval', $ids)]);
    }

    private function createItem(string $number, string $name, string $stock = '5'): int
    {
        $this->db->table('items')->insert([
            'name' => $name, 'category' => 'Test', 'item_number' => $number, 'description' => 'ItemsDeleteGuardTest',
            'cost_price' => '1.00', 'unit_price' => '10.00', 'reorder_level' => '0', 'receiving_quantity' => '1',
            'allow_alt_description' => 0, 'is_serialized' => 0,
        ]);
        $id            = (int) $this->db->insertID();
        $this->items[] = $id;

        $this->db->table('item_quantities')->insert(['item_id' => $id, 'location_id' => 1, 'quantity' => $stock]);

        return $id;
    }

    /**
     * @return int the kit's own item id
     */
    private function createKit(string $number, string $name, int $ingredient): int
    {
        $kitItem = $this->createItem($number, $name);
        $this->db->table('items')->where('item_id', $kitItem)->update(['item_type' => ITEM_KIT]);

        $this->db->table('item_kits')->insert([
            'item_kit_number' => $number, 'name' => $name, 'description' => $name, 'item_id' => $kitItem,
            'kit_discount' => '0.00', 'kit_discount_type' => PERCENT, 'price_option' => PRICE_OPTION_ALL, 'print_option' => PRINT_ALL,
        ]);
        $this->kits[] = (int) $this->db->insertID();

        $this->db->table('item_kit_items')->insert(['item_kit_id' => end($this->kits), 'item_id' => $ingredient, 'quantity' => '1', 'kit_sequence' => 1]);

        return $kitItem;
    }
}
