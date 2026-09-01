<?php

declare(strict_types=1);

namespace Tests\Libraries;

use App\Libraries\Item_import_lib;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\OSPOS;

/**
 * Subir el archivo corregido: qué se enseña, y qué se escribe.
 *
 * LO QUE ESTAS PRUEBAS FIJAN
 *
 * La promesa entera de esta pantalla cabe en una frase: **lo que se aplica es exactamente lo que se
 * enseñó**. De ahí las dos primeras pruebas --que la vista previa no escribe ni una fila, y que los
 * números del plan son los que ocurren al aplicar--; sin ellas, «vista previa» es una intención.
 *
 * Y de ahí también la forma del resto: cada una nace de una manera concreta de perder datos de un
 * cliente que ya está en producción. `1.000` guardado como `1`. Un EAN destruido por Excel y guardado
 * literal. Un archivo con solo la columna de precios que borra 1.184 nombres. Un artículo con tres
 * impuestos que se queda con dos. Ninguna es hipotética: todas están escritas en
 * `docs/Tecnico/carga-masiva-de-articulos.md`.
 *
 * CUIDADO AL AÑADIR PRUEBAS AQUÍ
 *
 * La suite comparte UNA base y no la refresca entre métodos (§3.7 del técnico). Todo lo que se siembre
 * --y todo lo que `apply()` cree, que no se sabe de antemano-- se borra en `tearDown()` buscando por
 * el prefijo. Dejar restos rompe pruebas de OTROS archivos, y de la forma más difícil de ver: cada una
 * pasa aislada y fallan en conjunto.
 *
 * @internal
 */
final class ItemImportLibTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    /** Todo lo que esta clase siembra o crea lo lleva, para poder barrerlo sin tocar nada ajeno. */
    private const PREFIJO = 'PRUEBACM-';

    private Item_import_lib $importador;

    /** @var list<string> */
    private array $archivos = [];

    protected function setUp(): void
    {
        parent::setUp();

        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();

        helper('importfile');

        $this->importador = new Item_import_lib();
    }

    protected function tearDown(): void
    {
        $this->limpiarArticulos();

        foreach ($this->archivos as $archivo) {
            @unlink($archivo);
        }

        $this->archivos = [];

        parent::tearDown();
    }

    /**
     * Borra por prefijo y no por lista de identificadores: `apply()` crea artículos cuyo `item_id` esta
     * prueba no conoce hasta después, y son justo los que no se pueden dejar atrás.
     */
    private function limpiarArticulos(): void
    {
        $db = db_connect();

        $filas = $db->table('items')
            ->select('item_id')
            ->groupStart()
            ->like('name', self::PREFIJO, 'after')
            ->orLike('item_number', self::PREFIJO, 'after')
            ->groupEnd()
            ->get()
            ->getResultArray();

        if ($filas === []) {
            return;
        }

        $ids = array_map(static fn (array $fila): int => (int) $fila['item_id'], $filas);

        $db->table('items_taxes')->whereIn('item_id', $ids)->delete();
        $db->table('item_quantities')->whereIn('item_id', $ids)->delete();
        $db->table('inventory')->whereIn('trans_items', $ids)->delete();
        $db->table('items')->whereIn('item_id', $ids)->delete();
    }

    // ================================================================================================
    // Cimientos de las pruebas
    // ================================================================================================

    /**
     * `$extra` va a la IZQUIERDA del `+` a propósito: con arreglos, ese operador conserva el valor del
     * operando de la izquierda, así que al revés lo que se pasa por parámetro no ganaría nunca y las
     * pruebas del kit y del precio inicial estarían comprobando otra cosa sin decirlo.
     */
    private function crearArticulo(string $nombre, string $codigo, array $extra = []): int
    {
        db_connect()->table('items')->insert($extra + [
            'name'        => self::PREFIJO . $nombre,
            'item_number' => $codigo,
            'category'    => 'Pruebas',
            'description' => '',
            'cost_price'  => 0,
            'unit_price'  => 0,
            'item_type'   => ITEM,
            'deleted'     => 0,
        ]);

        return (int) db_connect()->insertID();
    }

    private function articulo(int $id): array
    {
        return (array) db_connect()->table('items')->where('item_id', $id)->get()->getRowArray();
    }

    /**
     * `tempnam()` ya crea el archivo, así que añadirle `.csv` deja DOS: el que se usa y uno vacío que
     * nadie borra. Se apuntan los dos para que la máquina de CI no se llene de basura con el tiempo.
     */
    private function rutaTemporal(): string
    {
        $base = (string) tempnam(sys_get_temp_dir(), 'cargamasiva');
        $ruta = $base . '.csv';

        $this->archivos[] = $base;
        $this->archivos[] = $ruta;

        return $ruta;
    }

    /**
     * Escribe un CSV con la cabecera COMPLETA y las celdas que se le pasen; el resto van vacías.
     *
     * La cabecera sale de `import_items_csv_fixed_columns()` y no de una cadena escrita aquí: una
     * prueba que se inventa sus propias columnas deja de comprobar que el archivo real encaja.
     *
     * @param list<array<string, string>> $filas
     */
    private function archivo(array $filas): string
    {
        $columnas = import_items_csv_fixed_columns();
        $ruta     = $this->rutaTemporal();

        $csv = fopen($ruta, 'w');
        fputcsv($csv, $columnas);

        foreach ($filas as $fila) {
            fputcsv($csv, array_map(static fn (string $columna): string => $fila[$columna] ?? '', $columnas));
        }

        fclose($csv);

        return $ruta;
    }

    /** Las cuatro tablas que `apply()` puede tocar. Si la vista previa escribe, se nota aquí. */
    private function conteos(): array
    {
        $db = db_connect();

        return [
            'items'           => $db->table('items')->countAllResults(),
            'items_taxes'     => $db->table('items_taxes')->countAllResults(),
            'item_quantities' => $db->table('item_quantities')->countAllResults(),
            'inventory'       => $db->table('inventory')->countAllResults(),
        ];
    }

    /** @return list<string> */
    private function mensajes(array $plan, string $clave = 'errors'): array
    {
        return array_map(static fn (array $fila): string => $fila['message'], $plan[$clave]);
    }

    // ================================================================================================
    // La promesa: se enseña primero, se escribe después
    // ================================================================================================

    /**
     * La prueba que define la pantalla. Con una fila mala en el archivo --y con una buena al lado, para
     * que no se pueda pasar por no haber hecho nada-- `plan()` no puede haber tocado ni una de las
     * cuatro tablas que `apply()` escribe.
     */
    public function testThePreviewWritesAbsolutelyNothing(): void
    {
        $ruta = $this->archivo([
            ['Item Name' => self::PREFIJO . 'Bueno', 'Category' => 'Pruebas', 'Unit Price' => '1500', 'Barcode' => self::PREFIJO . 'OK'],
            ['Item Name' => self::PREFIJO . 'Sin categoria', 'Unit Price' => '900', 'Barcode' => self::PREFIJO . 'MALO'],
        ]);

        $antes = $this->conteos();
        $plan  = $this->importador->plan($ruta);

        $this->assertSame($antes, $this->conteos(), 'La vista previa no puede escribir NADA.');
        $this->assertCount(1, $plan['to_create']);
        $this->assertCount(1, $plan['errors']);
        $this->assertSame(3, $plan['errors'][0]['line'], 'El número de fila es el del archivo que el cliente tiene abierto.');
    }

    /**
     * Los conteos del plan son los que de verdad ocurren. Si estos dos números pudieran separarse, la
     * pantalla estaría prometiendo algo que no controla.
     */
    public function testThePlanCountsAreWhatApplyingDoes(): void
    {
        $existente = $this->crearArticulo('Existente', self::PREFIJO . 'VIEJO');

        $ruta = $this->archivo([
            ['Barcode' => self::PREFIJO . 'VIEJO', 'Unit Price' => '2500'],
            ['Item Name' => self::PREFIJO . 'Nuevo', 'Category' => 'Pruebas', 'Unit Price' => '3000', 'Barcode' => self::PREFIJO . 'NUEVO'],
            ['Item Name' => self::PREFIJO . 'Sin precio', 'Category' => 'Pruebas'],
        ]);

        $plan = $this->importador->plan($ruta);

        $this->assertCount(1, $plan['to_create']);
        $this->assertCount(1, $plan['to_update']);
        $this->assertCount(1, $plan['errors']);

        $resultado = $this->importador->apply($plan);

        $this->assertSame(1, $resultado['created']);
        $this->assertSame(1, $resultado['updated']);
        $this->assertSame([], $resultado['failed']);
        $this->assertSame(2500.0, (float) $this->articulo($existente)['unit_price']);
    }

    // ================================================================================================
    // El emparejamiento
    // ================================================================================================

    public function testAKnownCodeUpdatesAndDoesNotCreate(): void
    {
        $id = $this->crearArticulo('Conocido', self::PREFIJO . 'C1');

        $antes = $this->conteos()['items'];
        $plan  = $this->importador->plan($this->archivo([
            ['Barcode' => self::PREFIJO . 'C1', 'Unit Price' => '4200'],
        ]));

        $this->assertCount(1, $plan['to_update']);
        $this->assertCount(0, $plan['to_create']);

        $this->importador->apply($plan);

        $this->assertSame($antes, $this->conteos()['items'], 'Actualizar no puede crear un segundo artículo.');
        $this->assertSame(4200.0, (float) $this->articulo($id)['unit_price']);
    }

    public function testAnUnknownCodeCreates(): void
    {
        $plan = $this->importador->plan($this->archivo([
            ['Item Name' => self::PREFIJO . 'Recien llegado', 'Category' => 'Pruebas', 'Unit Price' => '700', 'Barcode' => self::PREFIJO . 'C2'],
        ]));

        $this->assertCount(1, $plan['to_create']);
        $this->assertSame(['created' => 1, 'updated' => 0, 'failed' => []], $this->importador->apply($plan));

        $creado = db_connect()->table('items')->where('item_number', self::PREFIJO . 'C2')->get()->getRowArray();

        $this->assertNotNull($creado);
        $this->assertSame(700.0, (float) $creado['unit_price']);
    }

    /**
     * D6: `items.item_number` no tiene restricción de unicidad, así que el caso existe. Nunca se
     * adivina cuál de los dos, porque actualizar el artículo equivocado tarda meses en notarse.
     */
    public function testACodeInTwoLiveItemsIsARowErrorAndTouchesNeither(): void
    {
        $uno = $this->crearArticulo('Doble A', self::PREFIJO . 'DOBLE');
        $dos = $this->crearArticulo('Doble B', self::PREFIJO . 'DOBLE');

        $plan = $this->importador->plan($this->archivo([
            ['Barcode' => self::PREFIJO . 'DOBLE', 'Unit Price' => '9999'],
        ]));

        $this->assertCount(0, $plan['to_update']);
        $this->assertCount(0, $plan['to_create']);
        $this->assertCount(1, $plan['errors']);

        $this->importador->apply($plan);

        $this->assertSame(0.0, (float) $this->articulo($uno)['unit_price']);
        $this->assertSame(0.0, (float) $this->articulo($dos)['unit_price']);
    }

    /**
     * El `Id` manda sobre el código, que es la regla OPUESTA a la de la búsqueda del punto de venta.
     * Allí se resuelve lo que teclea un cajero; aquí, un archivo que salió de nosotros.
     */
    public function testTheIdWinsOverTheCode(): void
    {
        $porId     = $this->crearArticulo('Manda el Id', self::PREFIJO . 'AAA');
        $porCodigo = $this->crearArticulo('No se toca', self::PREFIJO . 'BBB');

        $plan = $this->importador->plan($this->archivo([
            ['Id' => (string) $porId, 'Barcode' => self::PREFIJO . 'BBB', 'Unit Price' => '5555'],
        ]));

        $this->assertCount(1, $plan['to_update']);
        $this->assertSame($porId, $plan['to_update'][0]['item_id']);

        $this->importador->apply($plan);

        $this->assertSame(5555.0, (float) $this->articulo($porId)['unit_price']);
        $this->assertSame(0.0, (float) $this->articulo($porCodigo)['unit_price'], 'El artículo del código no se toca.');
    }

    public function testAnUnknownIdIsARowError(): void
    {
        $plan = $this->importador->plan($this->archivo([
            ['Id' => '999999999', 'Unit Price' => '10'],
        ]));

        $this->assertCount(1, $plan['errors']);
        $this->assertCount(0, $plan['to_create'], 'Un Id que no existe no se convierte en un artículo nuevo.');
    }

    /**
     * Las dos filas pasarían la validación por separado --ninguna existe todavía-- y crearían un
     * duplicado. Se marcan LAS DOS: elegir una ganadora sería adivinar cuál escribió mal el cliente.
     */
    public function testTwoRowsWithTheSameNewCodeAreARowError(): void
    {
        $antes = $this->conteos()['items'];

        $plan = $this->importador->plan($this->archivo([
            ['Item Name' => self::PREFIJO . 'Gemelo A', 'Category' => 'Pruebas', 'Unit Price' => '10', 'Barcode' => self::PREFIJO . 'GEMELO'],
            ['Item Name' => self::PREFIJO . 'Gemelo B', 'Category' => 'Pruebas', 'Unit Price' => '20', 'Barcode' => self::PREFIJO . 'GEMELO'],
        ]));

        $this->assertCount(0, $plan['to_create']);
        $this->assertCount(2, $plan['errors']);

        $this->importador->apply($plan);

        $this->assertSame($antes, $this->conteos()['items']);
    }

    /**
     * El archivo puede no haber salido de nosotros, y los temporales heredan el código que tecleó el
     * cajero: escribirles encima cambiaría un kit o resucitaría una venta suelta.
     */
    public function testARowMatchingAKitOrATemporaryIsARowError(): void
    {
        $this->crearArticulo('Kit', self::PREFIJO . 'KIT', ['item_type' => ITEM_KIT]);
        $this->crearArticulo('Temporal', self::PREFIJO . 'TEMP', ['item_type' => ITEM_TEMP]);

        $plan = $this->importador->plan($this->archivo([
            ['Barcode' => self::PREFIJO . 'KIT', 'Unit Price' => '10'],
            ['Barcode' => self::PREFIJO . 'TEMP', 'Unit Price' => '20'],
        ]));

        $this->assertCount(2, $plan['errors']);
        $this->assertCount(0, $plan['to_update']);
        $this->assertCount(0, $plan['to_create'], 'Tampoco se crea uno nuevo con ese código.');
    }

    // ================================================================================================
    // Celda vacía = no cambiar (D4)
    // ================================================================================================

    /**
     * El criterio de aceptación del funcional §9: la lista que llega de un proveedor trae precios y
     * nada más. Si «vacío» significara «poner en blanco», esto borraría 1.184 nombres.
     */
    public function testAFileWithOnlyPricesDoesNotEraseNames(): void
    {
        $id       = $this->crearArticulo('Nombre que sobrevive', self::PREFIJO . 'D4');
        $original = $this->articulo($id);

        $this->importador->apply($this->importador->plan($this->archivo([
            ['Barcode' => self::PREFIJO . 'D4', 'Unit Price' => '1200'],
        ])));

        $despues = $this->articulo($id);

        $this->assertSame($original['name'], $despues['name']);
        $this->assertSame($original['category'], $despues['category']);
        $this->assertSame(1200.0, (float) $despues['unit_price']);
    }

    /**
     * `array_filter(..., strlen($v))` es lo que hoy deja pasar el cero, y cualquier reescritura de ese
     * filtro lo perdería. Aquí no hay filtro: la clave está o no está, y un cero está.
     */
    public function testAPriceOfZeroIsStoredAsZeroAndNotDiscarded(): void
    {
        $id = $this->crearArticulo('Se pone en cero', self::PREFIJO . 'CERO', ['unit_price' => 8000]);

        $this->importador->apply($this->importador->plan($this->archivo([
            ['Barcode' => self::PREFIJO . 'CERO', 'Unit Price' => '0'],
        ])));

        $this->assertSame(0.0, (float) $this->articulo($id)['unit_price'], 'Un precio de 0 es un valor, no una celda vacía.');
    }

    /**
     * El flujo viejo hace `$row['Allow Alt Description'] === '' ? '0' : '1'` en las altas: un 0 escrito
     * a mano se guardaba como 1. Y la columna es `NOT NULL` sin DEFAULT, así que omitirla solo funciona
     * mientras `strictOn` siga en false.
     */
    public function testAllowAltDescriptionZeroIsStoredAsZeroOnInsert(): void
    {
        $this->importador->apply($this->importador->plan($this->archivo([
            [
                'Item Name'             => self::PREFIJO . 'Sin descripcion alterna',
                'Category'              => 'Pruebas',
                'Unit Price'            => '100',
                'Barcode'               => self::PREFIJO . 'ALT0',
                'Allow Alt Description' => '0',
            ],
        ])));

        $creado = db_connect()->table('items')->where('item_number', self::PREFIJO . 'ALT0')->get()->getRowArray();

        $this->assertSame(0, (int) $creado['allow_alt_description']);
        $this->assertSame(0, (int) $creado['is_serialized'], 'Lo que no se contesta en un alta se escribe explícito, no lo rellena el motor.');
        $this->assertSame('', $creado['description']);
    }

    // ================================================================================================
    // Los números y los códigos
    // ================================================================================================

    /**
     * Con `number_locale = es_CO` el punto es separador de MILES, así que `1.000` puede ser mil o puede
     * ser uno. Hoy `parse_decimals()` guarda uno, en silencio. Se rechaza en vez de elegir: elegir es
     * apostarse el precio del artículo.
     */
    public function testAnAmbiguousThousandIsRejectedInsteadOfRead(): void
    {
        config(OSPOS::class)->settings['number_locale'] = 'es_CO';

        $id = $this->crearArticulo('Precio ambiguo', self::PREFIJO . 'MIL', ['unit_price' => 7]);

        $plan = $this->importador->plan($this->archivo([
            ['Barcode' => self::PREFIJO . 'MIL', 'Unit Price' => '1.000'],
        ]));

        $this->assertCount(1, $plan['errors']);
        $this->assertCount(0, $plan['to_update'], 'Una fila con un número ambiguo no se aplica a medias.');

        $this->importador->apply($plan);

        $this->assertSame(7.0, (float) $this->articulo($id)['unit_price'], 'Y desde luego no se guarda como 1.');
    }

    public function testPlainDigitsAreReadAsTheyAreWritten(): void
    {
        config(OSPOS::class)->settings['number_locale'] = 'es_CO';

        $id = $this->crearArticulo('Precio claro', self::PREFIJO . 'CLARO');

        $this->importador->apply($this->importador->plan($this->archivo([
            ['Barcode' => self::PREFIJO . 'CLARO', 'Unit Price' => '1000'],
        ])));

        $this->assertSame(1000.0, (float) $this->articulo($id)['unit_price']);
    }

    /**
     * `7,70203E+12` es un EAN que Excel ya destruyó: perdió los dígitos y no hay de dónde sacarlos.
     * Guardarlo dejaría un artículo que no se puede encontrar ni con el lector ni tecleando.
     */
    public function testACodeInScientificNotationIsARowError(): void
    {
        $antes = $this->conteos()['items'];

        $plan = $this->importador->plan($this->archivo([
            ['Item Name' => self::PREFIJO . 'Codigo roto', 'Category' => 'Pruebas', 'Unit Price' => '100', 'Barcode' => '7,70203E+12'],
        ]));

        $this->assertCount(1, $plan['errors']);
        $this->assertCount(0, $plan['to_create']);

        $this->importador->apply($plan);

        $this->assertSame($antes, $this->conteos()['items']);
    }

    /**
     * La otra mitad de lo anterior: un EAN envuelto por la exportación (`="7702028000316"`) tiene que
     * volver entero. Si esto fallara, el viaje de ida y vuelta no existiría.
     */
    public function testAWrappedCodeComesBackWhole(): void
    {
        $ean = '7702028000' . random_int(100, 999);

        $this->importador->apply($this->importador->plan($this->archivo([
            [
                'Item Name'  => self::PREFIJO . 'Con EAN',
                'Category'   => 'Pruebas',
                'Unit Price' => '100',
                'Barcode'    => csv_text_cell($ean),
            ],
        ])));

        $creado = db_connect()->table('items')->where('item_number', $ean)->get()->getRowArray();

        // No hace falta borrarlo a mano: su NOMBRE lleva el prefijo, y `limpiarArticulos()` busca por
        // nombre además de por código precisamente para los casos como este, donde el código es el dato
        // que se está comprobando y no puede llevar marca ninguna.
        $this->assertNotNull($creado, "El EAN $ean tiene que guardarse limpio, sin el envoltorio.");
    }

    // ================================================================================================
    // El tercer impuesto
    // ================================================================================================

    /**
     * `Item_taxes::save_value()` borra y reinserta, y el archivo solo tiene sitio para dos impuestos:
     * reimportar el archivo que nosotros mismos generamos dejaría al artículo con dos. La regla es no
     * tocarle los impuestos a un artículo que tiene más de dos.
     */
    public function testAnItemWithThreeTaxesKeepsAllThreeWhenItsPriceChanges(): void
    {
        $id = $this->crearArticulo('Tres impuestos', self::PREFIJO . 'IMP3');

        foreach ([['IVA', '19'], ['Consumo', '8'], ['Bolsa', '1']] as [$nombre, $porcentaje]) {
            db_connect()->table('items_taxes')->insert(['item_id' => $id, 'name' => $nombre, 'percent' => $porcentaje]);
        }

        // Lo que la exportación puede escribir: los dos primeros, con el formato del motor (`1.000`),
        // que es justo el que la regla estricta de los precios rechazaría.
        $plan = $this->importador->plan($this->archivo([
            [
                'Barcode'       => self::PREFIJO . 'IMP3',
                'Unit Price'    => '15000',
                'Tax 1 Name'    => 'Bolsa',
                'Tax 1 Percent' => '1.000',
                'Tax 2 Name'    => 'Consumo',
                'Tax 2 Percent' => '8.000',
            ],
        ]));

        $this->assertCount(0, $plan['errors'], 'Un porcentaje no se agrupa nunca, así que `1.000` es uno.');

        $this->importador->apply($plan);

        $this->assertSame(15000.0, (float) $this->articulo($id)['unit_price']);
        $this->assertSame(3, db_connect()->table('items_taxes')->where('item_id', $id)->countAllResults());
    }

    /**
     * Y cuando el archivo SÍ quería cambiarlos, tampoco se tocan, pero se dice: un aviso no impide
     * aplicar, pero callarlo sería aplicar algo distinto de lo que se enseñó.
     */
    public function testChangingTheTaxesOfAThreeTaxItemWarnsAndChangesNothing(): void
    {
        $id = $this->crearArticulo('Tres con cambio', self::PREFIJO . 'IMP3B');

        foreach ([['IVA', '19'], ['Consumo', '8'], ['Bolsa', '1']] as [$nombre, $porcentaje]) {
            db_connect()->table('items_taxes')->insert(['item_id' => $id, 'name' => $nombre, 'percent' => $porcentaje]);
        }

        $plan = $this->importador->plan($this->archivo([
            [
                'Barcode'       => self::PREFIJO . 'IMP3B',
                'Unit Price'    => '2000',
                'Tax 1 Name'    => 'IVA',
                'Tax 1 Percent' => '5',
                'Tax 2 Name'    => '',
            ],
        ]));

        $this->assertCount(1, $plan['warnings']);
        $this->assertNull($plan['to_update'][0]['taxes']);

        $this->importador->apply($plan);

        $this->assertSame(3, db_connect()->table('items_taxes')->where('item_id', $id)->countAllResults());
    }

    /**
     * Y el caso normal: dos impuestos o menos sí se escriben, pero **solo si cambian**. Ese «solo si
     * cambian» es el 99% de las filas de un viaje de ida y vuelta.
     */
    public function testTaxesThatAlreadyMatchAreNotRewritten(): void
    {
        $id = $this->crearArticulo('Un impuesto', self::PREFIJO . 'IMP1');

        db_connect()->table('items_taxes')->insert(['item_id' => $id, 'name' => 'IVA', 'percent' => '19']);

        $plan = $this->importador->plan($this->archivo([
            ['Barcode' => self::PREFIJO . 'IMP1', 'Unit Price' => '300', 'Tax 1 Name' => 'IVA', 'Tax 1 Percent' => '19.000'],
        ]));

        $this->assertNull($plan['to_update'][0]['taxes'], 'Ni el borrado se escribe cuando el par es el mismo.');

        $plan = $this->importador->plan($this->archivo([
            ['Barcode' => self::PREFIJO . 'IMP1', 'Unit Price' => '300', 'Tax 1 Name' => 'IVA', 'Tax 1 Percent' => '5'],
        ]));

        $this->assertNotNull($plan['to_update'][0]['taxes'], 'Y sí se escribe cuando cambia.');

        $this->importador->apply($plan);

        $impuesto = db_connect()->table('items_taxes')->where('item_id', $id)->get()->getRowArray();

        $this->assertSame(5.0, (float) $impuesto['percent']);
    }

    /**
     * Sin esto, un archivo con solo la columna de precios dejaría a 1.184 artículos sin IVA -- que es
     * la misma regla de la celda vacía, aplicada a un sitio donde es fácil olvidarla.
     */
    public function testAFileWithNoTaxColumnsFilledLeavesTheTaxesAlone(): void
    {
        $id = $this->crearArticulo('Conserva su IVA', self::PREFIJO . 'IVA');

        db_connect()->table('items_taxes')->insert(['item_id' => $id, 'name' => 'IVA', 'percent' => '19']);

        $this->importador->apply($this->importador->plan($this->archivo([
            ['Barcode' => self::PREFIJO . 'IVA', 'Unit Price' => '400'],
        ])));

        $this->assertSame(1, db_connect()->table('items_taxes')->where('item_id', $id)->countAllResults());
    }

    // ================================================================================================
    // El archivo entero
    // ================================================================================================

    /**
     * Un archivo que no es de artículos no puede leerse fila a fila y contestar mil errores iguales:
     * se dice una vez, y se dice qué hacer.
     */
    public function testAFileWithoutTheExpectedHeadersIsRejectedAsAWhole(): void
    {
        $ruta = $this->rutaTemporal();
        file_put_contents($ruta, "cualquier,cosa\n1,2\n");

        $plan = $this->importador->plan($ruta);

        $this->assertCount(1, $plan['errors']);
        $this->assertSame(1, $plan['errors'][0]['line']);
        $this->assertContains(lang('Items.bulk_file_not_csv'), $this->mensajes($plan));
    }

    /**
     * El archivo pudo llevárselo un despliegue entre los dos pasos: `writable/uploads` no es un
     * volumen. Eso es un caso normal, no una avería, y `plan()` tiene que poder contarlo.
     */
    public function testAMissingFileIsAnEmptyPlanAndNotAnException(): void
    {
        $plan = $this->importador->plan('/no/existe/este/archivo.csv');

        $this->assertSame([], $plan['to_create']);
        $this->assertSame([], $plan['to_update']);
        $this->assertCount(1, $plan['errors']);
    }

    /** Aplicar un plan vacío no puede escribir nada ni reventar. */
    public function testApplyingAnEmptyPlanDoesNothing(): void
    {
        $antes = $this->conteos();

        $this->assertSame(
            ['created' => 0, 'updated' => 0, 'failed' => []],
            $this->importador->apply(['to_create' => [], 'to_update' => [], 'errors' => [], 'warnings' => []]),
        );
        $this->assertSame($antes, $this->conteos());
    }
}
