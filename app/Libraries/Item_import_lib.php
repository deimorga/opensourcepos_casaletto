<?php

declare(strict_types=1);

namespace App\Libraries;

use LogicException;

/**
 * Leer el archivo del cliente, decidir qué haría con cada fila, y --solo si lo aprueban-- hacerlo.
 *
 * ESTO ES UN ESQUELETO. Las firmas están congeladas en la Fase 0. **El cuerpo lo escribe el Carril B.**
 *
 * LAS DOS MITADES ESTÁN SEPARADAS A PROPÓSITO
 *
 * `plan()` no escribe nada. `apply()` no decide nada. Esa separación es lo que hace posible la promesa
 * de la vista previa: lo que se aplica es exactamente lo que se enseñó, porque es el mismo plan.
 * Mezclarlas --validar mientras se escribe, como hace el flujo viejo-- es lo que hoy obliga a meter
 * todo en una transacción y a revertir mil filas buenas por una mala.
 *
 * LO QUE ESTA CLASE TIENE QUE RESOLVER
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
 */
final class Item_import_lib
{
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
     */
    public function plan(string $csvPath): array
    {
        throw new LogicException('Carril B: sin implementar.');
    }

    /**
     * Aplica un plan ya calculado y aprobado.
     *
     * Recibe el plan y no el archivo: lo que se escribe tiene que ser exactamente lo que se enseñó.
     *
     * @param array $plan lo que devolvió `plan()`
     * @return array{created: int, updated: int, failed: list<array{line: int, message: string}>}
     */
    public function apply(array $plan): array
    {
        throw new LogicException('Carril B: sin implementar.');
    }
}
