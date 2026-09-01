<?php

declare(strict_types=1);

namespace Tests\helpers;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Las piezas del helper de las que depende que el archivo se pueda bajar, corregir y volver a subir.
 *
 * LA PRUEBA MÁS IMPORTANTE DE ESTE ARCHIVO ES LA PRIMERA
 *
 * La línea de encabezados era hasta hoy una cadena escrita a mano y ahora se genera. Si lo generado no
 * sale **byte a byte** igual, los clientes que conservan copias llenas de la plantilla se encuentran
 * con que su archivo ya no encaja -- y no de forma ruidosa: las columnas se corren y los valores
 * acaban en el campo de al lado.
 *
 * @internal
 */
final class ImportFileCsvCellTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('importfile');
    }

    /** La cadena literal que este helper tuvo escrita a mano hasta el 2026-09-01. */
    private const CABECERA_HISTORICA = 'Id,Barcode,"Item Name",Category,"Supplier ID","Cost Price","Unit Price","Tax 1 Name","Tax 1 Percent","Tax 2 Name","Tax 2 Percent","Reorder Level",Description,"Allow Alt Description","Item has Serial Number",Image,HSN,"Unit of Measure"';

    // ========== La cabecera no se mueve ==========

    public function testTheGeneratedHeaderIsByteForByteTheOldOne(): void
    {
        $this->assertSame(
            self::CABECERA_HISTORICA,
            generate_csv_header_line([], []),
            'Un cliente con una copia llena de la plantilla vieja se quedaría con las columnas corridas.',
        );
    }

    public function testTheTemplateStillCarriesTheByteOrderMark(): void
    {
        // Sin el BOM, Excel abre los encabezados con las tildes rotas.
        $this->assertStringStartsWith(pack('CCC', 0xef, 0xbb, 0xbf), generate_import_items_csv([], []));
    }

    public function testTheColumnListAndTheHeaderLineDescribeTheSameFile(): void
    {
        // Son las dos caras de la misma definición: si se separan, la exportación produce un archivo
        // que la importación no sabe leer. Que es el fallo que todo esto vino a arreglar.
        $columnas = import_items_csv_columns(['Bodega'], [-1 => '[SELECT]', 7 => 'Color']);
        $linea    = generate_csv_header_line(['Bodega'], [-1 => '[SELECT]', 7 => 'Color']);

        $this->assertSame(count($columnas), substr_count($linea, ',') + 1);
        $this->assertSame('location_Bodega', $columnas[18]);
        $this->assertSame('attribute_Color', $columnas[19]);
    }

    public function testTheEmptyDropdownOptionIsNotAColumn(): void
    {
        // get_definition_names() añade «[SELECT]» con clave -1. No es una definición: es la opción
        // vacía de un desplegable, y como columna no significaría nada.
        $columnas = import_items_csv_columns([], [-1 => '[SELECT]']);

        $this->assertSame(import_items_csv_fixed_columns(), $columnas);
    }

    public function testUnitOfMeasureIsTheLastFixedColumn(): void
    {
        // Innegociable: los clientes conservan copias llenas, y meter una columna en medio corre en
        // silencio todos los valores que ya escribieron. Lo nuevo va DETRÁS.
        $fijas = import_items_csv_fixed_columns();

        $this->assertSame('Unit of Measure', end($fijas));
        $this->assertSame('Id', $fijas[0]);
    }

    // ========== El código sobrevive a Excel ==========

    public function testAThirteenDigitCodeIsWrappedSoExcelKeepsIt(): void
    {
        // 7702028000316 es un EAN real de Paraíso. Sin envolver, Excel lo convierte en 7,70203E+12 y
        // al guardar el código queda destruido -- en las 1.184 filas y sin dar error.
        $this->assertSame('="7702028000316"', csv_text_cell('7702028000316'));
    }

    public function testAShortCodeIsLeftAlone(): void
    {
        // Los de Casaletto son «C10210» y «300027». Envolverlos sería ensuciar el archivo sin motivo.
        $this->assertSame('C10210', csv_text_cell('C10210'));
        $this->assertSame('300027', csv_text_cell('300027'));
        $this->assertSame('', csv_text_cell(''));
    }

    public function testWhatIsWrappedCanBeUnwrapped(): void
    {
        // Las dos mitades son un par. Si dejan de encajar, el catálogo exportado no se puede reimportar.
        foreach (['7702028000316', 'C10210', '300027', ''] as $codigo) {
            $this->assertSame($codigo, csv_read_text_cell(csv_text_cell($codigo)), $codigo);
        }
    }

    public function testAPlainCodeIsReadUnchanged(): void
    {
        // El archivo puede no haber salido de nosotros.
        $this->assertSame('7702028000316', csv_read_text_cell('7702028000316'));
    }

    // ========== Y un código ya destruido se detecta, no se adivina ==========

    public function testADamagedCodeIsRecognised(): void
    {
        $this->assertTrue(csv_looks_like_scientific_notation('7,70203E+12'));
        $this->assertTrue(csv_looks_like_scientific_notation('7.70203E+12'));
        $this->assertTrue(csv_looks_like_scientific_notation('7.70203e12'));
    }

    public function testARealCodeIsNotMistakenForADamagedOne(): void
    {
        foreach (['7702028000316', 'C10210', '300027', '', 'E12', '1E'] as $valor) {
            $this->assertFalse(csv_looks_like_scientific_notation($valor), $valor);
        }
    }

    // ========== La exportación no puede inyectar fórmulas ==========

    public function testTextThatStartsLikeAFormulaIsNeutralised(): void
    {
        // Un nombre de artículo que empiece por = se EJECUTARÍA al abrir la hoja del cliente. Este
        // riesgo lo crea la exportación, que hasta hoy no existía.
        $this->assertSame("'=1+1", csv_neutralise_formula('=1+1'));
        $this->assertSame("'@SUM(A1)", csv_neutralise_formula('@SUM(A1)'));
        $this->assertSame("'+1", csv_neutralise_formula('+1'));
        $this->assertSame("'-1", csv_neutralise_formula('-1'));
    }

    public function testOrdinaryTextIsLeftAlone(): void
    {
        $this->assertSame('Aceite Diana 250ML', csv_neutralise_formula('Aceite Diana 250ML'));
        $this->assertSame('', csv_neutralise_formula(''));
    }
}
