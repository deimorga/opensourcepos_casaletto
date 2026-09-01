<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\Item;
use App\Models\Item_quantity;
use App\Models\Item_taxes;
use App\Models\Stock_location;
use App\Models\Supplier;
use Throwable;

/**
 * Leer el archivo del cliente, decidir qué haría con cada fila, y --solo si lo aprueban-- hacerlo.
 *
 * LAS DOS MITADES ESTÁN SEPARADAS A PROPÓSITO
 *
 * `plan()` no escribe nada. `apply()` no decide nada. Esa separación es lo que hace posible la promesa
 * de la vista previa: lo que se aplica es exactamente lo que se enseñó, porque es el mismo plan.
 * Mezclarlas --validar mientras se escribe, como hace el flujo viejo-- es lo que hoy obliga a meter
 * todo en una transacción y a revertir mil filas buenas por una mala.
 *
 * LO QUE ESTA CLASE RESUELVE
 *
 * 1. **Emparejar por código cuando no viene `Id`** (D3), con `Item::resolve_item_numbers()`, que
 *    distingue «ninguno», «uno» y «varios». Un código en varios artículos es error de fila (D6): no
 *    se adivina cuál. El `Id`, si viene, manda sobre el código.
 *
 * 2. **Celda vacía = no cambiar** (D4). En un `update` eso es omitir la clave del arreglo. En un
 *    `insert` NO basta: varias columnas son `NOT NULL` sin DEFAULT y solo funcionan porque
 *    `strictOn = false` deja que MariaDB rellene con un aviso. Apoyarse en eso es apoyarse en una
 *    línea de configuración que alguien cambiará: en el alta se escriben valores explícitos.
 *
 * 3. **Los números se rechazan si son ambiguos, no se interpretan.** Con `number_locale = es_CO` el
 *    punto es separador de miles, así que `"1.000"` puede ser mil o puede ser uno. Hoy se guarda como
 *    uno, en silencio. El patrón a copiar es `Sale_lib::normalize_weight_input()`, que existe
 *    exactamente por esto.
 *
 * 4. **Un código en notación científica es un código destruido por Excel.** `7,70203E+12`. Se rechaza
 *    la fila diciéndolo (`csv_looks_like_scientific_notation()`), nunca se guarda: guardarlo dejaría
 *    un artículo inencontrable para siempre.
 *
 * 5. **Dos filas del mismo archivo no pueden crear el mismo código.** Las dos pasarían la validación
 *    por separado --ninguna existe todavía-- y crearían un duplicado.
 *
 * 6. **Una fila que empareje con un kit o un temporal es un error.** El archivo puede no haber salido
 *    de nosotros, y los temporales heredan el código que tecleó el cajero.
 *
 * 7. **El tercer impuesto no se pierde.** `Item_taxes::save_value()` borra y reinserta, así que un
 *    artículo con tres impuestos se queda con dos al reimportar el archivo que nosotros mismos
 *    generamos. Regla: si el par del archivo es idéntico a los dos primeros guardados, **no se
 *    escribe nada**, ni el borrado. Ese es el 99% de las filas de un viaje de ida y vuelta.
 *
 * 8. **Las existencias no se aplican** (decisión del dueño, 2026-09-01). Un artículo nuevo sí recibe
 *    sus filas en cero --sin ellas no sale en la grilla filtrada por bodega-- pero de uno existente no
 *    se toca nada, y la pantalla lo dice.
 *
 * LO QUE ESTA CLASE **NO** TOCA, Y POR QUÉ SE DICE AQUÍ
 *
 * - **`inventory`.** El punto 8 pide «sus filas en cero» para que el artículo salga en la grilla
 *   filtrada por bodega, y esa grilla se arma con `item_quantities`; `inventory` es el libro de
 *   movimientos. Un alta de 1.184 artículos escribiría 1.184 asientos que dicen «cero», que no
 *   documentan ningún movimiento porque no lo hubo. Se dejan fuera a propósito.
 * - **Los atributos.** El archivo trae columnas `attribute_*` y aquí se ignoran: no cambiarlas es
 *   exactamente D4, y el viaje de ida y vuelta las devuelve intactas. Aplicarlas es Entrega 2.
 * - **La unicidad del código al escribir.** El flujo viejo rechaza la fila si `item_number_exists()`,
 *   y eso es justo lo que hace que reimportar el propio catálogo conteste «fallaron las filas 2 a
 *   1185» (funcional §2.3). Aquí la ambigüedad se resuelve al emparejar --D6-- y no al escribir.
 */
final class Item_import_lib
{
    // Los nombres de columna se escriben una vez y se usan por constante: son literales que aparecen
    // en el archivo del cliente y en los mensajes de error, y un dedazo en uno de ellos no falla, sino
    // que hace que esa columna se lea siempre vacía. Es decir, en silencio.
    private const COL_ID          = 'Id';
    private const COL_BARCODE     = 'Barcode';
    private const COL_NAME        = 'Item Name';
    private const COL_CATEGORY    = 'Category';
    private const COL_SUPPLIER    = 'Supplier ID';
    private const COL_COST        = 'Cost Price';
    private const COL_PRICE       = 'Unit Price';
    private const COL_TAX1_NAME   = 'Tax 1 Name';
    private const COL_TAX1_PCT    = 'Tax 1 Percent';
    private const COL_TAX2_NAME   = 'Tax 2 Name';
    private const COL_TAX2_PCT    = 'Tax 2 Percent';
    private const COL_REORDER     = 'Reorder Level';
    private const COL_DESCRIPTION = 'Description';
    private const COL_ALT_DESC    = 'Allow Alt Description';
    private const COL_SERIALIZED  = 'Item has Serial Number';
    private const COL_IMAGE       = 'Image';
    private const COL_HSN         = 'HSN';
    private const COL_UNIT        = 'Unit of Measure';

    private Item $item;
    private Item_taxes $itemTaxes;
    private Item_quantity $itemQuantity;
    private Supplier $supplier;
    private Stock_location $stockLocation;

    /**
     * Qué proveedores existen, recordado durante un `plan()`.
     *
     * `Supplier::exists()` es una consulta, y la columna del proveedor viene llena en todas las filas
     * de un catálogo exportado: sin esto serían 1.184 consultas más dentro del paso que promete no
     * escribir nada. Un negocio tiene decenas de proveedores, no miles, así que recordar la respuesta
     * deja una consulta por proveedor DISTINTO en vez de una por fila.
     *
     * @var array<int, bool>
     */
    private array $supplierExists = [];

    public function __construct()
    {
        helper('importfile');

        $this->item          = model(Item::class);
        $this->itemTaxes     = model(Item_taxes::class);
        $this->itemQuantity  = model(Item_quantity::class);
        $this->supplier      = model(Supplier::class);
        $this->stockLocation = model(Stock_location::class);
    }

    /**
     * Lee el archivo y devuelve QUÉ HARÍA. No escribe absolutamente nada.
     *
     * @return array{
     *     to_create: list<array<string, mixed>>,
     *     to_update: list<array<string, mixed>>,
     *     errors: list<array{line: int, message: string}>,
     *     warnings: list<array{line: int, message: string}>
     * }
     *
     * `warnings` es para lo que se aplica pero conviene decir: el artículo con tres impuestos cuyos
     * impuestos no se tocan, por ejemplo. Un aviso no impide aplicar; un error sí impide esa fila.
     *
     * UN PUÑADO DE CONSULTAS, NO MILES
     *
     * Los códigos, los `Id` y los impuestos guardados se resuelven **en lote**, después de haber leído
     * el archivo entero, y los proveedores se recuerdan. Preguntar por fila son ~4.700 consultas con
     * las 1.184 de Paraíso, y el paso que promete «no escribo nada» sería el más caro de los dos.
     */
    public function plan(string $csvPath): array
    {
        $this->supplierExists = [];

        $plan = ['to_create' => [], 'to_update' => [], 'errors' => [], 'warnings' => []];

        $file = read_items_csv_file($csvPath);

        // Un archivo ilegible, vacío, o que no es de artículos. Se cuenta como error de la línea 1
        // --que es la de encabezados-- en vez de como excepción: quien llama tiene que poder pintar la
        // pantalla, no ver un 500.
        if ($file['headers'] === [] || array_diff(self::requiredColumns(), $file['headers']) !== []) {
            $plan['errors'][] = ['line' => 1, 'message' => lang('Items.bulk_file_not_csv')];

            return $plan;
        }

        // ===== Paso 1: leer cada fila por su cuenta, sin mirar la base =====
        $parsed = [];

        foreach ($file['rows'] as $row) {
            if ($row['error'] !== null) {
                $plan['errors'][] = ['line' => $row['line'], 'message' => $row['error']];

                continue;
            }

            $parsed[] = $this->parseRow($row['line'], $row['cells']);
        }

        // ===== Paso 2: lo que hay que preguntarle a la base, en lote =====
        $codes = [];
        $ids   = [];

        foreach ($parsed as $row) {
            if ($row['errors'] !== []) {
                continue;
            }

            if ($row['code'] !== null) {
                $codes[] = $row['code'];
            }

            if ($row['id'] !== null) {
                $ids[] = $row['id'];
            }
        }

        $byCode = $this->item->resolve_item_numbers($codes);
        $byId   = $this->itemsById($ids);

        // Un código que aparece dos veces en el MISMO archivo no lo detecta ninguna consulta: las dos
        // filas se validan por separado y las dos pasan.
        $timesInFile = array_count_values($codes);

        // ===== Paso 3: decidir qué hace cada fila =====
        foreach ($parsed as $row) {
            $entry = $this->decideRow($row, $byCode, $byId, $timesInFile);

            if ($entry['errors'] !== []) {
                foreach ($entry['errors'] as $message) {
                    $plan['errors'][] = ['line' => $row['line'], 'message' => $message];
                }

                continue;
            }

            if ($entry['item_id'] === null) {
                $plan['to_create'][] = [
                    'line'        => $row['line'],
                    'label'       => $entry['label'],
                    'item_number' => $entry['fields']['item_number'] ?? '',
                    'fields'      => $entry['fields'],
                    'taxes'       => $row['taxes'],
                ];

                continue;
            }

            $plan['to_update'][] = [
                'line'        => $row['line'],
                'item_id'     => $entry['item_id'],
                'label'       => $entry['label'],
                'item_number' => $entry['fields']['item_number'] ?? $entry['stored_number'],
                'fields'      => $entry['fields'],
                'taxes'       => $row['taxes'],    // Todavía sin decidir; lo cierra el paso 4.
            ];
        }

        // ===== Paso 4: los impuestos, que necesitan saber qué hay guardado =====
        $this->resolveTaxes($plan);

        // Los errores de formato del archivo se detectan al leerlo y los de emparejamiento después, así
        // que sin esto la lista saldría en dos bloques. El cliente la lee con el archivo abierto al
        // lado y va bajando por él: tiene que ir en orden de fila.
        $porLinea = static fn (array $a, array $b): int => $a['line'] <=> $b['line'];

        usort($plan['errors'], $porLinea);
        usort($plan['warnings'], $porLinea);

        return $plan;
    }

    /**
     * Aplica un plan ya calculado y aprobado.
     *
     * Recibe el plan y no el archivo: lo que se escribe tiene que ser exactamente lo que se enseñó.
     *
     * TODO O NADA, Y AQUÍ SÍ ES LO CORRECTO
     *
     * El flujo viejo mete validación y escritura en la misma transacción, y por eso una fila mala
     * revierte mil buenas. Aquí las filas malas ya se quedaron fuera del plan: si una escritura falla
     * a estas alturas es una avería --la base se cayó, alguien borró el artículo entre los dos pasos--
     * y no un dato del cliente. Ante una avería, revertir es lo único que deja la base coherente con
     * el «cómo estaba antes» que se acaba de fotografiar, y con lo que la pantalla prometió.
     *
     * @param array $plan lo que devolvió `plan()`
     * @return array{created: int, updated: int, failed: list<array{line: int, message: string}>}
     */
    public function apply(array $plan): array
    {
        $created = 0;
        $updated = 0;
        $failed  = [];

        $toCreate = $plan['to_create'] ?? [];
        $toUpdate = $plan['to_update'] ?? [];

        if ($toCreate === [] && $toUpdate === []) {
            return ['created' => 0, 'updated' => 0, 'failed' => []];
        }

        // Se leen una vez y no por fila: son las mismas para las 1.184.
        $locations = $this->stockLocation->get_allowed_locations();

        $db = db_connect();
        $db->transBegin();

        foreach ($toCreate as $entry) {
            try {
                $itemId = $this->insertItem($entry, $locations);

                if ($itemId === null) {
                    $failed[] = ['line' => (int) $entry['line'], 'message' => lang('Items.csv_import_failed')];

                    continue;
                }

                $created++;
            } catch (Throwable $e) {
                $failed[] = ['line' => (int) $entry['line'], 'message' => $e->getMessage()];
            }
        }

        foreach ($toUpdate as $entry) {
            try {
                if (! $this->updateItem($entry)) {
                    $failed[] = ['line' => (int) $entry['line'], 'message' => lang('Items.csv_import_failed')];

                    continue;
                }

                $updated++;
            } catch (Throwable $e) {
                $failed[] = ['line' => (int) $entry['line'], 'message' => $e->getMessage()];
            }
        }

        if ($db->transStatus() === false && $failed === []) {
            // Todas las escrituras comprueban lo que devuelven, así que aquí no debería llegarse nunca.
            // Pero si se llegara --una consulta que falla sin devolver false-- revertir en silencio y
            // contestar «se crearon 0 y se actualizaron 0» sería el peor resultado posible: parecería
            // que el archivo no tenía nada. La fila 0 significa «ninguna en particular».
            $failed[] = ['line' => 0, 'message' => lang('Items.csv_import_failed')];
        }

        if ($failed !== []) {
            $db->transRollback();

            return ['created' => 0, 'updated' => 0, 'failed' => $failed];
        }

        $db->transCommit();

        return ['created' => $created, 'updated' => $updated, 'failed' => []];
    }

    // =================================================================================================
    // Leer una fila
    // =================================================================================================

    /**
     * Las columnas que un archivo tiene que traer para que lo leamos.
     *
     * **`Unit of Measure` no está**, y es deliberado: es la columna más nueva y hay clientes con copias
     * llenas de una plantilla anterior a ella. El helper ya trata su ausencia como «no contestado»
     * (`importfile_helper.php`, «la clave solo existe cuando la celda tiene algo»); rechazar el archivo
     * entero por faltarle contradiría eso.
     *
     * Las de bodega y atributo tampoco: aquí no se aplican.
     *
     * @return list<string>
     */
    /** Ver `$supplierExists`. */
    private function supplierIsReal(int $personId): bool
    {
        return $this->supplierExists[$personId] ??= $this->supplier->exists($personId);
    }

    private static function requiredColumns(): array
    {
        return array_values(array_filter(
            import_items_csv_fixed_columns(),
            static fn (string $column): bool => $column !== self::COL_UNIT,
        ));
    }

    /**
     * Convierte las celdas de una fila en datos, sin consultar la base.
     *
     * @param array<string, string> $cells
     * @return array{line: int, id: ?int, code: ?string, fields: array<string, mixed>, taxes: ?list<array{name: string, percent: string}>, errors: list<string>}
     */
    private function parseRow(int $line, array $cells): array
    {
        $errors = [];
        $fields = [];

        // ----- El Id -----
        $id     = null;
        $rawId  = trim($cells[self::COL_ID] ?? '');

        if ($rawId !== '') {
            if (! ctype_digit($rawId)) {
                // Un Id que no es un número no puede existir. Se dice con el mismo mensaje que un Id
                // inexistente porque para el cliente es el mismo problema: ese Id no señala a nada.
                $errors[] = lang('Items.csv_row_id_unknown', [$rawId]);
            } elseif ((int) $rawId > 0) {
                $id = (int) $rawId;
            }
            // Un «0» es lo que el flujo de siempre entiende como «este es nuevo»: se deja en null.
        }

        // ----- El código -----
        $code    = null;
        $rawCode = csv_read_text_cell(trim($cells[self::COL_BARCODE] ?? ''));

        if (csv_looks_like_scientific_notation($rawCode)) {
            // Nunca se intenta rescatar: `7,70203E+12` ya perdió los dígitos y guardarlo dejaría un
            // artículo que no se puede encontrar ni con el lector ni tecleando.
            $errors[] = lang('Items.csv_row_barcode_broken', [$rawCode]);
        } elseif ($rawCode !== '') {
            $code                  = $rawCode;
            $fields['item_number'] = $rawCode;
        }

        // ----- Texto: vacío = no cambiar -----
        foreach ([
            self::COL_NAME        => 'name',
            self::COL_CATEGORY    => 'category',
            self::COL_DESCRIPTION => 'description',
            self::COL_IMAGE       => 'pic_filename',
            self::COL_HSN         => 'hsn_code',
        ] as $column => $field) {
            $value = trim($cells[$column] ?? '');

            if ($value !== '') {
                $fields[$field] = $value;
            }
        }

        // ----- La unidad de medida -----
        $rawUnit = trim($cells[self::COL_UNIT] ?? '');

        if ($rawUnit !== '') {
            $fields['unit_of_measure'] = Item::normalize_unit_of_measure($rawUnit);

            if ($fields['unit_of_measure'] !== strtolower($rawUnit)) {
                // No tumba la fila --el campo es opcional por diseño-- pero un negocio que quiso decir
                // «kg» y escribió otra cosa tiene un error de precio, así que queda escrito.
                log_message('error', "Carga masiva: unidad de medida no reconocida '$rawUnit' en la fila $line; el artículo queda en '" . Item::UNIT_OF_MEASURE_UNIT . "'.");
            }
        }

        // ----- Números: ambiguo = error, nunca una interpretación -----
        foreach ([
            self::COL_COST    => 'cost_price',
            self::COL_PRICE   => 'unit_price',
            self::COL_REORDER => 'reorder_level',
        ] as $column => $field) {
            $raw = trim($cells[$column] ?? '');

            if ($raw === '') {
                continue;
            }

            $value = self::readNumber($raw, true);

            if ($value === null) {
                $errors[] = lang('Items.csv_row_number_ambiguous', [$raw, $column]);

                continue;
            }

            $fields[$field] = $value;
        }

        // ----- Booleanos -----
        foreach ([
            self::COL_ALT_DESC   => 'allow_alt_description',
            self::COL_SERIALIZED => 'is_serialized',
        ] as $column => $field) {
            $raw = trim($cells[$column] ?? '');

            if ($raw === '') {
                continue;
            }

            if ($raw !== '0' && $raw !== '1') {
                $errors[] = lang('Items.csv_row_boolean_unclear', [$raw, $column]);

                continue;
            }

            $fields[$field] = $raw;
        }

        // ----- El proveedor -----
        $rawSupplier = trim($cells[self::COL_SUPPLIER] ?? '');

        if ($rawSupplier !== '') {
            if (ctype_digit($rawSupplier) && $this->supplierIsReal((int) $rawSupplier)) {
                $fields['supplier_id'] = (int) $rawSupplier;
            } else {
                // Se omite la clave --o sea, no se cambia-- en vez de escribir null, que borraría el
                // proveedor que el artículo ya tenía. Es la misma regla de la celda vacía: ante una
                // duda, no se destruye. Queda en el registro porque es un dato que el cliente creía
                // estar poniendo.
                log_message('error', "Carga masiva: proveedor '$rawSupplier' no existe (fila $line); ese artículo conserva el proveedor que tenía.");
            }
        }

        // Antes del return y no dentro de él: `parseTaxes()` añade errores por referencia, y depender
        // del orden en que PHP evalúa los elementos de un arreglo es una trampa para el que venga.
        $taxes = $this->parseTaxes($cells, $errors);

        return [
            'line'   => $line,
            'id'     => $id,
            'code'   => $code,
            'fields' => $fields,
            'taxes'  => $taxes,
            'errors' => $errors,
        ];
    }

    /**
     * Los dos impuestos que caben en el archivo.
     *
     * Devuelve `null` --«no se declaró nada»-- cuando las dos casillas de nombre vienen vacías, que es
     * la regla de la celda vacía aplicada a los impuestos: un archivo con solo la columna de precios
     * no puede dejar sin IVA a 1.184 artículos.
     *
     * @param array<string, string> $cells
     * @param list<string>          $errors se le añaden los errores encontrados
     * @return ?list<array{name: string, percent: string}>
     */
    private function parseTaxes(array $cells, array &$errors): ?array
    {
        $declared = [];
        $anyName  = false;

        foreach ([[self::COL_TAX1_NAME, self::COL_TAX1_PCT], [self::COL_TAX2_NAME, self::COL_TAX2_PCT]] as [$nameColumn, $percentColumn]) {
            $name    = trim($cells[$nameColumn] ?? '');
            $percent = trim($cells[$percentColumn] ?? '');

            if ($name === '') {
                continue;
            }

            $anyName = true;

            if ($percent === '') {
                continue;    // Un impuesto sin porcentaje no es un impuesto. Igual que el flujo viejo.
            }

            // UN PORCENTAJE NO SE AGRUPA NUNCA, Y POR ESO AQUÍ LA REGLA ES LA OTRA
            //
            // A las columnas de dinero se les exige que no sean ambiguas porque `1.000` puede ser mil
            // pesos o un peso, y las dos lecturas son creíbles. En un porcentaje no: un impuesto del
            // 19.000% no existe, y nadie escribe una tasa con separador de miles. Es exactamente el
            // razonamiento de `Sale_lib::normalize_weight_input()` --«a weight is a single number and
            // never needs digit grouping»-- aplicado a la otra magnitud que tampoco se agrupa.
            //
            // Y tiene una consecuencia práctica que vale el matiz: `items_taxes.percent` es
            // decimal(15,3), así que un 19% guardado vuelve del motor como `19.000`. Con la regla
            // estricta, el viaje de ida y vuelta fallaría en TODAS las filas con impuesto.
            $value = self::readNumber($percent, false);

            if ($value === null) {
                $errors[] = lang('Items.csv_row_number_ambiguous', [$percent, $percentColumn]);

                continue;
            }

            $declared[] = ['name' => $name, 'percent' => $value];
        }

        if (! $anyName) {
            return null;
        }

        return $declared;
    }

    /**
     * Lee un número de una celda, o `null` si no se puede leer sin adivinar.
     *
     * POR QUÉ NO `parse_decimals()`
     *
     * Ese helper pasa el texto por `NumberFormatter` con el `number_locale` del negocio, y en `es_CO`
     * el punto es el separador de MILES: lee `1.000` como 1. Un artículo que valía mil pesos pasa a
     * valer uno, sin un solo aviso, en las filas que el cliente no revisó. Es el mismo fallo que
     * obligó a escribir `Sale_lib::normalize_weight_input()` para la balanza.
     *
     * LA REGLA, Y POR QUÉ SE RECHAZA EN VEZ DE ELEGIR
     *
     * `1.000` puede ser mil (agrupado) o uno (decimal) y **las dos lecturas son creíbles**. Elegir una
     * es apostarse el precio del artículo. Así que exactamente esa forma --un separador seguido de
     * exactamente tres dígitos-- se rechaza y se le pide al cliente que lo escriba sin dudas.
     *
     * LA EXCEPCIÓN DEL CERO NO ES UN PARCHE
     *
     * Si lo que hay antes del separador es todo ceros, la agrupación no es una lectura posible: ningún
     * idioma escribe `0.735` para decir setecientos treinta y cinco. Así que `0.000` es cero y `0.735`
     * es cero coma setecientos treinta y cinco, sin ambigüedad ninguna. Sin esta excepción, un
     * `reorder_level` exportado por nosotros --decimal(15,3), o sea `0.000`-- rompería el viaje de ida
     * y vuelta en todas las filas.
     *
     * @param bool $rejectGrouping false para las magnitudes que nunca se agrupan (ver `parseTaxes()`)
     */
    private static function readNumber(string $raw, bool $rejectGrouping): ?string
    {
        $candidate = trim($raw);
        $sign      = '';

        if (str_starts_with($candidate, '-')) {
            // Un menos es un menos: la exportación escribe las columnas numéricas sin neutralizar
            // precisamente porque ahí un `-5` es un menos cinco y no una fórmula.
            $sign      = '-';
            $candidate = substr($candidate, 1);
        }

        if (preg_match('/^(\d*)(?:([.,])(\d+))?$/', $candidate, $matches) !== 1) {
            return null;
        }

        $whole = $matches[1];
        $sep   = $matches[2] ?? '';
        $frac  = $matches[3] ?? '';

        if ($whole === '' && $frac === '') {
            return null;
        }

        if ($rejectGrouping && $sep !== '' && strlen($frac) === 3 && ltrim($whole, '0') !== '') {
            return null;
        }

        if ($whole === '') {
            $whole = '0';    // `.5` es lo que produce un teclado numérico a medio pensar.
        }

        return $sign . $whole . ($sep === '' ? '' : '.' . $frac);
    }

    // =================================================================================================
    // Decidir qué le pasa a una fila
    // =================================================================================================

    /**
     * Los artículos vivos y normales de un lote de `Id`, en una sola consulta.
     *
     * La Fase 0 dejó `resolve_item_numbers()` para los códigos pero nada para los `Id`, y hacen falta
     * los dos: el archivo que exportamos trae el `Id` lleno en las 1.184 filas, así que preguntar por
     * fila serían 1.184 consultas en el paso que promete no escribir nada.
     *
     * Se lee por la API pública del modelo --sin tocar `app/Models/`-- y **sin excluir los kits**: hay
     * que poder distinguir «ese Id no existe» de «ese Id es un kit», que son dos mensajes distintos.
     * Los borrados sí quedan fuera, por lo mismo que en `resolve_item_numbers()`: emparejar contra uno
     * lo reviviría sin que nadie lo pidiera.
     *
     * @param list<int> $ids
     * @return array<int, array{item_id: int, item_type: int, item_number: string, name: string}>
     */
    private function itemsById(array $ids): array
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        $rows = $this->item
            ->select('item_id, item_type, item_number, name')
            ->whereIn('item_id', $ids)
            ->where('deleted', 0)
            ->findAll();

        $found = [];

        foreach ($rows as $row) {
            // El `returnType` del modelo no está declarado, así que se acepta lo que venga.
            $row = (array) $row;

            $found[(int) $row['item_id']] = [
                'item_id'     => (int) $row['item_id'],
                'item_type'   => (int) $row['item_type'],
                'item_number' => (string) ($row['item_number'] ?? ''),
                'name'        => (string) ($row['name'] ?? ''),
            ];
        }

        return $found;
    }

    /**
     * ¿Esta fila crea, actualiza, o es un error?
     *
     * EL ORDEN ES EL DE `docs/Tecnico/carga-masiva-de-articulos.md` §4.2 Y NO ES ARBITRARIO
     *
     * El `Id` manda sobre el código, que es la regla OPUESTA a la de la búsqueda del punto de venta.
     * No es una incoherencia: allí se resuelve lo que teclea un cajero, y lo que teclea es el código
     * impreso; aquí se resuelve un archivo que salió de nosotros, donde el `Id` es la identificación
     * exacta y el código es la humana.
     *
     * @param array<string, mixed>          $row
     * @param array<string, list<object>>   $byCode
     * @param array<int, array>             $byId
     * @param array<string, int>            $timesInFile
     * @return array{item_id: ?int, label: string, stored_number: string, fields: array<string, mixed>, errors: list<string>}
     */
    private function decideRow(array $row, array $byCode, array $byId, array $timesInFile): array
    {
        $decision = [
            'item_id'       => null,
            'label'         => '',
            'stored_number' => '',
            'fields'        => $row['fields'],
            'errors'        => $row['errors'],
        ];

        if ($decision['errors'] !== []) {
            return $decision;
        }

        $code = $row['code'];

        if ($code !== null && ($timesInFile[$code] ?? 0) > 1) {
            // Dos filas del mismo archivo apuntando al mismo código: creando, duplicarían el artículo;
            // actualizando, la segunda pisaría a la primera sin decirlo. Se marcan LAS DOS: elegir una
            // ganadora sería adivinar cuál de las dos escribió el cliente por error.
            $decision['errors'][] = lang('Items.csv_row_code_twice_in_file', [$code]);

            return $decision;
        }

        // ----- 1. Viene Id: manda el Id -----
        if ($row['id'] !== null) {
            $stored = $byId[$row['id']] ?? null;

            if ($stored === null) {
                $decision['errors'][] = lang('Items.csv_row_id_unknown', [$row['id']]);

                return $decision;
            }

            if ($stored['item_type'] !== ITEM) {
                $decision['errors'][] = lang('Items.csv_row_not_a_plain_item', [$stored['item_number'] !== '' ? $stored['item_number'] : (string) $row['id']]);

                return $decision;
            }

            $decision['item_id']       = $stored['item_id'];
            $decision['label']         = $decision['fields']['name'] ?? $stored['name'];
            $decision['stored_number'] = $stored['item_number'];

            return $decision;
        }

        // ----- 2. No viene Id pero sí código -----
        if ($code !== null) {
            $matches = $byCode[$code] ?? [];

            if (count($matches) > 1) {
                // D6. Nunca se adivina cuál: actualizar el artículo equivocado es un síntoma que tarda
                // meses en verse.
                $decision['errors'][] = lang('Items.csv_row_code_repeated', [$code]);

                return $decision;
            }

            if (count($matches) === 1) {
                $stored = (array) $matches[0];

                if ((int) $stored['item_type'] !== ITEM) {
                    $decision['errors'][] = lang('Items.csv_row_not_a_plain_item', [$code]);

                    return $decision;
                }

                $decision['item_id']       = (int) $stored['item_id'];
                $decision['label']         = $decision['fields']['name'] ?? (string) $stored['name'];
                $decision['stored_number'] = $code;

                return $decision;
            }
        }

        // ----- 3. Ni Id ni código conocidos: se crea -----
        foreach ([self::COL_NAME => 'name', self::COL_CATEGORY => 'category', self::COL_PRICE => 'unit_price'] as $column => $field) {
            if (! isset($decision['fields'][$field])) {
                $decision['errors'][] = lang('Items.csv_row_missing_required', [$column]);
            }
        }

        if ($decision['errors'] !== []) {
            return $decision;
        }

        $decision['fields'] = self::withInsertDefaults($decision['fields']);
        $decision['label']  = (string) $decision['fields']['name'];

        return $decision;
    }

    /**
     * Los valores que un alta tiene que escribir aunque la celda venga vacía.
     *
     * EN UN ALTA, «NO CAMBIAR» NO SIGNIFICA NADA
     *
     * Omitir la clave es la regla correcta en un `update`: la columna se queda como estaba. En un
     * `insert` no hay nada que quedarse: `name`, `category`, `description`, `cost_price`, `unit_price`,
     * `allow_alt_description` e `is_serialized` son `NOT NULL` **sin DEFAULT**, y hoy un insert
     * incompleto solo funciona porque `strictOn = false` deja que MariaDB rellene con un aviso.
     *
     * Eso es apoyarse en una línea de configuración que un día alguien pondrá en `true` --es lo
     * recomendado-- y ese día el alta empieza a fallar. Aquí se escriben los valores.
     *
     * Los tres obligatorios (`name`, `category`, `unit_price`) no aparecen: si faltaran, la fila ya se
     * habría rechazado antes de llegar aquí.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function withInsertDefaults(array $fields): array
    {
        return $fields + [
            'description'           => '',
            'cost_price'            => '0',
            'allow_alt_description' => '0',
            'is_serialized'         => '0',
            // La columna trae DEFAULT 0, que es ITEM. Se escribe igual para decir lo que se quiere:
            // esta pantalla crea artículos normales y nunca kits ni temporales.
            'item_type'             => ITEM,
        ];
    }

    // =================================================================================================
    // Los impuestos
    // =================================================================================================

    /**
     * Cierra la decisión de impuestos de todas las filas, con UNA lectura de lo guardado.
     *
     * @param array{to_create: list<array>, to_update: list<array>, errors: list<array>, warnings: list<array>} $plan
     */
    private function resolveTaxes(array &$plan): void
    {
        $ids = array_map(static fn (array $entry): int => (int) $entry['item_id'], $plan['to_update']);

        $stored = $this->itemTaxes->get_info_bulk($ids);

        foreach ($plan['to_update'] as $index => $entry) {
            $declared = $entry['taxes'];

            if ($declared === null) {
                continue;    // El archivo no dijo nada de impuestos: no se tocan (D4).
            }

            $current = $stored[(int) $entry['item_id']] ?? [];

            if (count($current) > 2) {
                // EL TERCER IMPUESTO
                //
                // `Item_taxes::save_value()` borra y reinserta, así que escribir el par del archivo
                // dejaría al artículo con dos. Y el archivo NO PUEDE representar el tercero: solo tiene
                // dos columnas. Así que a un artículo con más de dos impuestos no se le tocan los
                // impuestos nunca --ni para confirmarlos-- y se dice en la vista previa.
                $plan['to_update'][$index]['taxes'] = null;

                if (! self::sameTaxes($declared, array_slice($current, 0, 2))) {
                    $plan['warnings'][] = ['line' => (int) $entry['line'], 'message' => lang('Items.bulk_taxes_not_touched')];
                }

                continue;
            }

            if (self::sameTaxes($declared, $current)) {
                // El 99% de las filas de un viaje de ida y vuelta. Ni el borrado se escribe.
                $plan['to_update'][$index]['taxes'] = null;
            }
        }
    }

    /**
     * ¿Estos dos juegos de impuestos dicen lo mismo?
     *
     * Sin mirar el orden --la clave primaria de `items_taxes` es (artículo, nombre, porcentaje), así
     * que el orden no significa nada-- y comparando el porcentaje por su valor y no por su texto: lo
     * guardado vuelve del motor como `19.000` y el archivo puede traer `19`.
     *
     * @param list<array{name: string, percent: string}> $a
     * @param list<array{name: string, percent: string}> $b
     */
    private static function sameTaxes(array $a, array $b): bool
    {
        $normalise = static function (array $taxes): array {
            $out = [];

            foreach ($taxes as $tax) {
                $out[] = strtolower(trim((string) $tax['name'])) . '=' . number_format((float) $tax['percent'], 3, '.', '');
            }

            sort($out);

            return $out;
        };

        return $normalise($a) === $normalise($b);
    }

    // =================================================================================================
    // Escribir
    // =================================================================================================

    /**
     * @param array<string, mixed> $entry
     * @param array<int, string>   $locations
     */
    private function insertItem(array $entry, array $locations): ?int
    {
        $fields = $entry['fields'];

        if (! $this->item->save_value($fields, NEW_ENTRY)) {
            return null;
        }

        $itemId = (int) $fields['item_id'];

        // Las existencias del archivo no se aplican, pero un artículo SIN fila de existencias no sale
        // en la grilla filtrada por bodega: quien lo acaba de crear no lo encontraría. Por eso el
        // resultado se comprueba en vez de ignorarse -- un artículo invisible es un artículo perdido.
        foreach (array_keys($locations) as $locationId) {
            $written = $this->itemQuantity->save_value(
                ['item_id' => $itemId, 'location_id' => (int) $locationId, 'quantity' => 0],
                $itemId,
                (int) $locationId,
            );

            if (! $written) {
                return null;
            }
        }

        return $this->writeTaxes($entry, $itemId) ? $itemId : null;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function updateItem(array $entry): bool
    {
        $itemId = (int) $entry['item_id'];
        $fields = $entry['fields'];

        if ($fields !== [] && ! $this->item->save_value($fields, $itemId)) {
            return false;
        }

        return $this->writeTaxes($entry, $itemId);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function writeTaxes(array $entry, int $itemId): bool
    {
        $taxes = $entry['taxes'] ?? null;

        if ($taxes === null || $taxes === []) {
            return true;
        }

        return $this->itemTaxes->save_value($taxes, $itemId);
    }
}
