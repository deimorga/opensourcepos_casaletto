<?php

namespace Tests\Database;

use App\Database\Migrations\Migration_AddItemPriceHistory;
use App\Database\Migrations\Migration_SeedItemPriceHistory;
use CodeIgniter\Database\Database;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database as ConfigDatabase;

/**
 * La forma de la tabla del historial y la siembra de su punto de partida.
 *
 * Los índices se comprueban de verdad, no de vista: la tabla existe para responder tres preguntas
 * --«¿quién y cuándo cambió el precio de este artículo?», «¿qué cambió esta venta?» y «¿qué se
 * repreció ayer?»-- y un índice que falte convierte cada una en un recorrido completo el día que la
 * tabla tenga un año de tráfico.
 *
 * La siembra se prueba por lo que es fácil olvidar: que incluya los artículos en precio CERO --que
 * es el punto de partida que hace expresable «subió de 0 a 4.500»-- y que correrla dos veces no
 * duplique nada.
 */
class ItemPriceHistoryMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const PREFIX = 'SEED-PRICE-';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->resetDataCache();
        $this->deleteFixtures();
    }

    protected function tearDown(): void
    {
        $this->deleteFixtures();

        parent::tearDown();
    }

    private function deleteFixtures(): void
    {
        $ids = array_column(
            $this->db->table('items')->select('item_id')->like('name', self::PREFIX, 'after')->get()->getResultArray(),
            'item_id'
        );

        if ($ids !== []) {
            $this->db->table('item_price_history')->whereIn('item_id', $ids)->delete();
            $this->db->table('item_quantities')->whereIn('item_id', $ids)->delete();
            $this->db->table('items')->whereIn('item_id', $ids)->delete();
        }
    }

    /**
     * Los fixtures de artículos borran antes de insertar: `items.item_number` no es único y
     * $refresh está apagado, así que las copias se acumularían entre métodos.
     */
    private function seedItem(string $nameSuffix, string $unitPrice, int $deleted = 0): int
    {
        $this->db->table('items')->insert([
            'name'                  => self::PREFIX . $nameSuffix,
            'category'              => 'Test',
            'item_number'           => self::PREFIX . $nameSuffix,
            'cost_price'            => '0.00',
            'unit_price'            => $unitPrice,
            'reorder_level'         => '0',
            'receiving_quantity'    => '1',
            'allow_alt_description' => 0,
            'is_serialized'         => 0,
            'deleted'               => $deleted,
        ]);

        return (int) $this->db->insertID();
    }

    private function seedMigration(): Migration_SeedItemPriceHistory
    {
        return new Migration_SeedItemPriceHistory(ConfigDatabase::forge('tests'));
    }

    // ========== La forma de la tabla ==========

    public function testTheTableIsCreatedWithEveryColumnTheHistoryNeeds(): void
    {
        $columnas = $this->db->getFieldNames('item_price_history');

        sort($columnas);
        $esperadas = array_merge(['price_history_id'], Migration_AddItemPriceHistory::WRITABLE_COLUMNS);
        sort($esperadas);

        $this->assertSame($esperadas, $columnas);
    }

    /**
     * `previous_price`, `employee_id` y `sale_id` TIENEN que aceptar NULL. Los tres significan «no
     * se sabe» o «no aplica», y forzarlos a un valor obligaría a inventar un precio anterior, un
     * autor o una venta que no existieron.
     */
    public function testTheColumnsThatMeanNothingIsKnownAcceptNull(): void
    {
        $porNombre = [];

        foreach ($this->db->getFieldData('item_price_history') as $campo) {
            $porNombre[$campo->name] = $campo;
        }

        $this->assertTrue($porNombre['previous_price']->nullable, 'previous_price acepta NULL.');
        $this->assertTrue($porNombre['employee_id']->nullable, 'employee_id acepta NULL.');
        $this->assertTrue($porNombre['sale_id']->nullable, 'sale_id acepta NULL.');
        $this->assertFalse($porNombre['new_price']->nullable, 'new_price nunca es NULL.');
        $this->assertFalse($porNombre['item_id']->nullable, 'item_id nunca es NULL.');
    }

    public function testTheIndexesTheThreeQuestionsNeedAreThere(): void
    {
        $indices = [];

        foreach (Database::connect()->getIndexData('item_price_history') as $indice) {
            $indices[$indice->name] = $indice->fields;
        }

        $this->assertArrayHasKey('idx_item_changed', $indices, 'Falta el índice de «este artículo, ordenado por tiempo».');
        $this->assertSame(['item_id', 'changed_at'], $indices['idx_item_changed'], 'El orden del compuesto importa: primero el artículo.');
        $this->assertArrayHasKey('idx_sale_id', $indices, 'Falta el índice de «¿qué cambió esta venta?».');
        $this->assertArrayHasKey('idx_changed_at', $indices, 'Falta el índice de «¿qué se repreció ayer?».');
    }

    // ========== La siembra ==========

    public function testTheSeedGivesAnItemWithoutHistoryExactlyOneStartingRow(): void
    {
        $itemId = $this->seedItem('CON-PRECIO', '4500.00');

        $this->seedMigration()->up();

        $filas = $this->db->table('item_price_history')->where('item_id', $itemId)->get()->getResultArray();

        $this->assertCount(1, $filas);
        $this->assertSame('4500.00', $filas[0]['new_price']);
        $this->assertNull($filas[0]['previous_price'], 'La fila de partida no inventa un precio anterior.');
        $this->assertSame('seed', $filas[0]['source']);
        $this->assertNull($filas[0]['employee_id'], 'No hay autor: nadie puso ese precio hoy.');
    }

    /**
     * Los 1.184 artículos de Paraíso están en cero. Sin su fila de partida, el día que se les ponga
     * precio el historial diría «apareció un 4.500» en vez de «subió de 0 a 4.500» -- y nadie podría
     * preguntar cuántos artículos no se han preciado nunca.
     */
    public function testTheSeedIncludesItemsPricedAtZero(): void
    {
        $itemId = $this->seedItem('EN-CERO', '0.00');

        $this->seedMigration()->up();

        $fila = $this->db->table('item_price_history')->where('item_id', $itemId)->get()->getRowArray();

        $this->assertNotNull($fila, 'Un artículo en cero también tiene punto de partida.');
        $this->assertSame('0.00', $fila['new_price']);
    }

    /**
     * Un artículo borrado puede resucitar: `Sale::save_value()` llama a `undelete()` cuando vuelve
     * en una devolución. Si no se sembró, reaparece con un historial que empieza en el aire.
     */
    public function testTheSeedIncludesDeletedItems(): void
    {
        $itemId = $this->seedItem('BORRADO', '3000.00', 1);

        $this->seedMigration()->up();

        $this->assertNotNull(
            $this->db->table('item_price_history')->where('item_id', $itemId)->get()->getRowArray()
        );
    }

    public function testRunningTheSeedTwiceDoesNotDuplicate(): void
    {
        $itemId = $this->seedItem('DOS-VECES', '1500.00');

        $this->seedMigration()->up();
        $this->seedMigration()->up();

        $this->assertSame(
            1,
            $this->db->table('item_price_history')->where('item_id', $itemId)->countAllResults(),
            'La idempotencia vive en el WHERE NOT EXISTS, no en una bandera.'
        );
    }

    /**
     * Correrla tarde --cuando un artículo ya tiene historial real-- no debe pisar nada, y sigue
     * siendo correcta para los que no ha alcanzado.
     */
    public function testTheSeedLeavesAnItemThatAlreadyHasHistoryAlone(): void
    {
        $conHistorial = $this->seedItem('YA-TIENE', '7000.00');
        $sinHistorial = $this->seedItem('AUN-NO', '8000.00');

        $this->db->table('item_price_history')->insert([
            'item_id'    => $conHistorial,
            'new_price'  => '6000.00',
            'changed_at' => '2026-01-01 00:00:00',
            'source'     => 'item_form',
        ]);

        $this->seedMigration()->up();

        $filas = $this->db->table('item_price_history')->where('item_id', $conHistorial)->get()->getResultArray();

        $this->assertCount(1, $filas, 'No se añade una segunda partida a quien ya tenía historial.');
        $this->assertSame('6000.00', $filas[0]['new_price']);

        $this->assertSame(
            1,
            $this->db->table('item_price_history')->where('item_id', $sinHistorial)->countAllResults(),
            'Y el que no tenía sí se siembra.'
        );
    }
}
