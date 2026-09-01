<?php

declare(strict_types=1);

namespace Tests\Commands;

use App\Commands\PlatformAccountUnlock;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\OSPOS;

/**
 * `php spark platform:unlock-account <correo>`.
 *
 * POR QUÉ SE PRUEBA EL CÓDIGO DE SALIDA Y NO SOLO LA SALIDA DE TEXTO
 *
 * Este comando existe para el día en que nadie pueda entrar por la pantalla, y ese día alguien lo
 * va a encadenar detrás de un `&&` o lo va a mirar desde un script. Un comando que imprime un error
 * en rojo y devuelve 0 se lee como éxito en todas partes menos en los ojos de quien esté delante --
 * y es justamente el fallo que ya tiene el `migrate` de serie, que traga cualquier Throwable y
 * siempre sale con 0 (ver la cabecera de app/Commands/PlatformMigrate.php).
 *
 * Por eso el comando se instancia a mano en vez de usar el ayudante command(): ese devuelve la
 * salida de texto y tira el código de retorno, que es exactamente lo que hay que comprobar aquí.
 *
 * @internal
 */
final class PlatformAccountUnlockTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private int $lockedId;
    private int $calmId;

    protected function setUp(): void
    {
        parent::setUp();

        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();

        $this->createPlatformTables();

        $this->lockedId = $this->seedAccount('encerrado@micronuba.net');
        $this->calmId   = $this->seedAccount('tranquilo@micronuba.net');

        $this->lockAccount($this->lockedId);
    }

    protected function tearDown(): void
    {
        $platform = db_connect('platform');
        $platform->query('DROP TABLE IF EXISTS `platform_activity_log`');
        $platform->query('DROP TABLE IF EXISTS `platform_accounts`');
        $platform->resetDataCache();

        parent::tearDown();
    }

    public function testUnlockingClearsTheCounterAndReturnsZero(): void
    {
        $exit = $this->unlock('encerrado@micronuba.net');

        $this->assertSame(0, $exit);

        $account = $this->accountRow('encerrado@micronuba.net');
        $this->assertSame(0, (int) $account->failed_login_count);
        $this->assertNull($account->failed_login_first_at, 'La ventana se borra junto con el contador.');
    }

    /**
     * La razón de ser del comando: el rescate deja el mismo rastro que dejaría la pantalla. Un
     * desbloqueo que no se puede ver después es una forma silenciosa de que una cuenta cambie de
     * manos.
     */
    public function testTheRescueLeavesTheSameRowTheScreenWouldHaveLeft(): void
    {
        $this->unlock('encerrado@micronuba.net');

        $entry = $this->lastActivity();

        $this->assertNotNull($entry, 'Un desbloqueo es una modificación y se registra.');
        $this->assertSame('account.unlocked', $entry->action);
        $this->assertSame('account', $entry->target_type);
        $this->assertSame((string) $this->lockedId, $entry->target_id);

        $detail = json_decode((string) $entry->detail, true);
        $this->assertSame('encerrado@micronuba.net', $detail['email']);
        $this->assertSame('cli', $detail['via'], 'La fila dice que vino de la terminal y no de la pantalla.');

        $this->assertNull(
            $entry->account_email,
            'Bajo spark no hay sesión, así que la fila es honestamente anónima en vez de atribuirse a alguien.',
        );
    }

    /**
     * Idempotente a propósito: el estado que se pedía -- «esta cuenta no está frenada» -- ya se
     * cumple. Devolver 1 mandaría a buscar un segundo problema que no existe.
     */
    public function testAnAccountThatWasNeverLockedIsNotAnError(): void
    {
        $exit = $this->unlock('tranquilo@micronuba.net');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Nothing to unlock', $this->getStreamFilterBuffer());
        $this->assertNull($this->lastActivity(), 'No hubo modificación, así que no hay nada que registrar.');
    }

    public function testAnEmailNobodyHasIsAnError(): void
    {
        $exit = $this->unlock('nadie@micronuba.net');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No platform account exists', $this->getStreamFilterBuffer());
    }

    public function testCallingItWithNoEmailIsAnErrorAndSaysHowToUseIt(): void
    {
        $command = new PlatformAccountUnlock(service('logger'), service('commands'));

        $this->assertSame(1, $command->run([]));
        $this->assertStringContainsString('platform:unlock-account <email>', $this->getStreamFilterBuffer());
    }

    public function testUnlockingOneAccountDoesNotTouchTheOther(): void
    {
        $this->lockAccount($this->calmId);

        $this->unlock('encerrado@micronuba.net');

        $this->assertSame(
            3,
            (int) $this->accountRow('tranquilo@micronuba.net')->failed_login_count,
            'El comando desbloquea la cuenta que se le nombra, no todas.',
        );
    }

    // ==================== Andamiaje ====================

    private function unlock(string $email): int
    {
        $command = new PlatformAccountUnlock(service('logger'), service('commands'));

        return (int) $command->run([$email]);
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

        $platform->query('DROP TABLE IF EXISTS `platform_activity_log`');
        $platform->query(
            'CREATE TABLE `platform_activity_log` (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                account_id INT UNSIGNED NULL,
                account_email VARCHAR(255) NULL,
                action VARCHAR(60) NOT NULL,
                target_type VARCHAR(30) NULL,
                target_id VARCHAR(100) NULL,
                detail TEXT NULL,
                ip_address VARCHAR(45) NULL,
                created_at DATETIME NULL,
                KEY platform_activity_created_at (created_at),
                KEY platform_activity_target (target_type, target_id)
            )',
        );

        $platform->resetDataCache();
    }

    private function seedAccount(string $email): int
    {
        $platform = db_connect('platform');

        $platform->table('platform_accounts')->insert([
            'email'             => $email,
            'password_hash'     => password_hash('la-de-siempre-2026', PASSWORD_DEFAULT),
            'is_platform_admin' => 1,
            'created_at'        => '2026-08-01 08:00:00',
        ]);

        return (int) $platform->insertID();
    }

    /**
     * Tres fallos dentro de la ventana de dos horas: el estado exacto en que deja la cuenta el
     * tercer intento equivocado.
     */
    private function lockAccount(int $id): void
    {
        db_connect('platform')->table('platform_accounts')->where('id', $id)->update([
            'failed_login_count'    => 3,
            'failed_login_first_at' => date('Y-m-d H:i:s', time() - 60),
        ]);
    }

    private function accountRow(string $email): ?object
    {
        return db_connect('platform')->table('platform_accounts')->where('email', $email)->get()->getRow();
    }

    private function lastActivity(): ?object
    {
        return db_connect('platform')->table('platform_activity_log')->orderBy('id', 'DESC')->get(1)->getRow();
    }
}
