<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Libraries\PlatformTotp;
use App\Models\PlatformAccount;
use CodeIgniter\Session\Handlers\ArrayHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockSession;
use CodeIgniter\Test\TestResponse;
use Config\Encryption as EncryptionConfig;
use Config\OSPOS;
use Config\Services;

/**
 * La entrada a la consola con segundo factor: las dos pantallas, vistas desde fuera.
 *
 * Se prueba por HTTP y no llamando al modelo, porque lo que hay que demostrar aquí es exactamente
 * lo que el modelo no puede demostrar solo:
 *
 *   - Que la contraseña correcta con el factor encendido deja la sesión A MEDIAS, no abierta. Un
 *     `platform_account_id` puesto un paso antes de tiempo sería un salto completo del factor, y
 *     ninguna prueba que pregunte «¿hay sesión?» lo notaría.
 *   - Que los tres rechazos de D8 -- correo que no existe, contraseña mala, cuenta frenada --
 *     escriben EL MISMO texto en la pantalla. Con `assertSame`, letra por letra: si el mensaje
 *     distinguiera, una cuenta frenada estaría confirmando que existe, y `assertStringContainsString`
 *     dejaría pasar precisamente la diferencia que importa.
 *
 * Las tablas de la plataforma se construyen a mano, como en tests/Models/PlatformAccountTest.php:
 * el grupo `platform` apunta al mismo esquema que `tests` con prefijo VACÍO (ver phpunit.xml.dist),
 * así que correr el espacio de nombres Platform entero chocaría con las tablas que otros archivos
 * levantan para sí.
 *
 * @internal
 */
final class PlatformLoginSecondFactorTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const PASSWORD = 'la-de-siempre-y-bien-larga';

    private PlatformTotp $totp;

    private string $secret;

    private int $accountId;

    protected function setUp(): void
    {
        parent::setUp();

        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();
        config(EncryptionConfig::class)->key = str_repeat('k', 32);

        // Sin este mock, `regenerate()` llama a `session_regenerate_id()`, que bajo PHPUnit avisa
        // en vez de funcionar -- y phpunit.xml.dist corre con failOnWarning="true". El mock guarda
        // todo en $_SESSION, que es exactamente lo que FeatureTestTrait rellena antes de despachar
        // y lo que estas pruebas leen después. Mismo motivo que en tests/Models/PlatformAccountTest.
        $sessionConfig = config('Session');
        $session       = new MockSession(new ArrayHandler($sessionConfig, '0.0.0.0'), $sessionConfig);
        $session->setLogger(service('logger'));
        $session->start();
        Services::injectMock('session', $session);

        $this->createPlatformTables();

        $this->totp   = new PlatformTotp();
        $this->secret = $this->totp->generateSecret();
    }

    protected function tearDown(): void
    {
        $platform = db_connect('platform');
        $platform->query('DROP TABLE IF EXISTS `platform_account_recovery_codes`');
        $platform->query('DROP TABLE IF EXISTS `platform_accounts`');
        $platform->query('DROP TABLE IF EXISTS `platform_activity_log`');
        $platform->resetDataCache();

        parent::tearDown();
    }

    // ================= D8: un solo mensaje para los tres rechazos =================

    /**
     * LA PRUEBA CENTRAL DE D8.
     *
     * `assertSame` y no `assertStringContainsString`: lo que hay que demostrar es que los tres
     * textos son el mismo, no que los tres contengan una palabra parecida. Una diferencia de una
     * coma entre «correo desconocido» y «cuenta frenada» ya es un oráculo: quien pruebe direcciones
     * sabrá cuáles existen sin acertar ni una contraseña.
     */
    public function testTheThreeRefusalsSayExactlyTheSameThing(): void
    {
        $this->seedAccount();

        $unknownEmail = $this->errorFrom($this->post('platform/login', [
            'email'    => 'nadie@micronuba.net',
            'password' => self::PASSWORD,
        ]));

        $wrongPassword = $this->errorFrom($this->post('platform/login', [
            'email'    => 'super@micronuba.net',
            'password' => 'esta-no-es',
        ]));

        // Dos fallos más dejan la cuenta cerrada; el tercero es el que la cierra y ya devuelve
        // "frenada".
        $this->post('platform/login', ['email' => 'super@micronuba.net', 'password' => 'esta-no-es']);
        $this->post('platform/login', ['email' => 'super@micronuba.net', 'password' => 'esta-no-es']);

        $locked = $this->errorFrom($this->post('platform/login', [
            'email'    => 'super@micronuba.net',
            'password' => self::PASSWORD, // la BUENA: frenada quiere decir frenada
        ]));

        $this->assertNotSame('', $unknownEmail, 'La pantalla tiene que decir algo.');
        $this->assertSame($unknownEmail, $wrongPassword, 'Correo inexistente y contraseña mala: el mismo texto.');
        $this->assertSame($unknownEmail, $locked, 'Y la cuenta frenada, también el mismo texto.');
    }

    public function testALockedAccountIsRefusedEvenWithTheRightPassword(): void
    {
        $this->seedAccount();
        $this->failThreeTimes();

        $this->post('platform/login', ['email' => 'super@micronuba.net', 'password' => self::PASSWORD]);

        $this->assertNull(session()->get('platform_account_id'), 'Un freno que deja pasar la contraseña buena no es un freno.');
        $this->assertNull(session()->get('platform_pending_account_id'));
    }

    // ================= El estado a medias =================

    /**
     * La contraseña correcta con el factor encendido deja la marca de PENDIENTE y nada más.
     */
    public function testTheRightPasswordWithTotpOnLeavesTheSessionPendingAndNotAuthenticated(): void
    {
        $this->seedAccount(withTotp: true);

        $response = $this->post('platform/login', [
            'email'    => 'super@micronuba.net',
            'password' => self::PASSWORD,
        ]);

        $response->assertRedirectTo('platform/login/totp');
        $this->assertNull(session()->get('platform_account_id'), 'Nada autenticado todavía.');
        $this->assertSame($this->accountId, (int) session()->get('platform_pending_account_id'));
    }

    /**
     * Y sin el factor, la misma contraseña sí abre la sesión: la diferencia la marca la cuenta, no
     * la pantalla.
     */
    public function testTheRightPasswordWithoutTotpOpensTheSessionAsBefore(): void
    {
        $this->seedAccount();

        $this->post('platform/login', ['email' => 'super@micronuba.net', 'password' => self::PASSWORD]);

        $this->assertSame($this->accountId, (int) session()->get('platform_account_id'));
        $this->assertNull(session()->get('platform_pending_account_id'));
    }

    /**
     * Quien está a medias no puede entrar por la puerta de al lado: toda pantalla que herede de
     * `Platform_Controller` lo devuelve al reto, o el factor sería opcional para quien sepa
     * escribir otra dirección.
     *
     * Se comprueba sobre `platform/accounts/totp` y no sobre `platform/admin` a propósito: aquella
     * pantalla todavía hereda de BaseController con su propia guarda, y es la otra mitad de la
     * Entrega 2 la que decide cuándo pasa a la base común. Probar aquí su redirección sería probar
     * el trabajo de otro y romperse cuando lo termine.
     */
    public function testAPendingVisitorIsSentBackToTheChallengeFromAnyConsoleScreen(): void
    {
        $this->seedAccount(withTotp: true);

        $this->withSession(['platform_pending_account_id' => $this->accountId]);
        $this->get('platform/accounts/totp')->assertRedirectTo('platform/login/totp');
    }

    // ================= El reto =================

    public function testAValidCodePromotesThePendingSessionIntoARealOne(): void
    {
        $this->seedAccount(withTotp: true);
        $this->withSession(['platform_pending_account_id' => $this->accountId]);

        $this->post('platform/login/totp', ['code' => $this->totp->currentCode($this->secret)]);

        $this->assertSame($this->accountId, (int) session()->get('platform_account_id'));
        $this->assertNull(session()->get('platform_pending_account_id'), 'La marca de a medias se retira al ascender.');
    }

    public function testAWrongCodeLeavesTheSessionExactlyWhereItWas(): void
    {
        $this->seedAccount(withTotp: true);
        $this->withSession(['platform_pending_account_id' => $this->accountId]);

        $this->post('platform/login/totp', ['code' => '000000']);

        $this->assertNull(session()->get('platform_account_id'), 'Un código malo no abre nada.');
        $this->assertSame($this->accountId, (int) session()->get('platform_pending_account_id'), 'Y tampoco expulsa.');
    }

    public function testACodeFromTwoWindowsAgoIsRefused(): void
    {
        $this->seedAccount(withTotp: true);
        $this->withSession(['platform_pending_account_id' => $this->accountId]);

        $stale = (new PlatformTotp(static fn (): int => time() - 60))->currentCode($this->secret);

        $this->post('platform/login/totp', ['code' => $stale]);

        $this->assertNull(session()->get('platform_account_id'));
    }

    /**
     * El reto va atado a la cuenta que está pendiente, y a ninguna otra.
     *
     * Dos cuentas, cada una con su propio secreto. Con la marca de pendiente puesta sobre la
     * segunda, el código válido de la PRIMERA no abre nada: si abriera, bastaría con tener un
     * teléfono dado de alta -- el propio -- para terminar el reto de cualquiera cuya contraseña se
     * hubiese adivinado.
     */
    public function testTheChallengeIsBoundToThePendingAccountAndNotToAnyValidCode(): void
    {
        $otherSecret = $this->totp->generateSecret();
        $this->seedAccount(email: 'otro@micronuba.net', secret: $otherSecret);

        $this->seedAccount(withTotp: true);

        $this->withSession(['platform_pending_account_id' => $this->accountId]);
        $this->post('platform/login/totp', ['code' => $this->totp->currentCode($otherSecret)]);

        $this->assertNull(session()->get('platform_account_id'), 'El código de otro teléfono no vale aquí.');
        $this->assertSame($this->accountId, (int) session()->get('platform_pending_account_id'));
    }

    // ================= Los códigos de rescate =================

    public function testARecoveryCodeWorksOnceAndOnlyOnce(): void
    {
        $this->seedAccount(withTotp: true);
        $codes = model(PlatformAccount::class)->issueRecoveryCodes($this->accountId);
        $code  = $codes[0];

        $this->withSession(['platform_pending_account_id' => $this->accountId]);
        $this->post('platform/login/totp', ['code' => $code]);
        $this->assertSame($this->accountId, (int) session()->get('platform_account_id'), 'La primera vez entra.');

        // Segunda vuelta, con el mismo código: ya está gastado. `withSession()` reemplaza
        // $_SESSION entero en el próximo despacho, que es lo que deja al visitante otra vez a
        // medias -- `destroy()` no serviría, es un no-op bajo pruebas.
        $this->withSession(['platform_pending_account_id' => $this->accountId]);
        $this->post('platform/login/totp', ['code' => $code]);

        $this->assertNull(session()->get('platform_account_id'), 'De un solo uso quiere decir de un solo uso.');
        $this->assertSame($this->accountId, (int) session()->get('platform_pending_account_id'));
    }

    public function testTheOtherRecoveryCodesStillWorkAfterOneIsSpent(): void
    {
        $this->seedAccount(withTotp: true);
        $codes = model(PlatformAccount::class)->issueRecoveryCodes($this->accountId);

        $this->withSession(['platform_pending_account_id' => $this->accountId]);
        $this->post('platform/login/totp', ['code' => $codes[0]]);

        $this->withSession(['platform_pending_account_id' => $this->accountId]);
        $this->post('platform/login/totp', ['code' => $codes[1]]);

        $this->assertSame($this->accountId, (int) session()->get('platform_account_id'));
    }

    /**
     * Un código de rescate NO se descarta por su forma.
     *
     * Se prueban siempre los dos caminos -- primero el de la aplicación, después el de rescate --
     * y nunca se elige uno mirando lo que se tecleó. Uno cuyos dieciséis hexadecimales contengan
     * justo seis dígitos parece un código de la aplicación: descartarlo por eso lo dejaría fuera
     * precisamente el día en que hace falta, que es el día en que alguien perdió el teléfono.
     *
     * El código se siembra a mano en vez de sacarlo de una tanda emitida, para que la prueba no
     * dependa de que el azar produzca uno con esa forma.
     */
    public function testARecoveryCodeIsTriedEvenWhenItLooksLikeAnAppCode(): void
    {
        $this->seedAccount(withTotp: true);

        // 'ABCD-EFGH-JK12-3456': dieciséis alfanuméricos con exactamente seis dígitos dentro.
        $code = 'ABCD-EFGH-JK12-3456';
        $this->assertTrue($this->totp->looksLikeCode($code), 'Tiene la forma de un código de aplicación.');

        db_connect('platform')->table('platform_account_recovery_codes')->insert([
            'account_id' => $this->accountId,
            'code_hash'  => hash('sha256', 'ABCDEFGHJK123456'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->withSession(['platform_pending_account_id' => $this->accountId]);
        $this->post('platform/login/totp', ['code' => $code]);

        $this->assertSame($this->accountId, (int) session()->get('platform_account_id'));
    }

    // ================= El freno de D8, también en el reto =================

    /**
     * Con la contraseña ya pasada, seis dígitos son un espacio recorrible a fuerza de peticiones si
     * nada lo frena. Los intentos del reto cuentan sobre las MISMAS dos columnas que los de la
     * contraseña, porque D8 cuenta sobre la cuenta y no sobre la pantalla.
     */
    public function testThreeWrongCodesShutTheAccountAndSendTheVisitorBack(): void
    {
        $this->seedAccount(withTotp: true);
        $this->withSession(['platform_pending_account_id' => $this->accountId]);
        $this->post('platform/login/totp', ['code' => '000000']);

        $this->withSession(['platform_pending_account_id' => $this->accountId]);
        $this->post('platform/login/totp', ['code' => '000000']);

        $this->withSession(['platform_pending_account_id' => $this->accountId]);
        $response = $this->post('platform/login/totp', ['code' => '000000']);

        $response->assertRedirectTo('platform/login');
        $this->assertNull(session()->get('platform_pending_account_id'), 'Y no queda a medias, o daría vueltas para siempre.');

        $this->assertSame(3, (int) $this->row()->failed_login_count);
        $this->assertNotNull($this->row()->failed_login_first_at);
    }

    public function testAGoodCodeClearsTheCounterLeftByTheFailedOnes(): void
    {
        $this->seedAccount(withTotp: true);

        $this->withSession(['platform_pending_account_id' => $this->accountId]);
        $this->post('platform/login/totp', ['code' => '000000']);
        $this->assertSame(1, (int) $this->row()->failed_login_count);

        $this->withSession(['platform_pending_account_id' => $this->accountId]);
        $this->post('platform/login/totp', ['code' => $this->totp->currentCode($this->secret)]);

        $this->assertSame(0, (int) $this->row()->failed_login_count);
        $this->assertNull($this->row()->failed_login_first_at);
    }

    // ================= Ayudas =================

    /**
     * El texto del `alert alert-danger` que pinta la pantalla, o cadena vacía si no pintó ninguno.
     */
    private function errorFrom(TestResponse $response): string
    {
        preg_match('#<div class="alert alert-danger">(.*?)</div>#s', (string) $response->getBody(), $match);

        return trim(html_entity_decode($match[1] ?? '', ENT_QUOTES, 'UTF-8'));
    }

    private function failThreeTimes(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->post('platform/login', ['email' => 'super@micronuba.net', 'password' => 'esta-no-es']);
        }
    }

    private function row(): object
    {
        return db_connect('platform')->table('platform_accounts')->where('id', $this->accountId)->get()->getRow();
    }

    /**
     * @param string|null $secret un secreto propio para esta cuenta. Sin él y con $withTotp, se usa
     *                            el de la prueba; sin ninguno de los dos, la cuenta queda sin factor.
     */
    private function seedAccount(string $email = 'super@micronuba.net', bool $withTotp = false, ?string $secret = null): void
    {
        $secret ??= $withTotp ? $this->secret : null;

        $platform = db_connect('platform');

        $platform->table('platform_accounts')->insert([
            'email'             => $email,
            'password_hash'     => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'is_platform_admin' => 1,
            'totp_secret'       => $secret === null ? null : $this->totp->encryptSecret($secret),
            'totp_enabled_at'   => $secret === null ? null : date('Y-m-d H:i:s'),
        ]);

        $this->accountId = (int) $platform->insertID();
    }

    private function createPlatformTables(): void
    {
        $platform = db_connect('platform');

        $platform->query('DROP TABLE IF EXISTS `platform_accounts`');
        $platform->query(
            'CREATE TABLE `platform_accounts` (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                is_platform_admin TINYINT(1) NOT NULL DEFAULT 0,
                last_login_at DATETIME NULL,
                created_by_account_id INT UNSIGNED NULL,
                failed_login_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                failed_login_first_at DATETIME NULL,
                totp_secret VARCHAR(512) NULL,
                totp_enabled_at DATETIME NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )',
        );

        $platform->query('DROP TABLE IF EXISTS `platform_account_recovery_codes`');
        $platform->query(
            'CREATE TABLE `platform_account_recovery_codes` (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                account_id INT UNSIGNED NOT NULL,
                code_hash CHAR(64) NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NULL,
                UNIQUE KEY platform_recovery_codes_hash (code_hash),
                KEY platform_recovery_codes_account (account_id)
            )',
        );

        // El registro de actividad: `account.locked` se escribe cuando el freno salta, y sin la
        // tabla la petición reventaría por algo que la prueba ni siquiera está mirando.
        $platform->query('DROP TABLE IF EXISTS `platform_activity_log`');
        $platform->query(
            'CREATE TABLE `platform_activity_log` (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                account_id INT UNSIGNED NULL,
                account_email VARCHAR(255) NULL,
                action VARCHAR(64) NOT NULL,
                target_type VARCHAR(32) NULL,
                target_id VARCHAR(100) NULL,
                detail TEXT NULL,
                ip_address VARCHAR(45) NULL,
                created_at DATETIME NULL,
                KEY platform_activity_created (created_at)
            )',
        );

        $platform->resetDataCache();
    }
}
