<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\TenantProvisioner;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Copia al registro el nombre que cada negocio tiene en SU PROPIA configuración.
 *
 * POR QUÉ HACE FALTA
 *
 * `tenants.company_name` solo se rellena al dar de alta, así que los negocios anteriores a la
 * Entrega 3 --Casaletto y Paraíso-- salen en el listado de la consola como «(Sin nombre guardado)»
 * aunque ellos sí sepan cómo se llaman: «Casaletto Anapoima» y «Paraíso de la Canasta» están en su
 * `app_config.company`. Nadie va a volver a darlos de alta, así que sin esto se quedan sin nombre
 * para siempre.
 *
 * RELLENA HUECOS, NO SINCRONIZA
 *
 * Un negocio que ya tenga nombre en el registro se deja como está. Puede haberlo puesto una persona
 * a propósito --para distinguir dos negocios cuyo `company` es el mismo, por ejemplo-- y una copia
 * automática que lo machacara borraría esa decisión sin avisar. Correrlo dos veces no cambia nada la
 * segunda vez.
 *
 * NO ESCRIBE NADA EN LA BASE DEL CLIENTE
 *
 * Del negocio solo se LEE `app_config.company`. Lo único que se escribe es una columna de
 * `platform_control`.
 *
 * SOBRE LOS CÓDIGOS DE SALIDA
 *
 * 0 si todo lo que se pidió quedó resuelto --incluidos los que ya tenían nombre y los que no lo
 * tienen ni siquiera en su propia configuración--. 1 si alguno falló, y entonces se nombra. Un
 * negocio que falla no detiene a los demás: quedarse a medias por un problema de uno solo obligaría
 * a repetirlo todo.
 */
class PlatformFillCompanyNames extends BaseCommand
{
    protected $group       = 'Platform';
    protected $name        = 'platform:fill-company-names';
    protected $description = 'Copia al registro el nombre que cada negocio tiene en su propia configuración. No pisa los que ya tienen. Idempotente.';
    protected $usage       = 'platform:fill-company-names [slug]';
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
            CLI::write('El registro no tiene negocios activos. No hay nada que hacer.', 'yellow');

            return 0;
        }

        CLI::write('Nombre en el registro, para ' . count($negocios) . ' negocio(s):');

        $provisioner = new TenantProvisioner();
        $fallos      = [];
        $rellenados  = 0;

        foreach ($negocios as $negocio) {
            $suSlug = (string) $negocio['slug'];

            try {
                $resultado = $provisioner->fillCompanyNameFromBusiness($suSlug);
            } catch (Throwable $e) {
                CLI::error("  {$suSlug}: " . $e->getMessage());
                $fallos[] = $suSlug;

                continue;
            }

            if ($resultado['filled']) {
                CLI::write("  {$suSlug}: «{$resultado['name']}».", 'green');
                $rellenados++;

                continue;
            }

            if ($resultado['name'] !== null) {
                CLI::write("  {$suSlug}: ya tenía «{$resultado['name']}». Sin cambios.");

                continue;
            }

            // Ni el registro ni el negocio saben cómo se llama. No es un fallo: es un negocio al que
            // nadie le ha puesto nombre todavía, y decirlo es más útil que inventarle uno.
            CLI::write("  {$suSlug}: su propia configuración tampoco tiene nombre. Sin cambios.", 'yellow');
        }

        CLI::write('');
        CLI::write('Nombre puesto a ' . $rellenados . ' negocio(s) de ' . count($negocios) . '.');

        if ($fallos !== []) {
            CLI::error('Fallaron (' . count($fallos) . '): ' . implode(', ', $fallos));

            return 1;
        }

        return 0;
    }

    /**
     * @return list<array{slug: string, status: string}>
     */
    private function negocios(string $slug): array
    {
        $builder = db_connect('platform')->table('tenants')->select('slug, status');

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
