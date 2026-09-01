<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\Attribute;
use App\Models\Item;
use App\Models\Item_quantity;
use App\Models\Item_taxes;
use App\Models\Stock_location;
use Throwable;

/**
 * La descarga del catálogo: el mismo archivo que la importación sabe leer, pero lleno.
 *
 * LO QUE ESTA CLASE RESUELVE, Y POR QUÉ CADA COSA
 *
 * 1. **Las cabeceras salen de `import_items_csv_columns()` y de ningún otro sitio.** Si esta clase
 *    construyera su propia lista, un día dejarían de encajar y la exportación produciría un archivo
 *    que la importación ya no sabe leer -- que es el fallo que todo este trabajo vino a arreglar.
 *
 * 2. **Cuatro consultas por lote, no cuatro mil.** Leer impuestos, existencias y atributos artículo
 *    por artículo son ~4.700 consultas con 1.184 artículos. Para eso están
 *    `Item_taxes::get_info_bulk()`, `Item_quantity::get_quantities_bulk()` y
 *    `Attribute::get_attribute_values_bulk()`, escritas en la Fase 0.
 *
 * 3. **Kits, entradas por monto y temporales quedan fuera.** `Item::get_all_for_export()` ya filtra.
 *    Los TEMP los crea el punto de venta al cobrar algo suelto: sacarlos y reimportarlos los resucita.
 *
 * 4. **Los códigos tienen que sobrevivir a Excel.** `csv_text_cell()` en la columna del código, y
 *    **solo** ahí. Ver su docblock: envolver de más es inyección CSV.
 *
 * 5. **Y el texto no puede convertirse en fórmula.** Las columnas de texto pasan por
 *    `csv_neutralise_formula()`. Las numéricas NO: ahí un `-5` es un menos cinco.
 *
 * 6. **Los booleanos salen como `0` o `1`, nunca vacíos.** La importación lee «vacío» como «no», así
 *    que un booleano exportado en blanco vuelve cambiado.
 *
 * 7. **Las existencias salen llenas pero son de solo lectura** (decisión del dueño, 2026-09-01). El
 *    camino nuevo las ignora al subir; están para que el cliente las consulte.
 *
 * POR QUÉ EL ESCAPADO SE ESCRIBE AQUÍ Y NO CON `fputcsv()`
 *
 * Medido, no supuesto. Los dos lectores del sistema --`get_csv_file()` y `read_items_csv_file()`--
 * llaman a `fgetcsv()` con el carácter de escape que PHP trae por omisión, la barra invertida. Con
 * ese lector delante, `fputcsv()` tiene un fallo peor que el de escribir a mano: un valor que TERMINA
 * en barra invertida sale entrecomillado como `"...barra\"`, el lector toma `\"` como comilla
 * escapada, el campo no se cierra nunca y **se traga el resto del archivo**. Escribiendo a mano
 * (RFC 4180) ese mismo valor no lleva comillas y vuelve intacto; lo único que se pierde es una barra
 * invertida dentro de un campo que además lleve coma o comilla, que degrada UNA celda en vez de
 * romper el archivo entero. Además `fputcsv()` sin `$escape` explícito está en desuso desde PHP 8.4,
 * y la integración corre en 8.4.
 *
 * LA CABECERA SE ESCRIBE LITERAL, NO POR EL ESCAPADOR
 *
 * `generate_csv_header_line()` entrecomilla de forma irregular a propósito --las columnas fijas solo
 * si llevan un espacio, las de bodega y atributo siempre-- porque hay clientes con copias llenas de
 * la plantilla vieja. Pasarla por un escapador uniforme cambiaría esos bytes.
 */
final class Item_export_lib
{
    /**
     * Cuántos artículos se leen de una vez.
     *
     * Es público porque forma parte del contrato de rendimiento: la prueba que impide que esto vuelva
     * a ser una consulta por artículo calcula el presupuesto exacto a partir de aquí. Doscientas filas
     * son unos 35 KB en memoria, y con las 1.184 de Paraíso son seis vueltas.
     */
    public const BATCH_SIZE = 200;

    /**
     * Fin de línea del archivo.
     *
     * `\n` y no `\r\n`: es lo que escribe todo lo demás del sistema, `fgetcsv()` acepta las dos, y
     * Excel también. Mezclarlas sería lo único de verdad problemático.
     */
    private const LINE_END = "\n";

    private Item $item;
    private Item_taxes $item_taxes;
    private Item_quantity $item_quantity;
    private Attribute $attribute;
    private Stock_location $stock_location;

    public function __construct()
    {
        helper('importfile');

        $this->item           = model(Item::class);
        $this->item_taxes     = model(Item_taxes::class);
        $this->item_quantity  = model(Item_quantity::class);
        $this->attribute      = model(Attribute::class);
        $this->stock_location = model(Stock_location::class);
    }

    /**
     * El catálogo completo como CSV, con BOM, listo para `DownloadResponse`.
     *
     * Devuelve una cadena y no escribe a disco: es lo que ya hace `generate_import_items_csv()` y lo
     * que espera `$this->response->download()`. Con 1.184 filas son unos 200 KB.
     */
    public function toCsv(): string
    {
        // Las mismas dos fuentes que usa la plantilla vacía (`Items::getGenerateCsvFile()`), y por la
        // misma razón: si la descarga trajera columnas de bodegas que quien la pide no puede ver, la
        // importación rechazaría el archivo que nosotros mismos le dimos.
        $stock_locations = $this->stock_location->get_allowed_locations();
        $attributes      = $this->attribute->get_definition_names();

        $columns           = import_items_csv_columns($stock_locations, $attributes);
        $attribute_columns = $this->attribute_columns($attributes);

        $csv = pack('CCC', 0xef, 0xbb, 0xbf)    // El BOM, como la plantilla, o Excel abre las tildes rotas.
            . generate_csv_header_line($stock_locations, $attributes)
            . self::LINE_END;

        $with_extra_taxes = [];
        $offset           = 0;

        while (true) {
            $items = $this->item->get_all_for_export(self::BATCH_SIZE, $offset)->getResult();

            if ($items === []) {
                break;
            }

            $item_ids = array_map(static fn (object $item): int => (int)$item->item_id, $items);

            // Las tres lecturas en lote. Una consulta cada una, para todo el lote.
            $taxes      = $this->item_taxes->get_info_bulk($item_ids);
            $quantities = $this->item_quantity->get_quantities_bulk($item_ids);
            $values     = $this->attribute->get_attribute_values_bulk($item_ids);

            foreach ($items as $item) {
                $item_id = (int)$item->item_id;

                if (count($taxes[$item_id] ?? []) > 2) {
                    $with_extra_taxes[] = $item_id;
                }

                $csv .= $this->line(
                    $columns,
                    $this->cells_for(
                        $item,
                        $stock_locations,
                        $attribute_columns,
                        $taxes[$item_id] ?? [],
                        $quantities[$item_id] ?? [],
                        $values[$item_id] ?? []
                    )
                );
            }

            // Un lote corto es el último. Preguntarlo así ahorra la consulta de conteo, y el caso
            // exacto de un múltiplo de BATCH_SIZE cuesta una consulta que devuelve cero filas.
            if (count($items) < self::BATCH_SIZE) {
                break;
            }

            $offset += self::BATCH_SIZE;
        }

        $this->report_items_with_more_than_two_taxes($with_extra_taxes);

        return $csv;
    }

    /**
     * El nombre del archivo que se le ofrece al cliente.
     *
     * Con la fecha y la hora porque un cliente que corrige precios baja el catálogo varias veces en
     * una tarde, y sin ellas el navegador le deja `items_export (3).csv` y no sabe cuál es cuál.
     *
     * Solo letras, dígitos, guiones y puntos: este nombre acaba en una cabecera `Content-Disposition`,
     * donde una comilla o un salto de línea serían una inyección de cabecera. Aquí no puede haberlos
     * porque nada de fuera entra en la cadena.
     */
    public function fileName(): string
    {
        return 'items_export_' . date('Y-m-d_Hi') . '.csv';
    }

    /**
     * Escribe el catálogo en una ruta. Lo usa el «cómo estaba antes», que se genera justo antes de
     * aplicar los cambios y se guarda junto al archivo subido.
     *
     * @return bool false si no se pudo escribir. Que falle NO puede impedir aplicar los cambios: es
     *              una red, no un requisito. Por eso atrapa hasta lo que no debería pasar --que la
     *              base se caiga a mitad de la lectura-- en vez de dejarlo subir y tumbar la
     *              importación entera del cliente.
     */
    public function writeTo(string $path): bool
    {
        try {
            $written = @file_put_contents($path, $this->toCsv(), LOCK_EX);
        } catch (Throwable $e) {
            log_message('error', 'No se pudo escribir el «cómo estaba antes» en ' . $path . ': ' . $e->getMessage());

            return false;
        }

        if ($written === false) {
            log_message('error', 'No se pudo escribir el «cómo estaba antes» en ' . $path);

            return false;
        }

        @chmod($path, 0640);

        return true;
    }

    /**
     * Las columnas de atributo, por identificador de definición.
     *
     * El `-1` se quita igual que en `import_items_csv_columns()`: ese «[SELECT]» no es una definición
     * sino la opción vacía de un desplegable, y como columna no significaría nada.
     *
     * @param array<int, string> $attributes
     * @return array<int, string> `[definition_id => 'attribute_<nombre>']`
     */
    private function attribute_columns(array $attributes): array
    {
        unset($attributes[-1]);

        $columns = [];

        foreach ($attributes as $definition_id => $attribute_name) {
            $columns[(int)$definition_id] = 'attribute_' . $attribute_name;
        }

        return $columns;
    }

    /**
     * Una fila del archivo, por nombre de columna.
     *
     * Se arma por nombre y no por posición **a propósito**: el orden y el número de celdas los pone
     * `line()` recorriendo la lista de columnas, así que una columna nueva en el helper aparece aquí
     * vacía en vez de correr todos los valores una posición -- que es el fallo silencioso contra el
     * que avisa `import_items_csv_fixed_columns()`.
     *
     * @param array<int, string> $stock_locations   `[location_id => nombre]`
     * @param array<int, string> $attribute_columns `[definition_id => 'attribute_<nombre>']`
     * @param list<array{name: string, percent: string}> $taxes
     * @param array<int, string> $quantities        `[location_id => cantidad]`
     * @param array<int, array{attribute_value: ?string, attribute_decimal: ?string, attribute_date: ?string}> $attribute_values
     * @return array<string, string>
     */
    private function cells_for(
        object $item,
        array $stock_locations,
        array $attribute_columns,
        array $taxes,
        array $quantities,
        array $attribute_values
    ): array {
        $cells = [
            'Id' => (string)$item->item_id,

            // La única columna que se envuelve, y solo si es un número lo bastante largo para que
            // Excel lo destroce. Los 1.184 códigos de Paraíso son EAN de 13 dígitos.
            'Barcode' => csv_text_cell((string)($item->item_number ?? '')),

            'Item Name'   => csv_neutralise_formula((string)$item->name),
            'Category'    => csv_neutralise_formula((string)$item->category),
            'Supplier ID' => $item->supplier_id === null ? '' : (string)$item->supplier_id,

            // Los números salen tal como los guarda la base: sin separador de miles y con punto
            // decimal. Pasarlos por `to_currency()` metería el formato de `number_locale`, y con
            // es_CO el punto es separador de miles: «1.000» puede ser mil o puede ser uno, y la
            // importación tendría que adivinar. Aquí no hay nada que adivinar.
            'Cost Price' => (string)$item->cost_price,
            'Unit Price' => (string)$item->unit_price,

            'Tax 1 Name'    => csv_neutralise_formula($taxes[0]['name'] ?? ''),
            'Tax 1 Percent' => $taxes[0]['percent'] ?? '',
            'Tax 2 Name'    => csv_neutralise_formula($taxes[1]['name'] ?? ''),
            'Tax 2 Percent' => $taxes[1]['percent'] ?? '',

            'Reorder Level' => (string)$item->reorder_level,
            'Description'   => csv_neutralise_formula((string)$item->description),

            // Nunca vacíos. La importación lee «vacío» como «no», así que un booleano en blanco
            // vuelve cambiado, y eso es corrupción del viaje de ida y vuelta.
            'Allow Alt Description'  => (int)$item->allow_alt_description === 0 ? '0' : '1',
            'Item has Serial Number' => (int)$item->is_serialized === 0 ? '0' : '1',

            'Image' => csv_neutralise_formula((string)($item->pic_filename ?? '')),
            'HSN'   => csv_neutralise_formula((string)($item->hsn_code ?? '')),

            // Tal como está guardada, sin normalizar: normalizar aquí convertiría un valor corrupto
            // en un 'unit' de aspecto sano y escondería el problema en vez de enseñarlo.
            'Unit of Measure' => (string)($item->unit_of_measure ?? ''),
        ];

        foreach ($stock_locations as $location_id => $location_name) {
            // Sin fila en `item_quantities` la existencia es cero, no «no sé». Dejarla vacía sería
            // decirle al cliente que no hay dato cuando lo que hay es ninguno.
            $cells['location_' . $location_name] = $quantities[(int)$location_id] ?? '0';
        }

        foreach ($attribute_columns as $definition_id => $column) {
            $cells[$column] = csv_neutralise_formula($this->attribute_value($attribute_values[$definition_id] ?? null));
        }

        return $cells;
    }

    /**
     * El valor de un atributo, sea de la clase que sea.
     *
     * `attribute_values` guarda cada valor en UNA de sus tres columnas según el tipo de la definición
     * --fecha, decimal o texto--, y `saveAttributeValue()` solo escribe esa. Por eso se toma la
     * primera que traiga algo, en vez de gastar una consulta más por exportación en preguntar los
     * tipos: el resultado es el mismo y el presupuesto de consultas no se mueve.
     *
     * La fecha sale como la guarda la base (`Y-m-d`). No se traduce al formato de la configuración
     * porque `saveAttributeValue()` acepta las dos formas, y `Y-m-d` es la única que ninguna hoja de
     * cálculo interpreta al revés.
     *
     * @param array{attribute_value: ?string, attribute_decimal: ?string, attribute_date: ?string}|null $value
     */
    private function attribute_value(?array $value): string
    {
        if ($value === null) {
            return '';
        }

        foreach (['attribute_value', 'attribute_decimal', 'attribute_date'] as $column) {
            if (($value[$column] ?? null) !== null && $value[$column] !== '') {
                return (string)$value[$column];
            }
        }

        return '';
    }

    /**
     * Una línea del archivo: las celdas en el orden exacto de las columnas, escapadas y con su salto.
     *
     * @param list<string> $columns
     * @param array<string, string> $cells
     */
    private function line(array $columns, array $cells): string
    {
        $escaped = [];

        foreach ($columns as $column) {
            $escaped[] = $this->escape_cell($cells[$column] ?? '');
        }

        return implode(',', $escaped) . self::LINE_END;
    }

    /**
     * Una celda escapada según RFC 4180: se entrecomilla solo si hace falta, y la comilla se dobla.
     *
     * Es lo que `fgetcsv()` sabe deshacer, así que un nombre con una coma, una comilla o un salto de
     * línea vuelve idéntico. Y entrecomillar SOLO cuando hace falta no es un ahorro de bytes: es lo
     * que salva al valor que termina en barra invertida de que el lector de PHP, con su escape
     * heredado, se coma el resto del archivo.
     *
     * `strpbrk()` y no una expresión regular: esto se ejecuta una vez por celda, y el catálogo de
     * Paraíso son 1.184 filas por 21 columnas -- veinticinco mil llamadas por descarga.
     */
    private function escape_cell(string $value): string
    {
        if (strpbrk($value, ",\"\r\n") === false) {
            return $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }

    /**
     * Deja constancia de los artículos con más de dos impuestos.
     *
     * El archivo tiene sitio para dos, y no se le puede añadir una columna: los clientes conservan
     * copias llenas de la plantilla y una columna nueva correría en silencio todo lo que ya
     * escribieron. Así que del tercer impuesto no queda rastro EN el archivo, y lo que impide
     * perderlo es la regla del lado de la importación --si el par del archivo coincide con los dos
     * primeros guardados, no se toca nada-- que solo funciona porque estos dos salen siempre en el
     * mismo orden. La lectura en lote ordena por porcentaje y nombre justamente para eso.
     *
     * Queda en el registro para que soporte pueda contestar «¿por qué el archivo no muestra el
     * impuesto de bolsa?» sin abrir la base. Se corta la lista porque un registro de 1.184 números no
     * lo lee nadie.
     *
     * @param list<int> $item_ids
     */
    private function report_items_with_more_than_two_taxes(array $item_ids): void
    {
        if ($item_ids === []) {
            return;
        }

        $shown = array_slice($item_ids, 0, 20);
        $tail  = count($item_ids) > count($shown) ? ', …' : '';

        log_message(
            'info',
            'Exportación de artículos: ' . count($item_ids) . ' artículo(s) tienen más de dos impuestos y el'
            . ' archivo solo lleva los dos primeros. Al reimportarlo sus impuestos no se tocan. Ids: '
            . implode(', ', $shown) . $tail
        );
    }
}
