<?php

/**
 * Las columnas fijas del archivo de artículos, en orden. ESTA ES LA DEFINICIÓN Y NO HAY OTRA.
 *
 * La plantilla vacía y la exportación del catálogo salen las dos de aquí. Tenerlas por separado
 * garantizaría que un día dejen de encajar -- y que la exportación produzca un archivo que la
 * importación ya no sepa leer es exactamente el fallo que este trabajo vino a arreglar.
 *
 * "Unit of Measure" está al final y nunca intercalado: los clientes conservan copias llenas de la
 * plantilla, y reordenar corre en silencio todos los valores que ya escribieron. Cualquier columna
 * nueva va DETRÁS de esta, jamás en medio.
 *
 * @return list<string>
 */
function import_items_csv_fixed_columns(): array
{
    return [
        'Id',
        'Barcode',
        'Item Name',
        'Category',
        'Supplier ID',
        'Cost Price',
        'Unit Price',
        'Tax 1 Name',
        'Tax 1 Percent',
        'Tax 2 Name',
        'Tax 2 Percent',
        'Reorder Level',
        'Description',
        'Allow Alt Description',
        'Item has Serial Number',
        'Image',
        'HSN',
        'Unit of Measure',
    ];
}

/**
 * Todas las columnas del archivo, incluidas las de bodega y atributo. El orden es el del archivo.
 *
 * @return list<string>
 */
function import_items_csv_columns(array $stock_locations, array $attributes): array
{
    $columns = import_items_csv_fixed_columns();

    foreach ($stock_locations as $location_name) {
        $columns[] = 'location_' . $location_name;
    }

    // El «[SELECT]» que get_definition_names() añade con clave -1 no es una definición: es la opción
    // vacía de un desplegable, y como columna no significaría nada.
    unset($attributes[-1]);

    foreach ($attributes as $attribute_name) {
        $columns[] = 'attribute_' . $attribute_name;
    }

    return $columns;
}

/**
 * @param array $stock_locations
 * @param array $attributes
 * @return string
 */
function generate_import_items_csv(array $stock_locations, array $attributes): string
{
    $csv_headers = pack('CCC', 0xef, 0xbb, 0xbf);    // Encode the Byte-Order Mark (BOM) so that UTF-8 File headers display properly in Microsoft Excel

    // La plantilla vacía sufre lo mismo que el catálogo: sin esta directiva, Excel en configuración
    // regional española no separa por comas y el cliente escribe sus artículos en una sola columna.
    // Ver `CSV_SEPARATOR_HINT`.
    $csv_headers .= CSV_SEPARATOR_HINT . "\n";
    $csv_headers .= generate_csv_header_line($stock_locations, $attributes);

    return $csv_headers;
}

/**
 * La línea de encabezados, sin BOM.
 *
 * EL ENTRECOMILLADO REPRODUCE EL DE SIEMPRE, Y NO ES CAPRICHO
 *
 * Hasta hoy esta línea era una cadena escrita a mano, y sus comillas no siguen una regla uniforme:
 * las columnas fijas van entrecomilladas SOLO si llevan un espacio, y las de bodega y atributo van
 * entrecomilladas siempre. Se conserva tal cual porque hay clientes con copias llenas de la
 * plantilla, y una prueba afirma que lo generado sale **byte a byte** igual que la cadena vieja.
 */
function generate_csv_header_line(array $stock_locations, array $attributes): string
{
    $fixed = array_map(
        static fn (string $column): string => str_contains($column, ' ') ? '"' . $column . '"' : $column,
        import_items_csv_fixed_columns(),
    );

    return implode(',', $fixed)
        . generate_stock_location_headers($stock_locations)
        . generate_attribute_headers($attributes);
}

/**
 * @param array $locations
 * @return string
 */
function generate_stock_location_headers(array $locations): string
{
    $location_headers = '';

    foreach ($locations as $location_name) {
        $location_headers .= ',"location_' . $location_name . '"';
    }

    return $location_headers;
}

/**
 * @param array $attribute_names
 * @return string
 */
function generate_attribute_headers(array $attribute_names): string
{
    $attribute_headers = '';
    unset($attribute_names[-1]);

    foreach ($attribute_names as $attribute_name) {
        $attribute_headers .= ',"attribute_' . $attribute_name . '"';
    }

    return $attribute_headers;
}

/**
 * La línea que le dice a Excel cuál es el separador.
 *
 * POR QUÉ HACE FALTA, Y POR QUÉ SIN ESTO LA FUNCIÓN NO SIRVE
 *
 * Excel no lee el separador del archivo: usa el de la configuración regional del aparato. En español
 * ese separador es el **punto y coma**, así que al abrir un CSV separado por comas **no lo divide en
 * columnas**: mete la línea entera en la columna A y deja al cliente delante de un muro de texto.
 *
 * Visto en producción el 2026-09-01, en el iPad del dueño, con el catálogo real de Casaletto. Toda la
 * protección de los códigos EAN es inútil si Excel ni siquiera llega a interpretar las celdas.
 *
 * `sep=,` en la primera línea es una directiva que Excel y LibreOffice entienden y que **no depende
 * de la configuración regional de nadie**. Se eligió frente a cambiar el separador a punto y coma
 * porque aquello arreglaría el Excel en español y rompería el que esté en inglés -- y no sabemos qué
 * tiene cada cliente. Esto funciona en los dos.
 *
 * El precio: Google Sheets no la interpreta y la enseña como una primera fila suelta. Es visible y
 * molesta, no silenciosa, que es lo que la hace aceptable.
 */
const CSV_SEPARATOR_HINT = 'sep=,';

/**
 * ¿Esta línea es la directiva del separador y no datos?
 *
 * Se reconoce cualquier separador, no solo la coma: el archivo puede venir de otra herramienta que
 * escriba `sep=;`. Lo que importa es no confundirla nunca con la cabecera.
 *
 * EL SEPARADOR OPCIONAL AL FINAL NO SOBRA
 *
 * A esta función le puede llegar la línea cruda (`sep=,`) o el **primer campo ya interpretado** por
 * `fgetcsv()`. Y ahí está la trampa: leyendo con la coma como delimitador, `sep=,` se parte en dos
 * campos y el primero queda en `sep=`, a secas. Exigir un carácter detrás hacía que la directiva no
 * se reconociera justo en el caso más común -- el nuestro.
 */
function csv_is_separator_hint(string $line): bool
{
    return preg_match('/^sep=.?$/i', trim($line)) === 1;
}

/**
 * Cuántos dígitos hacen falta para que Excel destroce un número al abrir el archivo.
 *
 * Excel pasa a notación científica cualquier número de más de 11 dígitos. Un EAN-13 son 13, así que
 * `7702028000316` se convierte en `7,70203E+12` y al guardar **el código queda destruido**. Se usa 12
 * para dejar margen: por debajo de eso, un código como `C10210` o `300027` no se toca.
 */
const CSV_TEXT_CELL_MIN_DIGITS = 12;

/**
 * Escribe una celda que Excel va a respetar como texto.
 *
 * LAS DOS MITADES DE ESTO VIVEN JUNTAS A PROPÓSITO
 *
 * Esta función y `csv_read_text_cell()` son un par: una envuelve y la otra desenvuelve. Separarlas en
 * archivos distintos es garantizar que un día alguien cambie una y no la otra, y entonces el catálogo
 * exportado deja de poder reimportarse -- sin dar error, que es lo peor.
 *
 * POR QUÉ `="..."` Y NO COMILLAS NORMALES
 *
 * Excel **ignora** las comillas de un CSV para decidir el tipo de una celda: `"7702028000316"` se
 * sigue convirtiendo en notación científica. Lo que sí respeta es la sintaxis de fórmula: `="..."` lo
 * evalúa como texto, lo muestra íntegro, y al guardar de vuelta escribe el valor plano.
 *
 * Y ESO ABRE UN RIESGO QUE HAY QUE CERRAR AQUÍ MISMO
 *
 * Una celda que empieza por `=` es una fórmula. Si un nombre de artículo empezara por `=`, `+`, `-` o
 * `@`, la hoja de cálculo del cliente lo ejecutaría al abrirla: es inyección CSV, y la crea esta
 * exportación, que hasta hoy no existía. Por eso:
 *
 * - Se envuelve SOLO cuando se pide explícitamente (la columna del código) y SOLO si el valor es todo
 *   dígitos y lo bastante largo. Nunca «por si acaso».
 * - Las columnas de TEXTO --nombre, descripción, categoría-- pasan por `csv_neutralise_formula()`.
 *   Las numéricas **no**: ahí un `-5` es un menos cinco, no una fórmula, y neutralizarlo lo rompería.
 */
function csv_text_cell(string $value): string
{
    if ($value === '' || !ctype_digit($value) || strlen($value) < CSV_TEXT_CELL_MIN_DIGITS) {
        return $value;
    }

    return '="' . $value . '"';
}

/**
 * Lee una celda que pudo haber salido envuelta. La otra mitad de `csv_text_cell()`.
 *
 * Acepta el valor plano y el envuelto. **No** intenta rescatar una notación científica: eso es un
 * código ya destruido, y adivinar de qué código venía es imposible -- `7,70203E+12` perdió los
 * dígitos. Quien detecte esa forma tiene que rechazar la fila y decirlo, no interpretarla.
 */
function csv_read_text_cell(string $value): string
{
    $trimmed = trim($value);

    if (preg_match('/^="(.*)"$/s', $trimmed, $matches) === 1) {
        return $matches[1];
    }

    // Y el apóstrofo de «esto es texto», por dos caminos distintos que llegan al mismo sitio:
    //
    // - Lo pone `csv_neutralise_formula()` al exportar un valor que empieza por `=`, `+`, `-` o `@`.
    //   Si el cliente reenvía el archivo sin abrirlo, vuelve tal cual y hay que quitarlo o el código
    //   quedaría guardado con un apóstrofo delante y el artículo sería inencontrable.
    // - Y lo escriben a mano los usuarios de Excel para forzar que una celda se trate como texto.
    //
    // Excel lo quita solo al guardar, así que quitarlo aquí no puede perder un apóstrofo que alguien
    // quisiera de verdad: un código que empiece por apóstrofo no existe.
    if (str_starts_with($trimmed, "'")) {
        return substr($trimmed, 1);
    }

    return $value;
}

/**
 * ¿Esta celda es un número que Excel ya destrozó?
 *
 * `7,70203E+12` y `7.70203E+12`. Detectarlo es lo que convierte un fallo mudo en uno ruidoso: sin
 * esto se guardaría literal en un `varchar` y el artículo quedaría inencontrable para siempre.
 */
function csv_looks_like_scientific_notation(string $value): bool
{
    return preg_match('/^\s*\d+[.,]\d+E\+?\d+\s*$/i', $value) === 1;
}

/**
 * Desactiva el arranque de fórmula de un texto que sale hacia una hoja de cálculo.
 *
 * Se antepone un apóstrofo, que es la marca que Excel y LibreOffice entienden como «esto es texto».
 * Sin esto, un artículo llamado `=1+1` o `@SUM(...)` se ejecutaría al abrir el archivo del cliente.
 */
function csv_neutralise_formula(string $value): string
{
    if ($value !== '' && str_contains('=+-@', $value[0])) {
        return "'" . $value;
    }

    return $value;
}

/**
 * Processes a CSV file and returns it.
 * @param string $file_name
 * @return array A multidimensional array of rows found within the file and their associative key/value pairs.
 */
function get_csv_file(string $file_name): array
{
    $csv_rows = false;

    if (($csv_file = fopen($file_name, 'r')) !== false) {
        helper('security');

        $csv_rows = [];

        // Skip Byte-Order Mark
        if (bom_exists($csv_file)) {
            fseek($csv_file, 3);
        }

        $headers = fgetcsv($csv_file);

        // La directiva `sep=,` que escribe la exportación del catálogo. Esta pantalla es la vieja,
        // pero nada impide que alguien suba por aquí el archivo que se bajó por la nueva, y entonces
        // la cabecera sería «sep=» y no encajaría nada. Saltarla es aditivo: un archivo que no la
        // traiga se comporta exactamente igual que antes.
        if ($headers !== false && $headers !== [null] && csv_is_separator_hint((string)($headers[0] ?? ''))) {
            $headers = fgetcsv($csv_file);
        }

        while (($row = fgetcsv($csv_file)) !== false) {
            if ($row !== [null]) {
                $csv_rows[] = array_combine($headers, $row);
            }
        }

        fclose($csv_file);
    }

    return $csv_rows;
}

/**
 * Lee un CSV para el camino NUEVO: con el número de línea de verdad y sin reventar por una fila mal
 * formada.
 *
 * POR QUÉ NO SE TOCA `get_csv_file()`
 *
 * Esa la usa la importación de siempre y la de clientes. La Entrega 1 no modifica el flujo viejo --es
 * lo que permite dejar sus 37 pruebas donde están y no romperle nada a quien lo use hoy-- así que el
 * flujo nuevo trae su propio lector en vez de cambiarle el contrato al de todos.
 *
 * LAS DOS COSAS QUE ARREGLA
 *
 * 1. **El número de línea.** El viejo lo deduce con `$key + 2`, que solo acierta si ninguna fila se
 *    saltó. Aquí la línea se cuenta al leerla, así que el mensaje «la fila 340 está mal» señala la
 *    340 del archivo que el cliente tiene abierto.
 * 2. **Las filas de ancho distinto.** `array_combine()` **lanza** en PHP 8 si la fila no tiene tantas
 *    celdas como la cabecera -- hoy eso sería un 500 sobre una coma de más. Aquí es un error de esa
 *    fila, con su número, y el resto del archivo se sigue leyendo para poder informarlo todo junto.
 *
 * @return array{headers: list<string>, rows: list<array{line: int, cells: array<string, string>, error: ?string}>}
 */
function read_items_csv_file(string $file_name): array
{
    $handle = @fopen($file_name, 'r');

    if ($handle === false) {
        // Ni excepción ni `false`: el archivo pudo caducar entre la vista previa y el aplicar, y eso
        // no es una avería sino un caso normal que quien llama tiene que poder contar.
        return ['headers' => [], 'rows' => []];
    }

    if (bom_exists($handle)) {
        fseek($handle, 3);
    }

    // `escape: ''` -- RFC 4180 puro, sin el escape por barra invertida que PHP heredó y que nadie
    // escribe en un CSV. Sin esto, un valor que TERMINA en barra invertida deja el campo sin cerrar
    // y el lector **se traga el resto del archivo**. Además el parámetro es obligatorio desde PHP
    // 8.4, que es una de las versiones donde corre la suite.
    $headers = fgetcsv($handle, 0, ',', '"', '');

    // La directiva `sep=,` que la exportación pone para que Excel separe en columnas. Es la primera
    // línea del archivo que nosotros mismos generamos, así que hay que saltarla o la cabecera sería
    // «sep=» y no encajaría ni una columna. Se descuenta también del número de línea, para que el
    // cliente y nosotros contemos igual.
    $hint_lines = 0;

    if ($headers !== false && $headers !== [null] && csv_is_separator_hint((string)($headers[0] ?? ''))) {
        $hint_lines = 1;
        $headers    = fgetcsv($handle, 0, ',', '"', '');
    }

    if ($headers === false || $headers === [null]) {
        fclose($handle);

        return ['headers' => [], 'rows' => []];
    }

    $headers  = array_map(static fn ($header): string => trim((string)$header), $headers);
    $expected = count($headers);
    $rows     = [];

    // La línea FÍSICA del archivo, que es la que el cliente ve en Excel.
    //
    // No basta con contar llamadas a fgetcsv(): una celda entrecomillada puede contener saltos de
    // línea --un nombre de artículo escrito en dos renglones-- y entonces una sola fila de datos
    // ocupa varias líneas del archivo. Contando llamadas, todos los números posteriores quedan
    // corridos, y el mensaje «revise la fila 340» manda al cliente a la fila equivocada.
    //
    // Así que cada fila avanza tantas líneas como saltos traiga dentro más la suya.
    $line = 1 + $hint_lines + csv_physical_lines($headers);

    while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        if ($cells === [null]) {
            $line++;

            continue;    // Línea en blanco. No es un error y no ocupa número de fila para el cliente.
        }

        $line++;

        if (count($cells) !== $expected) {
            $rows[] = [
                'line'  => $line,
                'cells' => [],
                'error' => lang('Items.csv_row_column_count', [count($cells), $expected]),
            ];

            $line += csv_physical_lines($cells);

            continue;
        }

        $rows[] = [
            'line'  => $line,
            'cells' => array_combine($headers, array_map(static fn ($cell): string => (string)$cell, $cells)),
            'error' => null,
        ];

        $line += csv_physical_lines($cells);
    }

    fclose($handle);

    return ['headers' => $headers, 'rows' => $rows];
}


/**
 * Cuántas líneas del archivo ocupa una fila ya interpretada.
 *
 * Una celda entrecomillada puede llevar saltos de línea dentro. Sin contarlos, el número de fila que
 * se le enseña al cliente deja de coincidir con el que ve en Excel en cuanto un solo artículo tiene
 * el nombre en dos renglones.
 *
 * @param array<int|string, string|null> $cells
 */
function csv_physical_lines(array $cells): int
{
    $extra = 0;

    foreach ($cells as $cell) {
        $extra += substr_count(str_replace("\r\n", "\n", (string)$cell), "\n");
    }

    return $extra;
}

/**
 * @param $file_handle
 * @return bool
 */
function bom_exists(&$file_handle): bool
{
    $result        = false;
    $candidate    = fread($file_handle, 3);

    rewind($file_handle);

    $bom = pack('CCC', 0xef, 0xbb, 0xbf);

    if (0 === strncmp($candidate, $bom, 3)) {
        $result = true;
    }

    return $result;
}
