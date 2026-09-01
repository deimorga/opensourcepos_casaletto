<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Events\Events;

/**
 * Cuenta las consultas que hace un trozo de código.
 *
 * POR QUÉ ESTO EXISTE
 *
 * Exportar 1.184 artículos leyendo de uno en uno son ~4.700 consultas. Las lecturas en lote lo bajan
 * a cuatro. Pero «en lote» es una intención que se pierde en el primer refactor si nadie la mide:
 * basta con que alguien meta un `foreach` con una consulta dentro y todo sigue pasando en verde, solo
 * que el servidor se cae con el catálogo de un cliente de verdad.
 *
 * POR QUÉ POR EVENTO Y NO PREGUNTÁNDOLE A LA CONEXIÓN
 *
 * `getQueries()` no existe en esta versión de CodeIgniter, y el recolector de la barra de depuración
 * solo se alimenta cuando la barra está activa -- que en pruebas no lo está. El evento `DBQuery`, en
 * cambio, lo dispara `BaseConnection` en TODA consulta, pase lo que pase.
 *
 * Y POR QUÉ EL SUSCRIPTOR NO SE DA DE BAJA
 *
 * Porque no se puede sin llevarse a los demás: `Events::on()` no devuelve ningún asa, y
 * `removeAllListeners('DBQuery')` se llevaría también al recolector de la barra y al registro de
 * consultas que `app/Config/Events.php` engancha ahí. Así que el suscriptor se pone UNA vez y se
 * queda; lo que se mide es la diferencia entre antes y después.
 */
final class CuentaConsultas
{
    private static bool $enganchado = false;
    private static int $total = 0;

    /**
     * Cuántas consultas hace lo que se le pase.
     *
     * @template T
     * @param callable(): T $accion
     * @return array{consultas: int, resultado: T}
     */
    public static function medir(callable $accion): array
    {
        self::enganchar();

        $antes     = self::$total;
        $resultado = $accion();

        return ['consultas' => self::$total - $antes, 'resultado' => $resultado];
    }

    /** Azúcar para el caso habitual: solo interesa el número. */
    public static function contar(callable $accion): int
    {
        return self::medir($accion)['consultas'];
    }

    private static function enganchar(): void
    {
        if (self::$enganchado) {
            return;
        }

        Events::on('DBQuery', static function (): void {
            self::$total++;
        });

        self::$enganchado = true;
    }
}
