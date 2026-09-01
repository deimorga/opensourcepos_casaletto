<?php

declare(strict_types=1);

namespace Tests\Libraries;

use App\Libraries\Item_export_lib;
use App\Models\Attribute;
use App\Models\Item;
use App\Models\Stock_location;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\OSPOS;

/**
 * La descarga del catálogo: el archivo que Paraíso baja, corrige en Excel y vuelve a subir.
 *
 * LO QUE ESTAS PRUEBAS FIJAN, Y POR QUÉ CADA COSA
 *
 * 1. **Que la cabecera sea byte a byte la de `generate_csv_header_line()`.** Es la única prueba que
 *    impide que la exportación y la importación se separen con el tiempo, que es el fallo que todo
 *    este trabajo vino a arreglar.
 * 2. **Que el número de consultas no crezca con el catálogo.** Leyendo artículo por artículo, los
 *    1.184 de Paraíso son ~4.700 consultas. «En lote» sin contarlo es una intención que se pierde en
 *    el primer refactor.
 * 3. **Que el archivo se pueda volver a leer.** Un nombre con una coma, una comilla o un salto de
 *    línea no puede romperlo, y un EAN de 13 dígitos tiene que volver siendo el mismo EAN.
 *
 * CUIDADO AL AÑADIR PRUEBAS AQUÍ
 *
 * La suite comparte UNA sola base y no la refresca entre archivos (`$refresh = false`): todo lo que
 * se siembre aquí se lo encuentran las pruebas de los demás. Por eso `tearDown()` borra hasta la
 * última fila sembrada. Ya costó media jornada una vez.
 *
 * @internal
 */
final class ItemExportLibTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private Item_export_lib $export;

    /** @var list<int> */
    private array $sembrados = [];

    /** @var list<string> */
    private array $archivos = [];

    protected function setUp(): void
    {
        parent::setUp();

        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();

        helper('importfile');

        // Las bodegas del archivo salen de `get_allowed_locations()`, que mira los permisos de quien
        // ha entrado. Sin esto no habría ni una columna `location_*` que comprobar. El 1 es el
        // administrador que siembra el esquema inicial, con su permiso `items_stock` sobre la bodega 1.
        session()->set('person_id', 1);

        $this->export = new Item_export_lib();
    }

    protected function tearDown(): void
    {
        if ($this->sembrados !== []) {
            db_connect()->table('items_taxes')->whereIn('item_id', $this->sembrados)->delete();
            db_connect()->table('item_quantities')->whereIn('item_id', $this->sembrados)->delete();
            db_connect()->table('attribute_links')->whereIn('item_id', $this->sembrados)->delete();
            db_connect()->table('items')->whereIn('item_id', $this->sembrados)->delete();
        }

        foreach ($this->archivos as $archivo) {
            @unlink($archivo);
        }

        session()->remove('person_id');

        parent::tearDown();
    }

    /**
     * Siembra un artículo. Todo tiene valor por omisión para que cada prueba nombre SOLO el campo del
     * que habla, y se lea como la frase que quiere demostrar.
     */
    private function crearArticulo(array $campos = []): int
    {
        db_connect()->table('items')->insert($campos + [
            'name'                  => 'Artículo de prueba',
            'item_number'           => null,
            'category'              => 'Pruebas',
            'description'           => '',
            'supplier_id'           => null,
            'cost_price'            => '0.00',
            'unit_price'            => '0.00',
            'reorder_level'         => '0.000',
            'allow_alt_description' => 0,
            'is_serialized'         => 0,
            'item_type'             => ITEM,
            'deleted'               => 0,
        ]);

        $id                = (int) db_connect()->insertID();
        $this->sembrados[] = $id;

        return $id;
    }

    /** El CSV escrito a un archivo temporal, que es como lo va a leer la importación. */
    private function exportarAArchivo(): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'exportacion_articulos_');

        file_put_contents($ruta, $this->export->toCsv());
        $this->archivos[] = $ruta;

        return $ruta;
    }

    /**
     * Las celdas del artículo pedido, leídas de vuelta con el lector del camino NUEVO.
     *
     * Volver a leer en vez de mirar la cadena es lo que demuestra lo que importa: que el archivo
     * sobrevive al viaje. Mirar bytes sueltos solo demuestra que se escribieron.
     */
    private function filaDe(int $itemId): array
    {
        $leido = read_items_csv_file($this->exportarAArchivo());

        foreach ($leido['rows'] as $fila) {
            if ($fila['error'] === null && (int) ($fila['cells']['Id'] ?? 0) === $itemId) {
                return $fila['cells'];
            }
        }

        $this->fail("El artículo $itemId no salió en la exportación.");
    }

    // ========== La cabecera, que es el contrato con la importación ==========

    public function testTheHeaderIsByteForByteTheSharedOne(): void
    {
        $locations  = model(Stock_location::class)->get_allowed_locations();
        $attributes = model(Attribute::class)->get_definition_names();

        $csv       = $this->export->toCsv();
        $sinBom    = substr($csv, 3);
        $cabecera  = substr($sinBom, 0, (int) strpos($sinBom, "\n"));

        $this->assertSame(
            generate_csv_header_line($locations, $attributes),
            $cabecera,
            'Si la exportación arma su propia cabecera, un día produce un archivo que la importación ya no sabe leer.',
        );
    }

    public function testTheFileCarriesTheBomLikeTheTemplate(): void
    {
        $this->assertStringStartsWith(
            pack('CCC', 0xef, 0xbb, 0xbf),
            $this->export->toCsv(),
            'Sin BOM, Excel abre las tildes rotas.',
        );
    }

    public function testTheHeaderCarriesAColumnForEachWarehouse(): void
    {
        $bodegas = model(Stock_location::class)->get_allowed_locations();

        $this->assertNotSame([], $bodegas, 'Sin bodegas permitidas no habría nada que comprobar: revise el permiso items_stock del sembrado.');
        $this->assertStringContainsString('"location_' . reset($bodegas) . '"', $this->export->toCsv());
    }

    // ========== Qué artículos salen ==========

    public function testKitsTemporariesAndDeletedItemsStayOut(): void
    {
        $normal = $this->crearArticulo(['name' => 'Normal exportable']);
        $kit    = $this->crearArticulo(['name' => 'Kit exportable', 'item_type' => ITEM_KIT]);
        $temp   = $this->crearArticulo(['name' => 'Temporal exportable', 'item_type' => ITEM_TEMP]);
        $monto  = $this->crearArticulo(['name' => 'Monto exportable', 'item_type' => ITEM_AMOUNT_ENTRY]);
        $muerto = $this->crearArticulo(['name' => 'Borrado exportable', 'deleted' => 1]);

        $ids = [];

        foreach (read_items_csv_file($this->exportarAArchivo())['rows'] as $fila) {
            $ids[] = (int) ($fila['cells']['Id'] ?? 0);
        }

        $this->assertContains($normal, $ids);
        $this->assertNotContains($kit, $ids, 'Un kit no se puede reconstruir desde estas columnas.');
        $this->assertNotContains($temp, $ids, 'Reimportar un temporal lo resucita.');
        $this->assertNotContains($monto, $ids);
        $this->assertNotContains($muerto, $ids);
    }

    // ========== Los códigos, que es donde Excel destroza el catálogo de Paraíso ==========

    public function testAThirteenDigitEanComesOutWrappedSoExcelKeepsIt(): void
    {
        $id = $this->crearArticulo(['name' => 'EAN largo', 'item_number' => '7702028000316']);

        $celda = $this->filaDe($id)['Barcode'];

        $this->assertSame('="7702028000316"', $celda, 'Sin envolver, Excel lo convierte en 7,70203E+12 y lo destruye.');
        $this->assertSame('7702028000316', csv_read_text_cell($celda), 'Y la importación tiene que recuperar el código limpio.');
    }

    public function testAShortCodeComesOutPlain(): void
    {
        $id = $this->crearArticulo(['name' => 'Código corto', 'item_number' => '300027']);

        $this->assertSame(
            '300027',
            $this->filaDe($id)['Barcode'],
            'Envolver de más es inyección CSV: se envuelve solo lo que Excel rompería.',
        );
    }

    public function testAnItemWithNoCodeComesOutWithAnEmptyBarcodeAndItsIdFilled(): void
    {
        // Los 18 artículos de Casaletto sin código: su único ancla para volver a actualizarse es el Id.
        $id = $this->crearArticulo(['name' => 'Sin código', 'item_number' => null]);

        $fila = $this->filaDe($id);

        $this->assertSame('', $fila['Barcode']);
        $this->assertSame((string) $id, $fila['Id']);
    }

    /**
     * Un código que empieza por `=` es una fórmula que la hoja del cliente EJECUTA al abrirse. Hoy no
     * hay ninguno en producción, pero esta exportación es lo que llevaría uno hasta su Excel, y el
     * cliente puede teclearlo mañana.
     *
     * Las dos protecciones no se estorban --una actúa sobre puros dígitos, la otra sobre símbolos--
     * pero el orden importa: neutralizar un valor ya envuelto le pondría un apóstrofo delante del
     * `="` y Excel lo mostraría literal.
     */
    public function testACodeThatStartsLikeAFormulaIsNeutralised(): void
    {
        $id = $this->crearArticulo(['name' => 'Código con fórmula', 'item_number' => '=SUM(A1)']);

        $celda = $this->filaDe($id)['Barcode'];

        $this->assertSame("'=SUM(A1)", $celda);
        $this->assertSame('=SUM(A1)', csv_read_text_cell($celda), 'Y el viaje de ida y vuelta lo devuelve intacto.');
    }

    public function testNeutralisingDoesNotBreakTheEanWrapper(): void
    {
        // El orden inverso daría «'="7702028000316"», que Excel enseña tal cual y destruye el viaje.
        $id = $this->crearArticulo(['name' => 'EAN otra vez', 'item_number' => '7702028000316']);

        $this->assertStringStartsWith('="', $this->filaDe($id)['Barcode']);
    }

    /**
     * EL CHOQUE ENTRE LOS DOS CARRILES, Y ES REAL.
     *
     * `reorder_level` es `decimal(15,3)`, así que el motor devuelve `5.000` para un punto de reorden
     * de cinco. Ese valor es indistinguible de «cinco mil» escrito a la colombiana, y la importación
     * lo rechaza por ambiguo -- con razón. Sin recortar los ceros de cola, **el propio archivo que
     * generamos fallaría al volver**, y no en un caso raro: Casaletto tiene 18 artículos con punto de
     * reorden distinto de cero.
     */
    public function testNumbersComeOutWithoutTrailingZerosSoTheyCanComeBack(): void
    {
        $id = $this->crearArticulo([
            'name'          => 'Con punto de reorden',
            'item_number'   => 'PRUEBA-REORDEN',
            'reorder_level' => 5,
            'unit_price'    => 1500,
            'cost_price'    => 1000.5,
        ]);

        $fila = $this->filaDe($id);

        $this->assertSame('5', $fila['Reorder Level'], 'Con «5.000» la importación rechazaría la fila.');
        $this->assertSame('1500', $fila['Unit Price']);
        $this->assertSame('1000.5', $fila['Cost Price'], 'Los decimales de verdad se conservan.');
    }

    public function testAZeroStaysAZeroAndDoesNotBecomeEmpty(): void
    {
        // Vacío significa «no cambiar». Un cero exportado como nada dejaría de poder poner un precio
        // en cero -- que es justo lo que Paraíso tiene hoy en sus 1.184 artículos.
        $id = $this->crearArticulo(['name' => 'Todo en cero', 'item_number' => 'PRUEBA-CERO']);

        $fila = $this->filaDe($id);

        $this->assertSame('0', $fila['Unit Price']);
        $this->assertSame('0', $fila['Reorder Level']);
    }

    // ========== Que el archivo no se rompa ==========

    public function testANameWithACommaAQuoteAndALineBreakComesBackTheSame(): void
    {
        $nombre = "Arroz \"premium\", blanco\ny algo más";
        $id     = $this->crearArticulo(['name' => $nombre]);

        $this->assertSame($nombre, $this->filaDe($id)['Item Name']);
    }

    public function testTheOldReaderCanStillReadTheFileToo(): void
    {
        // `get_csv_file()` es el lector del camino viejo. Si la exportación produjera algo que solo
        // sabe leer el lector nuevo, el archivo dejaría de servir en la pantalla que existe hoy.
        $nombre = 'Panela, en "bloque"';
        $id     = $this->crearArticulo(['name' => $nombre]);

        $encontrado = null;

        foreach (get_csv_file($this->exportarAArchivo()) as $fila) {
            if ((int) $fila['Id'] === $id) {
                $encontrado = $fila['Item Name'];
            }
        }

        $this->assertSame($nombre, $encontrado);
    }

    public function testANameThatStartsWithAnEqualsSignComesOutNeutralised(): void
    {
        // Sin esto, la hoja de cálculo del cliente EJECUTA el nombre al abrir el archivo. Y la
        // exportación es quien lo crea: hasta hoy no había archivo que abrir.
        $id = $this->crearArticulo(['name' => '=1+1']);

        $this->assertSame("'=1+1", $this->filaDe($id)['Item Name']);
    }

    public function testACategoryAndADescriptionThatStartWithAFormulaAreNeutralisedToo(): void
    {
        $id = $this->crearArticulo([
            'name'        => 'Con categoría rara',
            'category'    => '@SUM(A1:A9)',
            'description' => '+1',
        ]);

        $fila = $this->filaDe($id);

        $this->assertSame("'@SUM(A1:A9)", $fila['Category']);
        $this->assertSame("'+1", $fila['Description']);
    }

    public function testANegativePriceIsNotNeutralised(): void
    {
        // En una columna numérica un `-5` es un menos cinco, no una fórmula. Neutralizarlo lo rompería.
        $id = $this->crearArticulo(['name' => 'Precio negativo', 'unit_price' => '-5.00']);

        $this->assertSame('-5.00', $this->filaDe($id)['Unit Price']);
    }

    // ========== Los booleanos, que vacíos vuelven cambiados ==========

    public function testAFalseBooleanComesOutAsZeroAndNotEmpty(): void
    {
        $id = $this->crearArticulo(['name' => 'Sin descripción alterna', 'allow_alt_description' => 0]);

        $fila = $this->filaDe($id);

        $this->assertSame('0', $fila['Allow Alt Description'], 'Un booleano en blanco vuelve cambiado: es corrupción del viaje de ida y vuelta.');
        $this->assertSame('0', $fila['Item has Serial Number']);
    }

    public function testATrueBooleanComesOutAsOne(): void
    {
        $id = $this->crearArticulo([
            'name'                  => 'Con descripción alterna',
            'allow_alt_description' => 1,
            'is_serialized'         => 1,
        ]);

        $fila = $this->filaDe($id);

        $this->assertSame('1', $fila['Allow Alt Description']);
        $this->assertSame('1', $fila['Item has Serial Number']);
    }

    // ========== La unidad de medida, que es la que hoy se pierde en el viaje ==========

    public function testAnItemSoldByTheKilogramComesOutAsKg(): void
    {
        $id = $this->crearArticulo(['name' => 'Queso campesino', 'unit_of_measure' => Item::UNIT_OF_MEASURE_KG]);

        $this->assertSame(Item::UNIT_OF_MEASURE_KG, $this->filaDe($id)['Unit of Measure']);
    }

    // ========== Las existencias: se ven, no se aplican ==========

    public function testStockComesOutFilledWhenThereIsARow(): void
    {
        $bodegas    = model(Stock_location::class)->get_allowed_locations();
        $locationId = (int) array_key_first($bodegas);

        $id = $this->crearArticulo(['name' => 'Con existencias']);
        db_connect()->table('item_quantities')->insert(['item_id' => $id, 'location_id' => $locationId, 'quantity' => 7]);

        $this->assertSame('7.000', $this->filaDe($id)['location_' . $bodegas[$locationId]]);
    }

    public function testStockComesOutAsZeroWhenThereIsNoRow(): void
    {
        // Un artículo recién creado no tiene fila en `item_quantities`. Dejar la celda vacía diría
        // «no sé», y lo que hay que decir es «ninguno».
        $bodegas = model(Stock_location::class)->get_allowed_locations();

        $id = $this->crearArticulo(['name' => 'Sin existencias']);

        $this->assertSame('0', $this->filaDe($id)['location_' . reset($bodegas)]);
    }

    // ========== Los impuestos: caben dos, y el orden no puede ser el capricho del motor ==========

    public function testOnlyTheFirstTwoTaxesFitAndTheOrderIsStable(): void
    {
        $id = $this->crearArticulo(['name' => 'Con tres impuestos']);

        foreach ([['IVA', '19'], ['Consumo', '8'], ['Bolsa', '1']] as [$nombre, $porcentaje]) {
            db_connect()->table('items_taxes')->insert(['item_id' => $id, 'name' => $nombre, 'percent' => $porcentaje]);
        }

        $fila = $this->filaDe($id);

        // La lectura en lote ordena por porcentaje y luego por nombre: 1 (Bolsa), 8 (Consumo), 19 (IVA).
        $this->assertSame('Bolsa', $fila['Tax 1 Name']);
        $this->assertSame('1.000', $fila['Tax 1 Percent']);
        $this->assertSame('Consumo', $fila['Tax 2 Name']);
        $this->assertSame('8.000', $fila['Tax 2 Percent']);

        // Y dos descargas seguidas tienen que dar exactamente lo mismo.
        $this->assertSame($fila, $this->filaDe($id));
    }

    public function testAnItemWithNoTaxesLeavesBothTaxColumnsEmpty(): void
    {
        $id = $this->crearArticulo(['name' => 'Sin impuestos']);

        $fila = $this->filaDe($id);

        $this->assertSame('', $fila['Tax 1 Name']);
        $this->assertSame('', $fila['Tax 1 Percent']);
        $this->assertSame('', $fila['Tax 2 Name']);
        $this->assertSame('', $fila['Tax 2 Percent']);
    }

    // ========== La prueba central: el coste no puede crecer con el catálogo ==========

    /**
     * Con 1.184 artículos, leer de uno en uno son ~4.700 consultas y el servidor se cae. Aquí se fija
     * el presupuesto exacto: dos consultas fijas (bodegas y atributos) más cuatro por lote.
     */
    public function testTheNumberOfQueriesIsTheBatchBudgetAndNotOnePerItem(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $this->crearArticulo(['name' => "Artículo de presupuesto $i", 'item_number' => "PRESUPUESTO-$i"]);
        }

        $total = model(Item::class)->count_all_for_export();

        $antes     = count($this->db->getQueries());
        $this->export->toCsv();
        $consultas = count($this->db->getQueries()) - $antes;

        $lotesLeidos   = intdiv($total, Item_export_lib::BATCH_SIZE) + 1;
        $lotesConFilas = (int) ceil($total / Item_export_lib::BATCH_SIZE);
        $presupuesto   = 2 + $lotesLeidos + 3 * $lotesConFilas;

        $this->assertSame($presupuesto, $consultas, 'Cuatro consultas por lote: artículos, impuestos, existencias y atributos.');
        $this->assertLessThan($total, $consultas, 'Si el coste creciera por artículo, esto sería falso con 40 sembrados.');
    }

    /**
     * La misma verdad dicha sin fórmulas: se añaden 40 artículos y la cuenta NO se mueve.
     */
    public function testAddingItemsWithinABatchDoesNotAddQueries(): void
    {
        $this->crearArticulo(['name' => 'Uno solo para medir']);

        if (model(Item::class)->count_all_for_export() + 40 > Item_export_lib::BATCH_SIZE) {
            $this->markTestSkipped('La base de pruebas ya trae más artículos de los que caben en un lote.');
        }

        $antes     = count($this->db->getQueries());
        $this->export->toCsv();
        $conUno    = count($this->db->getQueries()) - $antes;

        for ($i = 0; $i < 40; $i++) {
            $this->crearArticulo(['name' => "Artículo añadido $i"]);
        }

        $antes         = count($this->db->getQueries());
        $this->export->toCsv();
        $conCuarentaYUno = count($this->db->getQueries()) - $antes;

        $this->assertSame($conUno, $conCuarentaYUno, 'Cuarenta artículos más no pueden costar ni una consulta más.');
    }

    // ========== El archivo en disco, que es el «cómo estaba antes» ==========

    public function testWriteToLeavesOnDiskExactlyWhatTheDownloadWouldGive(): void
    {
        $this->crearArticulo(['name' => 'Para el respaldo', 'item_number' => 'RESPALDO-1']);

        $ruta             = tempnam(sys_get_temp_dir(), 'respaldo_articulos_');
        $this->archivos[] = $ruta;

        $this->assertTrue($this->export->writeTo($ruta));
        $this->assertSame($this->export->toCsv(), file_get_contents($ruta));
    }

    public function testWriteToSaysFalseInsteadOfThrowingWhenItCannotWrite(): void
    {
        // Es una red, no un requisito: que falle no puede impedir aplicar los cambios del cliente.
        $this->assertFalse($this->export->writeTo('/directorio/que/no/existe/respaldo.csv'));
    }

    public function testTheFileNameIsAPlainCsvNameWithNothingToInjectIntoAHeader(): void
    {
        $nombre = $this->export->fileName();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_.-]+\.csv$/', $nombre);
        $this->assertStringNotContainsString('"', $nombre);
    }
}
