<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\Attribute;
use App\Models\Item;
use App\Models\Item_quantity;
use App\Models\Item_taxes;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\OSPOS;

/**
 * Las lecturas en lote de las que depende que exportar 1.184 artículos no tumbe el servidor.
 *
 * LO QUE ESTAS PRUEBAS FIJAN
 *
 * Leyendo artículo por artículo, exportar el catálogo de Paraíso son **~4.700 consultas**: una por
 * artículo para los impuestos, una por artículo y bodega para las existencias, una por artículo para
 * los atributos. Aquí se afirma que cada una de esas tres cosas es **UNA** consulta, contándolas. Sin
 * contarlas, «en lote» es una intención que se pierde en el primer refactor.
 *
 * Y la de exportación fija dos cosas que ya han mordido: que los kits y temporales no salen, y que el
 * orden es por `item_id` -- porque `items.name` NO es único (Casaletto tiene 3 repetidos, Paraíso 14)
 * y paginar por él salta y repite filas.
 *
 * @internal
 */
final class ItemBulkReadsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private Item $item;
    private Item_taxes $taxes;
    private Item_quantity $quantities;
    private Attribute $attributes;

    /** @var list<int> */
    private array $sembrados = [];

    protected function setUp(): void
    {
        parent::setUp();

        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();

        $this->item       = model(Item::class);
        $this->taxes      = model(Item_taxes::class);
        $this->quantities = model(Item_quantity::class);
        $this->attributes = model(Attribute::class);
    }

    protected function tearDown(): void
    {
        // La suite comparte una sola base y no la refresca entre pruebas: lo que se siembra aquí se lo
        // encuentran las de otros archivos. Y los fixtures de artículos SE BORRAN ANTES DE INSERTAR
        // por costumbre de la casa, así que dejar restos rompe pruebas ajenas de forma difícil de ver.
        if ($this->sembrados !== []) {
            db_connect()->table('items_taxes')->whereIn('item_id', $this->sembrados)->delete();
            db_connect()->table('item_quantities')->whereIn('item_id', $this->sembrados)->delete();
            db_connect()->table('items')->whereIn('item_id', $this->sembrados)->delete();
        }

        parent::tearDown();
    }

    private function crearArticulo(string $nombre, string $codigo, int $tipo = ITEM, int $borrado = 0): int
    {
        db_connect()->table('items')->insert([
            'name'        => $nombre,
            'item_number' => $codigo,
            'category'    => 'Pruebas',
            'cost_price'  => 0,
            'unit_price'  => 0,
            'item_type'   => $tipo,
            'deleted'     => $borrado,
        ]);

        $id = (int) db_connect()->insertID();
        $this->sembrados[] = $id;

        return $id;
    }

    // ========== Qué sale en la exportación ==========

    public function testKitsAmountEntriesAndTemporariesStayOut(): void
    {
        $normal = $this->crearArticulo('Normal de prueba', 'PRUEBA-N');
        $kit    = $this->crearArticulo('Kit de prueba', 'PRUEBA-K', ITEM_KIT);
        $monto  = $this->crearArticulo('Monto de prueba', 'PRUEBA-M', ITEM_AMOUNT_ENTRY);
        $temp   = $this->crearArticulo('Temporal de prueba', 'PRUEBA-T', ITEM_TEMP);

        $ids = array_map(
            static fn ($fila): int => (int) $fila->item_id,
            $this->item->get_all_for_export()->getResult(),
        );

        $this->assertContains($normal, $ids);
        $this->assertNotContains($kit, $ids, 'Un kit no se puede reconstruir desde estas columnas.');
        $this->assertNotContains($monto, $ids);
        $this->assertNotContains($temp, $ids, 'Los temporales los crea el POS al cobrar algo suelto: reimportarlos los resucita.');
    }

    public function testDeletedItemsStayOut(): void
    {
        $borrado = $this->crearArticulo('Borrado de prueba', 'PRUEBA-B', ITEM, 1);

        $ids = array_map(
            static fn ($fila): int => (int) $fila->item_id,
            $this->item->get_all_for_export()->getResult(),
        );

        $this->assertNotContains($borrado, $ids);
    }

    /**
     * `get_all()` ordena por `items.name`, que no es único. Paginar por una columna repetida hace que
     * el motor pueda devolver la misma fila en dos lotes y saltarse otra: el archivo saldría con
     * artículos duplicados y con artículos que faltan, sin avisar.
     */
    public function testPagingIsStableEvenWithRepeatedNames(): void
    {
        $nombre = 'Nombre repetido de prueba';
        $this->crearArticulo($nombre, 'PRUEBA-R1');
        $this->crearArticulo($nombre, 'PRUEBA-R2');
        $this->crearArticulo($nombre, 'PRUEBA-R3');

        $total = $this->item->count_all_for_export();
        $vistos = [];

        for ($offset = 0; $offset < $total; $offset += 2) {
            foreach ($this->item->get_all_for_export(2, $offset)->getResult() as $fila) {
                $vistos[] = (int) $fila->item_id;
            }
        }

        $this->assertSame(
            count($vistos),
            count(array_unique($vistos)),
            'Paginando no puede repetirse ninguna fila.',
        );
        $this->assertSame($total, count($vistos), 'Ni puede faltar ninguna.');
    }

    public function testTheCountMatchesWhatIsExported(): void
    {
        $this->crearArticulo('Para contar', 'PRUEBA-C');

        $this->assertSame(
            $this->item->count_all_for_export(),
            count($this->item->get_all_for_export()->getResult()),
        );
    }

    // ========== Emparejar por código ==========

    public function testResolvingCodesTellsTheThreeCasesApart(): void
    {
        $uno = $this->crearArticulo('Uno solo', 'PRUEBA-UNICO');
        $this->crearArticulo('Repetido A', 'PRUEBA-DOBLE');
        $this->crearArticulo('Repetido B', 'PRUEBA-DOBLE');

        $resuelto = $this->item->resolve_item_numbers(['PRUEBA-UNICO', 'PRUEBA-DOBLE', 'PRUEBA-NO-EXISTE']);

        $this->assertCount(1, $resuelto['PRUEBA-UNICO'], 'Uno solo: se actualiza.');
        $this->assertSame($uno, (int) $resuelto['PRUEBA-UNICO'][0]->item_id);
        $this->assertCount(2, $resuelto['PRUEBA-DOBLE'], 'Varios: error de fila, no se adivina cuál.');
        $this->assertArrayNotHasKey('PRUEBA-NO-EXISTE', $resuelto, 'Ninguno: se crea.');
    }

    public function testResolvingCodesDoesNotReviveDeletedItems(): void
    {
        $this->crearArticulo('Borrado con código', 'PRUEBA-MUERTO', ITEM, 1);

        $this->assertArrayNotHasKey('PRUEBA-MUERTO', $this->item->resolve_item_numbers(['PRUEBA-MUERTO']));
    }

    public function testResolvingCodesSaysWhatKindOfItemItIs(): void
    {
        // Para poder rechazar la fila en vez de escribirle encima a un kit.
        $this->crearArticulo('Kit con código', 'PRUEBA-KIT-COD', ITEM_KIT);

        $resuelto = $this->item->resolve_item_numbers(['PRUEBA-KIT-COD']);

        $this->assertSame(ITEM_KIT, (int) $resuelto['PRUEBA-KIT-COD'][0]->item_type);
    }

    public function testResolvingNothingCostsNoQuery(): void
    {
        $antes = count($this->db->getQueries());
        $this->item->resolve_item_numbers([]);

        $this->assertSame($antes, count($this->db->getQueries()));
    }

    // ========== Y que sea UNA consulta, contada ==========

    public function testTaxesForManyItemsAreOneQuery(): void
    {
        $ids = [];

        for ($i = 0; $i < 5; $i++) {
            $id = $this->crearArticulo("Con impuesto $i", "PRUEBA-IMP-$i");
            db_connect()->table('items_taxes')->insert(['item_id' => $id, 'name' => 'IVA', 'percent' => '19']);
            $ids[] = $id;
        }

        $antes = count($this->db->getQueries());
        $leido = $this->taxes->get_info_bulk($ids);
        $consultas = count($this->db->getQueries()) - $antes;

        $this->assertSame(1, $consultas, 'Artículo por artículo serían 1.184 consultas con Paraíso.');
        $this->assertCount(5, $leido);
    }

    public function testQuantitiesForManyItemsAreOneQuery(): void
    {
        $ids = [];

        for ($i = 0; $i < 5; $i++) {
            $id = $this->crearArticulo("Con existencias $i", "PRUEBA-EXI-$i");
            db_connect()->table('item_quantities')->insert(['item_id' => $id, 'location_id' => 1, 'quantity' => 7]);
            $ids[] = $id;
        }

        $antes = count($this->db->getQueries());
        $leido = $this->quantities->get_quantities_bulk($ids);
        $consultas = count($this->db->getQueries()) - $antes;

        $this->assertSame(1, $consultas, 'Por artículo Y por bodega serían 2.368 con Paraíso y dos bodegas.');
        $this->assertSame('7.000', $leido[$ids[0]][1]);
    }

    public function testAttributesForManyItemsAreOneQuery(): void
    {
        $ids = [$this->crearArticulo('Sin atributos', 'PRUEBA-ATR-1')];

        $antes = count($this->db->getQueries());
        $this->attributes->get_attribute_values_bulk($ids);
        $consultas = count($this->db->getQueries()) - $antes;

        $this->assertSame(1, $consultas);
    }

    public function testTheBulkReadsCostNothingWithNoItems(): void
    {
        $antes = count($this->db->getQueries());

        $this->assertSame([], $this->taxes->get_info_bulk([]));
        $this->assertSame([], $this->quantities->get_quantities_bulk([]));
        $this->assertSame([], $this->attributes->get_attribute_values_bulk([]));

        $this->assertSame($antes, count($this->db->getQueries()));
    }

    /**
     * El archivo solo tiene sitio para dos impuestos, pero la lectura devuelve TODOS: quien exporta
     * necesita saber que hay un tercero para no borrarlo al reimportar.
     */
    public function testAllTaxesComeBackNotJustTheFirstTwo(): void
    {
        $id = $this->crearArticulo('Con tres impuestos', 'PRUEBA-IMP-3');

        foreach ([['IVA', '19'], ['Consumo', '8'], ['Bolsa', '1']] as [$nombre, $porcentaje]) {
            db_connect()->table('items_taxes')->insert(['item_id' => $id, 'name' => $nombre, 'percent' => $porcentaje]);
        }

        $this->assertCount(3, $this->taxes->get_info_bulk([$id])[$id]);
    }
}
