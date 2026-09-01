<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Platform_support;
use App\Libraries\TenantProvisioner;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Crea el empleado de soporte de la plataforma en los negocios que YA existen.
 *
 * POR QUÉ HACE FALTA UN COMANDO
 *
 * El alta de un negocio nuevo ya lo crea sola (TenantProvisioner::create()). Pero los negocios que
 * están vivos hoy --Casaletto, que está vendiendo, y Paraíso-- nacieron antes de que esto existiera,
 * así que no lo tienen y nadie va a volver a darlos de alta. Sin esta fila no podemos entrar a
 * gestionarlos, que es justo lo que la Entrega 4 promete.
 *
 * SE PUEDE CORRER LAS VECES QUE HAGA FALTA
 *
 * Es la propiedad que lo hace utilizable: se corre después de cada despliegue si se quiere, y en el
 * negocio que ya lo tiene no escribe nada. Lo único que hace sobre un empleado que ya está es
 * completarle los permisos que le falten --los que añadió una migración posterior, o los tres que
 * crea cada ubicación de existencias nueva-- y decirlo.
 *
 * NO VA EN EL ARRANQUE, Y ES A PROPÓSITO
 *
 * `docker/entrypoint.sh` corre las migraciones de todos los negocios y hace `exit 1` si una sola
 * falla, con lo que Apache no levanta y el punto de venta se queda abajo. Encadenar esto ahí le
 * daría a una función nuestra --entrar a dar soporte-- el poder de tumbar la caja del cliente. Se
 * corre a mano, por SSH, cuando toque.
 *
 * SOBRE LOS CÓDIGOS DE SALIDA
 *
 * 0 significa «todos los negocios que se pidieron tienen su empleado de soporte», incluidos los que
 * ya lo tenían. 1 es «alguno no lo tiene», y entonces se nombra: quien encadene esto detrás de un
 * `&&` tiene que poder confiar en `$?`, que es exactamente lo que el `migrate` de serie no permite
 * (traga cualquier excepción y siempre sale con 0 -- ver la cabecera de PlatformMigrate.php).
 *
 * Un negocio que falla NO detiene a los demás: se anota, se sigue, y al final se dice cuáles
 * quedaron pendientes. Pararse en el primero dejaría el resto sin hacer por un problema que puede
 * ser de uno solo.
 */
class PlatformSupportEmployee extends BaseCommand
{
    protected $group       = 'Platform';
    protected $name        = 'platform:support-employee';
    protected $description = 'Crea el empleado de soporte de la plataforma en los negocios que ya existen. Idempotente. Sale con 1 si algún negocio quedó sin él.';
    protected $usage       = 'platform:support-employee [slug]';
    protected $arguments   = [
        'slug' => 'Opcional: hacer un solo negocio. Sin él, se recorren todos los negocios activos del registro.',
    ];

    public function run(array $params)
    {
        $slug = trim((string) ($params[0] ?? ''));

        try {
            $negocios = $this->negocios($slug);
        } catch (Throwable $e) {
            CLI::error('No se pudo leer el registro de negocios: ' . $e->getMessage());

            return 1;
        }

        if ($slug !== '' && $negocios === []) {
            CLI::error("No hay ningún negocio con el slug «{$slug}».");

            return 1;
        }

        if ($negocios === []) {
            // El registro contestó y no tiene negocios activos: una instalación de un solo negocio
            // (desarrollo local, o un entorno anterior al multi-negocio). No hay nada que hacer y no
            // hay nada roto, así que se sale con 0 en vez de mandar a alguien a buscar un problema.
            CLI::write('El registro no tiene negocios activos. No hay nada que hacer.', 'yellow');

            return 0;
        }

        CLI::write('Empleado de soporte «' . Platform_support::USERNAME . '» en ' . count($negocios) . ' negocio(s):');

        $provisioner = new TenantProvisioner();
        $fallos      = [];
        $creados     = 0;

        foreach ($negocios as $negocio) {
            $suSlug = (string) $negocio['slug'];

            if ($negocio['status'] !== 'active') {
                // Solo puede pasar cuando se pidió un slug concreto: la lista sin slug ya viene
                // filtrada. Un negocio suspendido sigue siendo nuestro y su base sigue ahí, así que
                // se hace igual -- pero se dice, porque quien lo corra puede haberse equivocado de
                // negocio.
                CLI::write("  {$suSlug}: está «{$negocio['status']}», no activo. Se hace igual.", 'yellow');
            }

            try {
                $resultado = $provisioner->ensurePlatformSupportEmployee($suSlug);
            } catch (Throwable $e) {
                CLI::error("  {$suSlug}: " . $e->getMessage());
                $fallos[] = $suSlug;

                continue;
            }

            if ($resultado['created']) {
                CLI::write(
                    "  {$suSlug}: creado (person_id {$resultado['person_id']}, {$resultado['grants_added']} permisos).",
                    'green',
                );
                $creados++;

                continue;
            }

            if ($resultado['grants_added'] > 0) {
                CLI::write(
                    "  {$suSlug}: ya estaba; se le completaron {$resultado['grants_added']} permiso(s) que le faltaban.",
                    'yellow',
                );

                continue;
            }

            CLI::write("  {$suSlug}: ya estaba, con todos sus permisos. Sin cambios.");
        }

        CLI::write('');
        CLI::write('Creado en ' . $creados . ' negocio(s) de ' . count($negocios) . '.');

        if ($fallos !== []) {
            CLI::error('Quedaron SIN empleado de soporte (' . count($fallos) . '): ' . implode(', ', $fallos));
            CLI::write('A esos negocios no se puede entrar a dar soporte hasta arreglarlo y volver a correr esto.');

            return 1;
        }

        return 0;
    }

    /**
     * Los negocios sobre los que hay que trabajar, leídos del registro.
     *
     * Sin slug, solo los activos: es la misma condición con la que `tenant:list` alimenta al
     * orquestador de migraciones, y un negocio suspendido no tiene por qué recibir escrituras que
     * nadie pidió. Con slug, se busca sin filtrar por estado --y el bucle avisa si no está activo--
     * porque pedir uno por su nombre es una decisión deliberada de quien lo escribe.
     *
     * @return list<array{slug: string, status: string}>
     */
    private function negocios(string $slug): array
    {
        $builder = db_connect('platform')
            ->table('tenants')
            ->select('slug, status');

        if ($slug !== '') {
            $builder->where('slug', $slug);
        } else {
            $builder->where('status', 'active');
        }

        /** @var list<array{slug: string, status: string}> $filas */
        $filas = $builder->orderBy('slug', 'asc')->get()->getResultArray();

        return $filas;
    }
}
