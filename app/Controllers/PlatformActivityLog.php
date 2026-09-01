<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * El registro de actividad (§6.5 del funcional).
 *
 * Solo lectura, y a propósito: no hay ruta que borre de aquí ni método que edite una fila. Un
 * registro que su propio operador puede recortar deja de ser un registro y pasa a ser una opinión.
 *
 * D6 fija tanto lo que hay como lo que no: se registran las MODIFICACIONES, no los accesos. Este
 * módulo nunca podrá contestar «¿quién entró y cuándo?» -- para la única parte de eso que alguien
 * pidió está la columna «último ingreso» del listado de cuentas -- y siempre podrá contestar «quién
 * cambió qué».
 *
 * El nombre de la clase lo fija la ruta que la Fase 0 ya declaró: `platform/activity` ->
 * `PlatformActivityLog::index`. No se llama `PlatformActivity` porque así se llama el modelo.
 */
class PlatformActivityLog extends Platform_Controller
{
    /**
     * Cuántas filas se traen de una vez. No hay paginación todavía porque el registro empieza hoy
     * con cero filas y lo alimentan una o dos personas: doscientas entradas son meses de consola.
     * Cuando deje de serlo, el índice sobre created_at que dejó la migración es el que sostiene el
     * ORDER BY, y añadir un LIMIT/OFFSET aquí no rompe nada.
     */
    private const HOW_MANY = 200;

    public function index(): string
    {
        return view('platform/admin/activity', [
            'title'   => lang('Platform.activity_title'),
            'nav'     => 'activity',
            'entries' => $this->activity->recent(self::HOW_MANY),
        ]);
    }
}
