<?php

namespace Tests\Models;

use App\Models\Item;
use App\Models\Item_price_history;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Que todo cambio de precio deje rastro, venga por donde venga.
 *
 * Lo que se prueba aquí no es que sepa insertar una fila --eso ya está en ItemPriceHistoryTest--
 * sino las tres reglas que hacen que el historial sea legible en vez de ruidoso: **una fila por
 * cambio real**, ninguna cuando el precio no se movió, y ninguna cuando el guardado ni siquiera
 * traía precio. Sin ellas, corregir la descripción de un artículo ensuciaría su historial de
 * precios y nadie volvería a mirarlo.
 *
 * Y cubre las DOS puertas de escritura. `update_multiple()` no pasa por `save_value()`: escribe con
 * un solo UPDATE sobre muchas filas, y casi se queda sin capturar.
 */
class ItemPriceCaptureTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const PREFIX = 'CAPTURA-PRECIO-';

    private Item $item;
    private Item_price_history $history;

    protected function setUp(): void
    {
        parent::setUp();

        $this->item    = model(Item::class);
        $this->history = model(Item_price_history::class);

        Item::clear_price_change_context();
        $this->deleteFixtures();
    }

    protected function tearDown(): void
    {
        // El contexto es estático: dejarlo puesto contaminaría las pruebas de OTROS archivos, y el
        // fallo saldría donde no está la causa. La base de pruebas es compartida.
        Item::clear_price_change_context();
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

    private function crearArticulo(string $sufijo, string $precio): int
    {
        $datos = [
            'name'                  => self::PREFIX . $sufijo,
            'category'              => 'Test',
            'item_number'           => self::PREFIX . $sufijo,
            'cost_price'            => '0.00',
            'unit_price'            => $precio,
            'reorder_level'         => '0',
            'receiving_quantity'    => '1',
            'allow_alt_description' => 0,
            'is_serialized'         => 0,
        ];

        $this->item->save_value($datos, NEW_ENTRY);

        return (int) $datos['item_id'];
    }

    private function filas(int $itemId): array
    {
        return $this->db->table('item_price_history')
            ->where('item_id', $itemId)
            ->orderBy('price_history_id', 'ASC')
            ->get()
            ->getResultArray();
    }

    // ========== Una fila por cambio real, y ninguna de más ==========

    public function testCreatingAnItemRecordsItsFirstPriceWithNoPredecessor(): void
    {
        $itemId = $this->crearArticulo('NUEVO', '4500.00');

        $filas = $this->filas($itemId);

        $this->assertCount(1, $filas);
        $this->assertNull($filas[0]['previous_price'], 'Un artículo nuevo no tiene precio anterior.');
        $this->assertSame('4500.00', $filas[0]['new_price']);
    }

    public function testChangingThePriceRecordsWhatItWasAndWhatItBecame(): void
    {
        $itemId = $this->crearArticulo('CAMBIA', '4500.00');

        $datos = ['unit_price' => '5200.00'];
        $this->item->save_value($datos, $itemId);

        $filas = $this->filas($itemId);

        $this->assertCount(2, $filas, 'La creación y el cambio: dos filas, no una ni tres.');
        $this->assertSame('4500.00', $filas[1]['previous_price']);
        $this->assertSame('5200.00', $filas[1]['new_price']);
    }

    /**
     * `Items::postSave()` reescribe la fila ENTERA en cada guardado, así que sin esta regla
     * corregir una descripción dejaría una fila de precio idéntica a la anterior. Un historial que
     * crece sin que nada cambie es un historial que nadie lee.
     */
    public function testSavingTheSamePriceAgainRecordsNothing(): void
    {
        $itemId = $this->crearArticulo('IGUAL', '4500.00');

        $datos = ['unit_price' => '4500.00', 'description' => 'otra cosa'];
        $this->item->save_value($datos, $itemId);

        $this->assertCount(1, $this->filas($itemId), 'Solo queda la fila de creación.');
    }

    /**
     * Un guardado que no trae precio no es un cambio de precio. Es lo que deja gratis a
     * `change_cost_price()` --el costo promedio de recepciones-- y a quitar el logo de un artículo.
     */
    public function testSavingWithoutTouchingThePriceRecordsNothing(): void
    {
        $itemId = $this->crearArticulo('SIN-PRECIO', '4500.00');

        $datos = ['description' => 'solo la descripción'];
        $this->item->save_value($datos, $itemId);

        $this->assertCount(1, $this->filas($itemId));
    }

    // ========== La segunda puerta: la edición masiva ==========

    /**
     * `update_multiple()` NO pasa por `save_value()`: escribe con un solo UPDATE sobre muchas filas.
     * Sin captura propia, cambiar 40 precios de un clic --lo que un negocio hace cuando le suben los
     * costos-- no dejaría rastro de ninguno.
     */
    public function testTheBulkEditRecordsOneRowPerItem(): void
    {
        $uno = $this->crearArticulo('MASIVA-1', '1000.00');
        $dos = $this->crearArticulo('MASIVA-2', '2000.00');

        $this->item->update_multiple(['unit_price' => '3000.00'], $uno . ':' . $dos);

        $delUno = $this->filas($uno);
        $delDos = $this->filas($dos);

        $this->assertCount(2, $delUno);
        $this->assertSame('1000.00', $delUno[1]['previous_price'], 'Cada artículo conserva SU precio anterior, no el del vecino.');
        $this->assertSame('3000.00', $delUno[1]['new_price']);

        $this->assertCount(2, $delDos);
        $this->assertSame('2000.00', $delDos[1]['previous_price']);
    }

    public function testTheBulkEditRecordsNothingWhenNoPriceIsInvolved(): void
    {
        $itemId = $this->crearArticulo('MASIVA-SIN-PRECIO', '1000.00');

        $this->item->update_multiple(['category' => 'Otra'], (string) $itemId);

        $this->assertCount(1, $this->filas($itemId));
    }

    // ========== El origen y el autor ==========

    public function testTheDeclaredOriginAndEmployeeReachTheRow(): void
    {
        Item::with_price_change_context(Item_price_history::SOURCE_ITEM_FORM, 7);

        $itemId = $this->crearArticulo('CON-CONTEXTO', '1500.00');
        $fila   = $this->filas($itemId)[0];

        $this->assertSame(Item_price_history::SOURCE_ITEM_FORM, $fila['source']);
        $this->assertSame(7, (int) $fila['employee_id']);
    }

    /**
     * Sin contexto declarado la fila se escribe igual, con origen 'unknown'. Perder de dónde vino un
     * cambio es una pérdida pequeña; perder el cambio porque nadie declaró el origen sería una
     * grande -- y es justo lo que pasaría si esto fuera un parámetro obligatorio que alguien olvida.
     */
    public function testAPriceChangeWithNoDeclaredOriginIsStillRecorded(): void
    {
        $itemId = $this->crearArticulo('SIN-CONTEXTO', '900.00');
        $fila   = $this->filas($itemId)[0];

        $this->assertSame(Item_price_history::SOURCE_UNKNOWN, $fila['source']);
    }

    /**
     * LA PRUEBA QUE PROTEGE LA CAJA.
     *
     * La captura corre dentro del camino de guardado de todo artículo. Si el historial no se puede
     * escribir, el artículo TIENE que guardarse igual: observar no puede tumbar lo observado.
     */
    public function testTheItemIsStillSavedWhenTheHistoryCannotBeWritten(): void
    {
        $itemId = $this->crearArticulo('PESE-A-TODO', '1000.00');

        $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable('item_price_history') . '_apartada');
        $this->db->query('RENAME TABLE ' . $this->db->prefixTable('item_price_history') . ' TO ' . $this->db->prefixTable('item_price_history') . '_apartada');

        try {
            $datos = ['unit_price' => '2000.00'];
            $guardado = $this->item->save_value($datos, $itemId);
        } finally {
            $this->db->query('RENAME TABLE ' . $this->db->prefixTable('item_price_history') . '_apartada TO ' . $this->db->prefixTable('item_price_history'));
        }

        $this->assertTrue($guardado, 'El artículo se guarda aunque el historial falle.');

        $precio = $this->db->table('items')->select('unit_price')->where('item_id', $itemId)->get()->getRow();
        $this->assertSame('2000.00', (string) $precio->unit_price, 'Y el precio nuevo quedó escrito.');
    }
}
