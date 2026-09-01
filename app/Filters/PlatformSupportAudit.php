<?php

declare(strict_types=1);

namespace App\Filters;

use App\Libraries\Platform_business_entry;
use App\Libraries\TenantContext;
use App\Models\PlatformActivity;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * El registro de nivel 2: qué se modifica dentro del negocio de un cliente durante una sesión de
 * soporte.
 *
 * El registro de nivel 1 --el de la consola-- ya anota lo que hacemos SOBRE un negocio: crearlo,
 * suspenderlo, restablecerle la contraseña. Este anota lo que hacemos DENTRO. Sin él, la consola
 * podría decir «entró soporte» y nada más, y un cliente que pregunte «¿quién me cambió este precio?»
 * no tendría respuesta.
 *
 * SOLO EL QUÉ, NUNCA EL CUERPO
 *
 * Un POST del punto de venta puede llevar una contraseña dentro --`/employees/save` la lleva-- así
 * que el cuerpo NO se registra. Esta tabla la leen personas y se guarda para siempre: meter ahí el
 * cuerpo de una petición sería sembrar credenciales de clientes en nuestra base de control sin que
 * nadie lo decidiera.
 *
 * Se anota la ruta, el método y el desenlace, que es lo que hace falta para responder «qué tocó
 * soporte y cuándo». De los campos enviados solo se recoge una lista corta de identificadores
 * --nunca valores-- para poder señalar SOBRE QUÉ fila se actuó cuando la ruta no lo dice.
 *
 * SE REGISTRA DESPUÉS, Y CON EL ESTADO
 *
 * En `after()`, con el código de respuesta, para que se distinga un cambio hecho de uno rechazado.
 * Un registro que no distinga las dos cosas acusa de cambios que nunca ocurrieron.
 *
 * OBSERVAR NO PUEDE TUMBAR LO OBSERVADO
 *
 * `PlatformActivity::record()` ya se traga sus propios fallos, y aquí se envuelve otra vez: esto
 * corre sobre la caja de un cliente, y ninguna avería del registro puede impedirle cobrar.
 */
class PlatformSupportAudit implements FilterInterface
{
    /**
     * Lo único que se toma del cuerpo. Identificadores, jamás valores.
     *
     * `/employees/delete` no lleva el id en la ruta sino en `ids[]`, y sin esto una baja de empleados
     * quedaría anotada como «alguien hizo POST a /employees/delete» sin decir a quién.
     */
    private const CAMPOS_DE_IDENTIDAD = ['id', 'ids', 'item_id', 'person_id', 'sale_id', 'employee_id'];

    public function before(RequestInterface $request, $arguments = null)
    {
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return;
        }

        if (! Platform_business_entry::isSupportSession()) {
            return;
        }

        try {
            model(PlatformActivity::class)->record(
                PlatformActivity::SUPPORT_WRITE,
                PlatformActivity::TARGET_TENANT,
                TenantContext::slug(),
                [
                    'ruta'   => $this->ruta($request),
                    'estado' => $response->getStatusCode(),
                ] + $this->identificadores($request),
            );
        } catch (\Throwable $e) {
            log_message('error', 'No se pudo registrar una escritura de soporte: ' . $e->getMessage());
        }
    }

    /**
     * La ruta, sin dominio y sin cadena de consulta: lo que identifica la acción. La consulta se
     * descarta porque puede llevar filtros con datos del cliente y no aporta nada al «qué se hizo».
     */
    private function ruta(RequestInterface $request): string
    {
        return '/' . ltrim($request->getUri()->getPath(), '/');
    }

    /**
     * @return array<string, string>
     */
    private function identificadores(RequestInterface $request): array
    {
        $recogidos = [];

        foreach (self::CAMPOS_DE_IDENTIDAD as $campo) {
            $valor = $request->getPost($campo);

            if ($valor === null || $valor === '' || $valor === []) {
                continue;
            }

            // Aplanado y recortado: un array de ids es legítimo, pero nada de lo que venga de fuera
            // entra entero en una columna que después lee una persona.
            $recogidos[$campo] = substr(
                is_array($valor) ? implode(',', array_map('strval', $valor)) : (string) $valor,
                0,
                100,
            );
        }

        return $recogidos;
    }
}
