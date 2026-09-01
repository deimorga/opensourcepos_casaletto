<?php

declare(strict_types=1);

namespace Tests\Language;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Que la consola hable en español también cuando se está quejando.
 *
 * `TenantProvisioner` lanza `RuntimeException` y la consola pinta ese texto tal cual en el aviso
 * rojo. Estuvieron en inglés dentro de una consola en español hasta el 2026-09-01, y se vio en
 * producción: al restablecer con un usuario que no existía, el operador leyó «Business 'casaletto'
 * has no employee with the username 'admin'».
 *
 * El idioma de la aplicación es es-MX. Una clave que exista solo en inglés no da error: sale el
 * nombre crudo de la clave en pantalla, que es peor que el inglés.
 *
 * @internal
 */
final class PlatformErrorStringsTest extends CIUnitTestCase
{
    /** @return array<string, string> */
    private function claves(string $idioma): array
    {
        $todas = require APPPATH . 'Language/' . $idioma . '/Platform.php';

        return array_filter(
            $todas,
            static fn (string $clave): bool => str_starts_with($clave, 'error_'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public function testTheTwoLanguagesCarryTheSameErrorKeys(): void
    {
        $es = array_keys($this->claves('es-MX'));
        $en = array_keys($this->claves('en'));

        sort($es);
        sort($en);

        $this->assertSame($en, $es, 'Una clave que falte en es-MX sale como "Platform.loquesea" en pantalla.');
    }

    public function testThereAreErrorKeysToCheck(): void
    {
        $this->assertNotSame([], $this->claves('es-MX'), 'Si no hay claves, esta prueba no prueba nada.');
    }

    public function testEveryErrorStringResolvesInTheLanguageTheApplicationRunsIn(): void
    {
        foreach (array_keys($this->claves('es-MX')) as $clave) {
            $texto = lang('Platform.' . $clave, ['uno', 'dos']);

            $this->assertStringNotContainsString('Platform.', $texto, $clave . ' no se resuelve');
            $this->assertNotSame('', trim($texto), $clave . ' está vacía');
        }
    }

    /**
     * Los mensajes que la consola muestra llevan el slug o el nombre de la base, y sin ellos no se
     * puede actuar: «no se pudo crear» sin decir cuál no sirve de nada.
     */
    public function testTheMessagesThatNameSomethingActuallyInterpolateIt(): void
    {
        $conHueco = [
            'error_slug_invalid',
            'error_slug_reserved',
            'error_slug_taken',
            'error_tenant_not_found',
            'error_username_not_found',
            'error_password_not_written',
            'error_delete_adopted',
        ];

        foreach ($conHueco as $clave) {
            $this->assertStringContainsString(
                'zanahoria',
                lang('Platform.' . $clave, ['zanahoria', 'zanahoria']),
                $clave . ' no dice de qué negocio habla',
            );
        }
    }

    /**
     * `TenantProvisioner` no puede volver a lanzar un mensaje escrito a mano en los caminos que
     * llegan a la pantalla. `adopt()` es la excepción declarada: se corre por terminal, no tiene
     * pantalla, y sus mensajes se quedan en inglés a propósito.
     */
    public function testTheProvisionerDoesNotThrowHandWrittenTextOnTheScreenPaths(): void
    {
        $fuente = (string) file_get_contents(APPPATH . 'Libraries/TenantProvisioner.php');
        $desde  = strpos($fuente, 'public function adopt(');
        $hasta  = strpos($fuente, 'public function resetAdminPassword(');

        $this->assertIsInt($desde);
        $this->assertIsInt($hasta);

        // Todo el archivo menos el bloque de adopción, que va de adopt() a la siguiente función
        // pública que sí tiene pantalla.
        $sinAdopcion = substr($fuente, 0, (int) $desde) . substr($fuente, (int) $hasta);

        // La única excepción, y va con nombre para que sea una decisión y no un hueco: setStatus()
        // solo recibe literales desde el controlador ('suspended' / 'active'), así que un estado
        // inválido es un error de programación que no puede llegar por pantalla.
        $sinAdopcion = str_replace(
            'throw new RuntimeException("Invalid status \'{$status}\'.");',
            '',
            $sinAdopcion,
        );

        preg_match_all('/throw new RuntimeException\(\s*[\'"]/', $sinAdopcion, $encontrados);

        $this->assertSame(
            [],
            $encontrados[0],
            'Un mensaje escrito a mano en un camino con pantalla. Debe salir de lang(\'Platform.error_*\').',
        );
    }
}
