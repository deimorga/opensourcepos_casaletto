<?php

declare(strict_types=1);

namespace App\Libraries;

use LogicException;

/**
 * La descarga del catálogo: el mismo archivo que la importación sabe leer, pero lleno.
 *
 * ESTO ES UN ESQUELETO. Las firmas están congeladas en la Fase 0 para que los dos carriles programen
 * contra un contrato y no contra el otro carril. **El cuerpo lo escribe el Carril A.**
 *
 * LO QUE ESTA CLASE TIENE QUE RESOLVER, Y POR QUÉ CADA COSA
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
 * 5. **Y el texto no puede convertirse en fórmula.** Nombre, descripción y categoría pasan por
 *    `csv_neutralise_formula()`. Las columnas numéricas NO: ahí un `-5` es un menos cinco.
 *
 * 6. **Los booleanos salen como `0` o `1`, nunca vacíos.** La importación lee «vacío» como «no», así
 *    que un booleano exportado en blanco vuelve cambiado.
 *
 * 7. **Las existencias salen llenas pero son de solo lectura** (decisión del dueño, 2026-09-01). El
 *    camino nuevo las ignora al subir; están para que el cliente las consulte.
 */
final class Item_export_lib
{
    /**
     * El catálogo completo como CSV, con BOM, listo para `DownloadResponse`.
     *
     * Devuelve una cadena y no escribe a disco: es lo que ya hace `generate_import_items_csv()` y lo
     * que espera `$this->response->download()`. Con 1.184 filas son unos 200 KB.
     */
    public function toCsv(): string
    {
        throw new LogicException('Carril A: sin implementar.');
    }

    /**
     * El nombre del archivo que se le ofrece al cliente.
     */
    public function fileName(): string
    {
        throw new LogicException('Carril A: sin implementar.');
    }

    /**
     * Escribe el catálogo en una ruta. Lo usa el «cómo estaba antes», que se genera justo antes de
     * aplicar los cambios y se guarda junto al archivo subido.
     *
     * @return bool false si no se pudo escribir. Que falle NO puede impedir aplicar los cambios: es
     *              una red, no un requisito.
     */
    public function writeTo(string $path): bool
    {
        throw new LogicException('Carril A: sin implementar.');
    }
}
