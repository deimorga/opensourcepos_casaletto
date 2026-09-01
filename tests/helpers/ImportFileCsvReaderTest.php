<?php

declare(strict_types=1);

namespace Tests\helpers;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * El lector del camino nuevo: números de línea de verdad, y una fila mal formada que no revienta.
 *
 * POR QUÉ HAY UN LECTOR NUEVO Y NO SE ARREGLÓ EL VIEJO
 *
 * `get_csv_file()` la usan la importación de siempre y la de clientes. La Entrega 1 no toca el flujo
 * viejo, así que el nuevo trae su propio lector en vez de cambiarle el contrato al de todos.
 *
 * @internal
 */
final class ImportFileCsvReaderTest extends CIUnitTestCase
{
    private array $temporales = [];

    protected function setUp(): void
    {
        parent::setUp();
        helper('importfile');
    }

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }

        parent::tearDown();
    }

    private function archivo(string $contenido): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'csv_lector_');
        file_put_contents($ruta, $contenido);
        $this->temporales[] = $ruta;

        return $ruta;
    }

    public function testItReadsRowsKeyedByHeader(): void
    {
        $leido = read_items_csv_file($this->archivo("Id,Barcode\n1,C10210\n2,C10209\n"));

        $this->assertSame(['Id', 'Barcode'], $leido['headers']);
        $this->assertCount(2, $leido['rows']);
        $this->assertSame('C10210', $leido['rows'][0]['cells']['Barcode']);
    }

    /**
     * El viejo deduce la línea con `$key + 2`, que solo acierta si ninguna fila se saltó. El cliente
     * lee este número con el archivo abierto al lado: si señala la fila equivocada, no sirve.
     */
    public function testTheLineNumberIsTheRealOneInTheFile(): void
    {
        $leido = read_items_csv_file($this->archivo("Id,Barcode\n1,A\n2,B\n3,C\n"));

        $this->assertSame(2, $leido['rows'][0]['line']);
        $this->assertSame(3, $leido['rows'][1]['line']);
        $this->assertSame(4, $leido['rows'][2]['line']);
    }

    /**
     * LA PRUEBA QUE JUSTIFICA EL LECTOR NUEVO. `array_combine()` LANZA en PHP 8 si la fila no tiene
     * tantas celdas como la cabecera: hoy una coma de más sería un error 500 sobre un archivo que el
     * cliente puede arreglar solo.
     */
    public function testARowWithTooManyColumnsIsAnErrorAndNotACrash(): void
    {
        $leido = read_items_csv_file($this->archivo("Id,Barcode\n1,A\n2,B,sobra\n3,C\n"));

        $this->assertCount(3, $leido['rows']);
        $this->assertNull($leido['rows'][0]['error']);
        $this->assertNotNull($leido['rows'][1]['error'], 'La fila ancha tiene que dar error, no tumbar la petición.');
        $this->assertSame(3, $leido['rows'][1]['line']);
        $this->assertNull($leido['rows'][2]['error'], 'Y el resto del archivo se sigue leyendo.');
    }

    public function testARowWithTooFewColumnsIsAlsoAnError(): void
    {
        $leido = read_items_csv_file($this->archivo("Id,Barcode,Category\n1,A\n"));

        $this->assertNotNull($leido['rows'][0]['error']);
    }

    public function testTheByteOrderMarkIsSkipped(): void
    {
        $bom   = pack('CCC', 0xef, 0xbb, 0xbf);
        $leido = read_items_csv_file($this->archivo($bom . "Id,Barcode\n1,C10210\n"));

        $this->assertSame(['Id', 'Barcode'], $leido['headers'], 'Sin saltar el BOM, la primera columna se llamaría "\u{feff}Id".');
    }

    public function testBlankLinesDoNotBecomeRows(): void
    {
        $leido = read_items_csv_file($this->archivo("Id,Barcode\n1,A\n\n2,B\n"));

        $this->assertCount(2, $leido['rows']);
    }

    /**
     * Un archivo que ya no está no es una avería: `writable/uploads` no es un volumen, así que un
     * despliegue puede llevárselo entre la vista previa y el aplicar.
     */
    public function testAMissingFileIsEmptyAndNotAnException(): void
    {
        $leido = read_items_csv_file('/no/existe/este/archivo.csv');

        $this->assertSame([], $leido['headers']);
        $this->assertSame([], $leido['rows']);
    }

    public function testAFileWithOnlyHeadersHasNoRows(): void
    {
        $leido = read_items_csv_file($this->archivo("Id,Barcode\n"));

        $this->assertSame(['Id', 'Barcode'], $leido['headers']);
        $this->assertSame([], $leido['rows']);
    }
}
