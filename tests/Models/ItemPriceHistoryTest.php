<?php

namespace Tests\Models;

use App\Database\Migrations\Migration_AddItemPriceHistory;
use App\Models\Item_price_history;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * El historial de precios de un artículo.
 *
 * Lo que hay que probar aquí no es que sepa insertar filas, sino las dos promesas que lo hacen
 * utilizable: que **nunca tumba a quien lo llamó** --vive dentro del camino de escritura de todo
 * precio, incluido el de una venta ya cobrada-- y que **una fila que no existe se distingue de un
 * precio de cero**, porque de esa distinción depende que «¿cuánto costaba en marzo?» tenga
 * respuesta honesta.
 */
class ItemPriceHistoryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const ITEM_ID = 987654;

    private Item_price_history $history;

    protected function setUp(): void
    {
        parent::setUp();

        $this->history = model(Item_price_history::class);
        $this->db->table('item_price_history')->where('item_id', self::ITEM_ID)->delete();
    }

    protected function tearDown(): void
    {
        $this->db->table('item_price_history')->where('item_id', self::ITEM_ID)->delete();

        parent::tearDown();
    }

    // ========== El enlace entre la migración y el modelo ==========

    /**
     * El fallo del que protege no levanta nada: CodeIgniter descarta en silencio un campo que falte
     * en $allowedFields, y aquí el campo que descartaría es el precio o quién lo cambió. Leer las
     * columnas de la base y no de una lista en la prueba hace que una columna añadida después sin
     * tocar el modelo falle aquí y no en producción.
     */
    public function testAllowedFieldsCoverEveryWritableColumnOfTheTable(): void
    {
        $columns  = $this->db->getFieldNames('item_price_history');
        $writable = array_values(array_diff($columns, ['price_history_id']));

        sort($writable);
        $allowed = $this->history->allowedFields;
        sort($allowed);

        $this->assertSame($writable, $allowed);
    }

    public function testMigrationAndModelAgreeOnTheWritableColumns(): void
    {
        $expected = Migration_AddItemPriceHistory::WRITABLE_COLUMNS;
        sort($expected);

        $allowed = $this->history->allowedFields;
        sort($allowed);

        $this->assertSame($expected, $allowed);
    }

    // ========== Lo que se escribe ==========

    public function testRecordingAPriceChangeKeepsWhoWhenAndWhatItWasBefore(): void
    {
        $id = $this->history->record(
            self::ITEM_ID,
            '8000.00',
            '10000.00',
            Item_price_history::SOURCE_SALE,
            42,
            777,
            'una nota'
        );

        $this->assertGreaterThan(0, $id, 'La fila se escribió.');

        $fila = $this->history->find($id);

        $this->assertSame('8000.00', $fila['previous_price']);
        $this->assertSame('10000.00', $fila['new_price']);
        $this->assertSame(Item_price_history::SOURCE_SALE, $fila['source']);
        $this->assertSame(42, (int) $fila['employee_id']);
        $this->assertSame(777, (int) $fila['sale_id']);
        $this->assertSame('una nota', $fila['note']);
    }

    /**
     * Un precio sin predecesor conocido guarda NULL, jamás un cero: «no se sabe» y «costaba cero»
     * son hechos distintos, y confundirlos es lo que convierte un historial en una mentira.
     */
    public function testAFirstPriceIsRecordedWithNoPreviousPriceAndNotWithZero(): void
    {
        $id = $this->history->record(self::ITEM_ID, null, '4500.00', Item_price_history::SOURCE_SEED);

        $this->assertNull($this->history->find($id)['previous_price']);
    }

    /**
     * Un origen que nadie declaró se guarda como 'unknown' en vez de rechazarse. Perder de dónde
     * vino un cambio de precio es una pérdida pequeña; perder el cambio porque su etiqueta venía
     * mal escrita es una grande.
     */
    public function testAnUnrecognisedSourceBecomesUnknownInsteadOfLosingTheRow(): void
    {
        $id = $this->history->record(self::ITEM_ID, null, '1000.00', 'lo-que-sea');

        $this->assertSame(Item_price_history::SOURCE_UNKNOWN, $this->history->find($id)['source']);
    }

    public function testNormalisingASourceNeverRaisesWhateverItIsHanded(): void
    {
        $this->assertSame(Item_price_history::SOURCE_SALE, Item_price_history::normalize_source('SALE'));
        $this->assertSame(Item_price_history::SOURCE_SALE, Item_price_history::normalize_source('  sale '));
        $this->assertSame(Item_price_history::SOURCE_UNKNOWN, Item_price_history::normalize_source(null));
        $this->assertSame(Item_price_history::SOURCE_UNKNOWN, Item_price_history::normalize_source(7));
        $this->assertSame(Item_price_history::SOURCE_UNKNOWN, Item_price_history::normalize_source(''));
    }

    /**
     * LA PRUEBA QUE DE VERDAD IMPORTA.
     *
     * `record()` corre dentro de `Item::save_value()`, que está en el camino de toda escritura de
     * precio -- incluida la que ocurre justo después de que una venta se cobró y se confirmó. Un
     * historial capaz de hacer fracasar aquello que solo estaba observando sería un historial que
     * conviene quitar.
     *
     * El escenario que se reproduce es el REAL, no uno inventado: en este proyecto los despliegues
     * **no ejecutan migraciones** --hay que lanzar `php spark migrate` a mano por SSH-- así que en
     * cada versión existe una ventana con el código vivo y la tabla todavía ausente. Sin esta
     * guarda, el primer negocio cuya migración se olvide **no puede cerrar una venta**.
     */
    public function testAMissingTableIsSurvivedInsteadOfBreakingTheSaleThatWasBeingObserved(): void
    {
        $sinTabla = new class extends Item_price_history {
            protected $table = 'item_price_history_que_todavia_no_existe';
        };

        $this->assertSame(0, $sinTabla->record(self::ITEM_ID, null, '1000.00', 'sale'), 'Devuelve 0; jamás lanza.');
        $this->assertSame([], $sinTabla->get_history(self::ITEM_ID));
        $this->assertNull($sinTabla->get_price_at(self::ITEM_ID, '2026-01-01 00:00:00'));
        $this->assertSame([], $sinTabla->get_changes_for_sale(1));
    }

    // ========== Lo que se lee ==========

    public function testTheHistoryComesBackNewestFirst(): void
    {
        $this->history->record(self::ITEM_ID, null, '1000.00', Item_price_history::SOURCE_SEED);
        $this->history->record(self::ITEM_ID, '1000.00', '2000.00', Item_price_history::SOURCE_ITEM_FORM);
        $this->history->record(self::ITEM_ID, '2000.00', '3000.00', Item_price_history::SOURCE_SALE);

        $precios = array_column($this->history->get_history(self::ITEM_ID), 'new_price');

        $this->assertSame(['3000.00', '2000.00', '1000.00'], $precios);
    }

    /**
     * Las tres filas de arriba caen dentro del MISMO segundo --`changed_at` tiene resolución de
     * segundo y repreciar dos líneas de una venta ocurre de golpe--, así que el orden solo sale bien
     * si se desempata por el id. Sin ese desempate esta prueba pasa unas veces y otras no.
     */
    public function testTwoChangesInsideTheSameSecondStillComeBackInOrder(): void
    {
        $primero = $this->history->record(self::ITEM_ID, null, '1000.00', Item_price_history::SOURCE_SALE);
        $segundo = $this->history->record(self::ITEM_ID, '1000.00', '2000.00', Item_price_history::SOURCE_SALE);

        $filas = $this->history->get_history(self::ITEM_ID);

        $this->assertSame($segundo, (int) $filas[0]['price_history_id']);
        $this->assertSame($primero, (int) $filas[1]['price_history_id']);
    }

    public function testThePriceAtAGivenMomentIsTheOneInEffectThen(): void
    {
        $this->db->table('item_price_history')->insertBatch([
            ['item_id' => self::ITEM_ID, 'previous_price' => null, 'new_price' => '1000.00', 'changed_at' => '2026-01-01 10:00:00', 'source' => 'seed'],
            ['item_id' => self::ITEM_ID, 'previous_price' => '1000.00', 'new_price' => '2000.00', 'changed_at' => '2026-03-15 10:00:00', 'source' => 'sale'],
        ]);

        $this->assertSame('1000.00', $this->history->get_price_at(self::ITEM_ID, '2026-02-01 00:00:00'));
        $this->assertSame('2000.00', $this->history->get_price_at(self::ITEM_ID, '2026-04-01 00:00:00'));
    }

    /**
     * Antes del primer registro la respuesta es «no sé», y eso NO es cero. Es la distinción que la
     * fila de siembra existe para preservar.
     */
    public function testThePriceBeforeTheHistoryBeginsIsUnknownAndNotZero(): void
    {
        $this->history->record(self::ITEM_ID, null, '1000.00', Item_price_history::SOURCE_SEED);

        $this->assertNull($this->history->get_price_at(self::ITEM_ID, '2020-01-01 00:00:00'));
    }

    public function testTheChangesOfOneSaleComeBackTogether(): void
    {
        $this->history->record(self::ITEM_ID, '0.00', '4500.00', Item_price_history::SOURCE_SALE, 1, 555);
        $this->history->record(self::ITEM_ID, '4500.00', '5000.00', Item_price_history::SOURCE_ITEM_FORM, 1, null);

        $delaVenta = $this->history->get_changes_for_sale(555);

        $this->assertCount(1, $delaVenta);
        $this->assertSame('4500.00', $delaVenta[0]['new_price']);
    }
}
