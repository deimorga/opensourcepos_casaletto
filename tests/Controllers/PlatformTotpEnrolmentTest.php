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
use Config\Encryption as EncryptionConfig;
use Config\OSPOS;
use Config\Services;

/**
 * El alta del segundo factor, vista desde la pantalla.
 *
 * Toda esta clase existe para sostener una sola frase: **`totp_enabled_at` no se escribe jamás sin
 * haber verificado antes un código real**. Si eso falla, el superadministrador queda fuera de su
 * propia consola, y la plataforma no tiene ningún canal -- ni correo, ni SMS -- por donde
 * devolvérsela. Es el único desenlace de esta entrega del que no se vuelve.
 *
 * Las demás pruebas son las esquinas de esa misma frase: que el secreto no llegue a la cuenta antes
 * de tiempo, que lo que se guarda quepa en su columna, que apagar el factor exija la contraseña, y
 * que los códigos de rescate se entreguen en el mismo acto en que se enciende.
 *
 * @internal
 */
final class PlatformTotpEnrolmentTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const PASSWORD = 'la-de-siempre-y-bien-larga';

    private PlatformTotp $totp;

    private int $accountId;

    protected function setUp(): void
    {
        parent::setUp();

        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();
        config(EncryptionConfig::class)->key = str_repeat('k', 32);

        // Ver el mismo comentario en PlatformLoginSecondFactorTest: sin el mock, `regenerate()`
        // llama a `session_regenerate_id()` y phpunit corre con failOnWarning="true".
        $sessionConfig = config('Session');
        $session       = new MockSession(new ArrayHandler($sessionConfig, '0.0.0.0'), $sessionConfig);
        $session->setLogger(service('logger'));
        $session->start();
        Services::injectMock('session', $session);

        $this->createPlatformTables();

        $this->totp      = new PlatformTotp();
        $this->accountId = $this->seedAdmin();
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

    // ================= Quién puede llegar =================

    public function testAnAnonymousVisitorIsSentToTheLoginForm(): void
    {
        $this->withSession([]);

        $this->get('platform/accounts/totp')->assertRedirectTo('platform/login');
    }

    public function testAnAccountThatIsNotAPlatformAdminIsSentToTheLoginForm(): void
    {
        $ownerId = $this->seedAdmin('duena@negocio.test', isAdmin: false);
        $this->withSession(['platform_account_id' => $ownerId]);

        // D11 es solo para is_platform_admin = 1: la dueña de un negocio no instala nada.
        $this->get('platform/accounts/totp')->assertRedirectTo('platform/login');
    }

    // ================= El alta =================

    public function testEnrollingMintsASecretAndWritesNothingToTheAccountYet(): void
    {
        $this->asAdmin();
        $body = (string) $this->post('platform/accounts/totp/enroll')->getBody();

        $this->assertNotSame('', $this->secretFromPage($body), 'La pantalla tiene que enseñar la clave.');
        $this->assertNull($this->row()->totp_secret, 'Nada en la cuenta hasta confirmar.');
        $this->assertNull($this->row()->totp_enabled_at);
    }

    /**
     * Sin QR, la clave se teclea, así que tiene que estar en la pantalla en las dos formas: la
     * legible para escribirla a mano y la URI para pegarla o abrirla desde el propio teléfono.
     */
    public function testTheEnrolmentScreenShowsTheKeyAndTheOtpauthUri(): void
    {
        $this->asAdmin();
        $body = (string) $this->post('platform/accounts/totp/enroll')->getBody();

        $secret = $this->secretFromPage($body);

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertStringContainsString('otpauth://totp/', $body);
        $this->assertStringContainsString(
            trim(chunk_split($secret, 4, ' ')),
            $body,
            'Y en grupos de cuatro, que es como se teclea sin perder el sitio.',
        );
        $this->assertStringContainsString(PlatformTotp::ISSUER, $body, 'El nombre de la entrada, escrito.');
        $this->assertStringContainsString('super@micronuba.net', $body, 'Y de qué cuenta es.');
    }

    // ================= La regla que ordena la pantalla =================

    /**
     * LA PRUEBA QUE JUSTIFICA TODA LA CLASE.
     */
    public function testAWrongCodeDoesNotSwitchTheFactorOn(): void
    {
        $this->asAdmin();
        $this->post('platform/accounts/totp/enroll');

        $this->withSession(); // arrastra la sesión del alta, con su secreto dentro
        $this->post('platform/accounts/totp/confirm', ['code' => '000000']);

        $this->assertNull($this->row()->totp_enabled_at, 'Nunca queda encendido algo que no se ha probado.');
        $this->assertNull($this->row()->totp_secret, 'Y el secreto tampoco llega a la cuenta.');
    }

    public function testAWrongCodeKeepsTheSecretSoNobodyHasToTypeThirtyTwoCharactersAgain(): void
    {
        $this->asAdmin();
        $first = $this->secretFromPage((string) $this->post('platform/accounts/totp/enroll')->getBody());

        $this->withSession();
        $again = $this->secretFromPage((string) $this->post('platform/accounts/totp/confirm', ['code' => '000000'])->getBody());

        $this->assertSame($first, $again, 'Equivocarse de dígito no obliga a repetir el alta.');
    }

    public function testAValidCodeSwitchesTheFactorOnAndStoresTheSecretEncrypted(): void
    {
        $this->asAdmin();
        $secret = $this->secretFromPage((string) $this->post('platform/accounts/totp/enroll')->getBody());

        $this->withSession();
        $this->post('platform/accounts/totp/confirm', ['code' => $this->totp->currentCode($secret)]);

        $row = $this->row();

        $this->assertNotNull($row->totp_enabled_at, 'Con un código real, se enciende.');
        $this->assertNotNull($row->totp_secret);
        $this->assertNotSame($secret, $row->totp_secret, 'Y no se guarda en claro.');
        $this->assertSame($secret, $this->totp->decryptSecret($row->totp_secret), 'Pero se puede volver a abrir.');
    }

    /**
     * La columna es VARCHAR(512) por un incidente real -- `tenants.db_password` se truncó en 255 sin
     * decir una palabra. Esto lo comprueba sobre lo que de verdad se escribió en la fila, no sobre
     * un cálculo aparte.
     */
    public function testWhatEndsUpInTheColumnFitsInFiveHundredAndTwelve(): void
    {
        $this->asAdmin();
        $secret = $this->secretFromPage((string) $this->post('platform/accounts/totp/enroll')->getBody());

        $this->withSession();
        $this->post('platform/accounts/totp/confirm', ['code' => $this->totp->currentCode($secret)]);

        $this->assertLessThanOrEqual(512, strlen((string) $this->row()->totp_secret));
    }

    public function testConfirmingWithoutHavingEnrolledChangesNothing(): void
    {
        $this->asAdmin();

        $this->post('platform/accounts/totp/confirm', ['code' => '123456'])
            ->assertRedirectTo('platform/accounts/totp');

        $this->assertNull($this->row()->totp_enabled_at);
    }

    // ================= Los códigos de rescate =================

    public function testSwitchingItOnHandsOverTheRecoveryCodesInTheSameBreath(): void
    {
        $this->asAdmin();
        $secret = $this->secretFromPage((string) $this->post('platform/accounts/totp/enroll')->getBody());

        $this->withSession();
        $body = (string) $this->post('platform/accounts/totp/confirm', ['code' => $this->totp->currentCode($secret)])->getBody();

        $stored = db_connect('platform')->table('platform_account_recovery_codes')
            ->where('account_id', $this->accountId)
            ->countAllResults();

        $this->assertSame(
            PlatformAccount::RECOVERY_CODE_COUNT,
            $stored,
            'Encender el factor sin entregarlos deja la cuenta con una única llave.',
        );

        preg_match_all('/\b[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}\b/', $body, $shown);
        $this->assertCount($stored, $shown[0], 'Y todos se enseñan, esta vez y ninguna más.');
    }

    public function testRegeneratingRevokesThePreviousBatch(): void
    {
        $this->enableTheFactor();

        $before = $this->recoveryHashes();

        $this->asAdmin();
        $this->post('platform/accounts/totp/recovery-codes');

        $after = $this->recoveryHashes();

        $this->assertNotSame($before, $after, 'Una hoja nueva que dejara viva la vieja no habría revocado nada.');
        $this->assertSame([], array_intersect($before, $after));
    }

    public function testRecoveryCodesCannotBeRegeneratedWhileTheFactorIsOff(): void
    {
        $this->asAdmin();

        $this->post('platform/accounts/totp/recovery-codes')->assertRedirectTo('platform/accounts/totp');

        $this->assertSame(0, count($this->recoveryHashes()));
    }

    // ================= Apagarlo =================

    public function testTurningItOffNeedsThePassword(): void
    {
        $this->enableTheFactor();

        $this->asAdmin();
        $this->post('platform/accounts/totp/disable', ['password' => 'esta-no-es']);

        $this->assertNotNull($this->row()->totp_enabled_at, 'Sigue encendido.');
        $this->assertNotNull($this->row()->totp_secret);
    }

    public function testTheRightPasswordTurnsItOffAndClearsTheSecret(): void
    {
        $this->enableTheFactor();

        $this->asAdmin();
        $this->post('platform/accounts/totp/disable', ['password' => self::PASSWORD]);

        $this->assertNull($this->row()->totp_enabled_at);
        $this->assertNull($this->row()->totp_secret, 'Un secreto que ya no se usa no se queda guardado.');
    }

    /**
     * Volver a darse de alta con el factor encendido sustituiría en silencio un secreto que
     * funciona. Quien cambie de teléfono lo apaga primero, que ya le pide la contraseña.
     */
    public function testEnrollingAgainWhileItIsOnIsRefusedWithoutTouchingTheSecret(): void
    {
        $this->enableTheFactor();
        $before = $this->row()->totp_secret;

        $this->asAdmin();
        $this->post('platform/accounts/totp/enroll')->assertRedirectTo('platform/accounts/totp');

        $this->assertSame($before, $this->row()->totp_secret);
    }

    // ================= El registro de D6 =================

    public function testSwitchingItOnAndOffIsRecordedWithoutEverWritingTheSecret(): void
    {
        $this->enableTheFactor();

        $this->asAdmin();
        $this->post('platform/accounts/totp/disable', ['password' => self::PASSWORD]);

        $rows = db_connect('platform')->table('platform_activity_log')->orderBy('id')->get()->getResult();

        $this->assertSame(['account.totp_enabled', 'account.totp_disabled'], array_column($rows, 'action'));

        foreach ($rows as $row) {
            $this->assertStringNotContainsString('secret', strtolower((string) $row->detail));
        }
    }

    // ================= Ayudas =================

    private function asAdmin(): void
    {
        $this->withSession(['platform_account_id' => $this->accountId]);
    }

    /**
     * Hace el alta entera y deja el factor encendido, que es el punto de partida de media clase.
     */
    private function enableTheFactor(): void
    {
        $this->asAdmin();
        $secret = $this->secretFromPage((string) $this->post('platform/accounts/totp/enroll')->getBody());

        $this->withSession();
        $this->post('platform/accounts/totp/confirm', ['code' => $this->totp->currentCode($secret)]);
    }

    /**
     * La clave, sacada de la URI `otpauth://` que la propia pantalla imprime. Se lee de ahí y no de
     * la sesión a propósito: es lo que va a leer la aplicación del teléfono, así que si la pantalla
     * imprimiera una cosa distinta de la que guarda, estas pruebas lo verían.
     */
    private function secretFromPage(string $body): string
    {
        preg_match('/otpauth:\/\/totp\/[^"\'<\s]*/', $body, $match);

        if ($match === []) {
            return '';
        }

        parse_str((string) parse_url(html_entity_decode($match[0], ENT_QUOTES, 'UTF-8'), PHP_URL_QUERY), $query);

        return (string) ($query['secret'] ?? '');
    }

    /** @return list<string> */
    private function recoveryHashes(): array
    {
        return array_column(
            db_connect('platform')->table('platform_account_recovery_codes')
                ->where('account_id', $this->accountId)
                ->orderBy('id')
                ->get()
                ->getResult(),
            'code_hash',
        );
    }

    private function row(): object
    {
        return db_connect('platform')->table('platform_accounts')->where('id', $this->accountId)->get()->getRow();
    }

    private function seedAdmin(string $email = 'super@micronuba.net', bool $isAdmin = true): int
    {
        $platform = db_connect('platform');

        $platform->table('platform_accounts')->insert([
            'email'             => $email,
            'password_hash'     => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'is_platform_admin' => $isAdmin ? 1 : 0,
        ]);

        return (int) $platform->insertID();
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
