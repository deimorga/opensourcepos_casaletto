<?php

declare(strict_types=1);

namespace Tests\Controllers;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Lo que la pantalla de entrada de un negocio PUEDE y NO PUEDE decir.
 *
 * Esta pantalla vive en la dirección pública de un cliente. Cualquiera puede teclear ahí un correo y
 * leer la respuesta, así que lo que responde no puede servir para averiguar quiénes somos.
 *
 * @internal
 */
final class LoginPlatformEntryTest extends CIUnitTestCase
{
    /**
     * NO PUEDE EXISTIR UN MENSAJE DE «CUENTA FRENADA» EN ESTA PANTALLA
     *
     * Enseñarlo confirmaría que ese correo es de un superadministrador nuestro a cualquiera que lo
     * teclee -- justo el oráculo que la consola evita usando un solo mensaje para los tres casos
     * (correo inexistente, contraseña mala, cuenta frenada).
     *
     * Se comprueba sobre el archivo de idioma y sobre el controlador: la clave no existe, y el
     * controlador no la nombra. Quien vuelva a añadirla tendrá que borrar esta prueba, y eso se ve
     * en una revisión.
     */
    public function testTheBusinessLoginScreenHasNoLockedAccountMessage(): void
    {
        foreach (['es-MX', 'en'] as $idioma) {
            $claves = require APPPATH . 'Language/' . $idioma . '/Login.php';

            $this->assertArrayNotHasKey(
                'platform_account_locked',
                $claves,
                'Un mensaje de «frenada» en la dirección pública de un cliente dice que ese correo es nuestro.',
            );
        }

        $controlador = (string) file_get_contents(APPPATH . 'Controllers/Login.php');

        $this->assertStringNotContainsString('platform_account_locked', $controlador);
    }

    /**
     * Las dos cosas que sí puede decir aparecen solo DESPUÉS de una contraseña correcta, así que no
     * le cuentan nada a quien no la tenga.
     */
    public function testTheMessagesItDoesShowExistInBothLanguages(): void
    {
        $es = require APPPATH . 'Language/es-MX/Login.php';
        $en = require APPPATH . 'Language/en/Login.php';

        foreach (['platform_second_factor_required', 'platform_support_employee_missing'] as $clave) {
            $this->assertArrayHasKey($clave, $es, $clave);
            $this->assertArrayHasKey($clave, $en, $clave);
        }
    }

    /**
     * El intento de plataforma va DESPUÉS de que la validación del empleado haya fallado.
     *
     * Es lo que hace que esta entrega no pueda dejar fuera a nadie del negocio: su puerta corre
     * primero y no se toca. Si alguien mueve la llamada antes de `validate()`, cada entrada de un
     * cajero pasaría por `platform_accounts` -- y una ráfaga de intentos contra la caja frenaría la
     * cuenta de un superadministrador desde la dirección pública de un cliente.
     */
    public function testThePlatformAttemptRunsAfterTheEmployeeValidation(): void
    {
        $controlador = (string) file_get_contents(APPPATH . 'Controllers/Login.php');

        $validacion = strpos($controlador, 'if (!$this->validate($rules, $messages))');
        $intento    = strpos($controlador, '$this->intentarCredencialDePlataforma(');

        $this->assertIsInt($validacion);
        $this->assertIsInt($intento);
        $this->assertGreaterThan(
            $validacion,
            $intento,
            'La credencial de plataforma se intenta cuando la del empleado ya falló, nunca antes.',
        );
    }
}
