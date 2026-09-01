<?php

declare(strict_types=1);

namespace Tests\Controllers;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Una sesión que apunta a una cuenta que ya no existe.
 *
 * NO ES UN CASO RARO: ES LO QUE HACE LA PROPIA CONSOLA
 *
 * Esta consola ofrece eliminar superadministradores. Si a alguien se le elimina mientras está
 * dentro, su sesión sigue viva apuntando a una fila que ya no está -- `isLoggedIn()` solo comprueba
 * que la sesión lleve un id, no que ese id tenga cuenta detrás.
 *
 * Sin la guarda, `redirectAfterLogin()` hacía `$account->id` sobre null y devolvía un 500 en
 * `platform/login`, que es la ÚNICA pantalla a la que todo lo demás redirige. Ese navegador quedaba
 * en un bucle sin poder ni ver el formulario para entrar como otra persona: solo se salía borrando
 * la cookie a mano.
 *
 * Encontrado el 2026-09-01 en staging, eliminando una cuenta que tenía la sesión abierta.
 *
 * @internal
 */
final class PlatformLoginStaleSessionTest extends CIUnitTestCase
{
    /**
     * El cuerpo de `redirectAfterLogin()`, que es el único sitio donde importa.
     *
     * Se acota al método y no se busca en todo el archivo: `if ($account === null)` aparece en más
     * de un sitio, y una prueba que se conforme con la primera coincidencia da por buena una guarda
     * que está en otra parte. Ya me pasó al escribirla.
     */
    private function cuerpoDeRedirectAfterLogin(): string
    {
        $fuente = (string) file_get_contents(APPPATH . 'Controllers/PlatformLogin.php');
        $desde  = strpos($fuente, 'private function redirectAfterLogin()');

        $this->assertIsInt($desde, 'El método cambió de nombre; esta prueba ya no mira lo que cree.');

        $hasta = strpos($fuente, 'private function tenantUrl(', (int) $desde);

        return substr($fuente, (int) $desde, ((int) $hasta) - ((int) $desde));
    }

    public function testTheNullAccountIsCheckedBeforeItIsUsed(): void
    {
        $cuerpo = $this->cuerpoDeRedirectAfterLogin();

        $guarda = strpos($cuerpo, 'if ($account === null) {');
        $uso    = strpos($cuerpo, 'getTenantsForAccount((int) $account->id)');

        $this->assertIsInt($guarda, 'Sin la guarda, una cuenta eliminada deja su navegador en un 500.');
        $this->assertIsInt($uso, 'Si ya no se usa la cuenta, esta prueba sobra.');
        $this->assertGreaterThan($guarda, $uso, 'La guarda tiene que ir ANTES de usar la cuenta.');
    }

    public function testTheStaleSessionIsClosedAndNotJustRedirected(): void
    {
        $cuerpo = $this->cuerpoDeRedirectAfterLogin();
        $desde  = (int) strpos($cuerpo, 'if ($account === null) {');

        $this->assertStringContainsString(
            'logout()',
            substr($cuerpo, $desde, 260),
            'Redirigir sin cerrar la sesión deja la cookie apuntando a la nada y repite el problema.',
        );
    }

    public function testTheMessageExistsInBothLanguages(): void
    {
        $es = require APPPATH . 'Language/es-MX/Platform.php';
        $en = require APPPATH . 'Language/en/Platform.php';

        $this->assertArrayHasKey('session_account_gone', $es);
        $this->assertArrayHasKey('session_account_gone', $en);
        $this->assertStringNotContainsString('Platform.', lang('Platform.session_account_gone'));
    }
}
