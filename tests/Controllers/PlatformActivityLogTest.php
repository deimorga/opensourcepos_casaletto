<?php

declare(strict_types=1);

namespace Tests\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\OSPOS;

/**
 * El registro de actividad.
 *
 * LA PRUEBA QUE JUSTIFICA LA TABLA
 *
 * `account_email` está desnormalizado a propósito, y la única forma de demostrar que eso sirve para
 * algo es eliminar la cuenta que escribió la fila y volver a mirar la pantalla. Si algún día alguien
 * "mejora" esto con un JOIN contra `platform_accounts` para mostrar el correo actual, todo seguirá
 * pareciendo correcto salvo justo las filas que más importan -- las que hablan de cuentas que ya no
 * están -- y este caso es lo que lo va a impedir.
 *
 * @internal
 */
final class PlatformActivityLogTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private int $operatorId;
    private int $orphanId;

    protected function setUp(): void
    {
        parent::setUp();

        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();

        $this->createPlatformTables();

        $this->operatorId = $this->seedAccount('deimorga@gmail.com');
        $this->orphanId   = $this->seedAccount('admin@ospos-saas.micronuba.net');
    }

    protected function tearDown(): void
    {
        $platform = db_connect('platform');
        $platform->query('DROP TABLE IF EXISTS `platform_activity_log`');
        $platform->query('DROP TABLE IF EXISTS `platform_account_recovery_codes`');
        $platform->query('DROP TABLE IF EXISTS `platform_accounts`');
        $platform->resetDataCache();

        parent::tearDown();
    }

    public function testAnEmptyLogSaysSoInsteadOfShowingAnEmptyTable(): void
    {
        $body = (string) $this->asOperator()->get('platform/activity')->getBody();

        $this->assertStringContainsString(esc(lang('Platform.activity_empty')), $body);
        $this->assertStringNotContainsString('<tbody>', $body, 'Sin filas no se pinta una tabla vacía.');
    }

    public function testTheLogNamesTheActionInWordsAndNotAsItsInternalCode(): void
    {
        $this->seedEntry('deimorga@gmail.com', 'tenant.suspended', 'tenant', 'paraiso');

        $body = (string) $this->asOperator()->get('platform/activity')->getBody();

        $this->assertStringContainsString(esc(lang('Platform.action_tenant_suspended')), $body);
        $this->assertStringContainsString('paraiso', $body);
        $this->assertStringContainsString(esc(lang('Platform.activity_target_tenant')), $body);
    }

    /**
     * Una acción que alguien añada al modelo y se olvide de traducir sale con su nombre técnico,
     * no como un hueco en blanco. Una celda vacía se lee como «no pasó nada».
     */
    public function testAnUntranslatedActionFallsBackToItsOwnName(): void
    {
        $this->seedEntry('deimorga@gmail.com', 'tenant.rebranded', 'tenant', 'paraiso');

        $body = (string) $this->asOperator()->get('platform/activity')->getBody();

        $this->assertStringContainsString('tenant.rebranded', $body);
        $this->assertStringNotContainsString('Platform.action_', $body);
    }

    public function testAnEntryWrittenFromTheCommandLineSaysSoInsteadOfLookingAnonymous(): void
    {
        $this->seedEntry(null, 'account.unlocked', 'account', (string) $this->orphanId);

        $body = (string) $this->asOperator()->get('platform/activity')->getBody();

        $this->assertStringContainsString(esc(lang('Platform.activity_from_cli')), $body);
    }

    /**
     * El caso central. Se elimina la cuenta huérfana DESDE LA PANTALLA -- no con un DELETE a mano --
     * y después se lee el registro: tiene que seguir diciendo quién la eliminó y a quién eliminó,
     * con los dos correos escritos, sin poder resolver ninguno de los dos por id.
     */
    public function testTheLogStillNamesBothAccountsAfterOneOfThemIsDeleted(): void
    {
        $this->asOperator()->post('platform/accounts/' . $this->orphanId . '/delete', [
            'confirm_email' => 'admin@ospos-saas.micronuba.net',
        ]);

        $this->assertSame(
            0,
            db_connect('platform')->table('platform_accounts')
                ->where('id', $this->orphanId)
                ->countAllResults(),
            'La cuenta tiene que estar realmente eliminada para que la prueba signifique algo.',
        );

        $body = (string) $this->asOperator()->get('platform/activity')->getBody();

        $this->assertStringContainsString(
            'deimorga@gmail.com',
            $body,
            'Quién lo hizo: viene de account_email, copiado al escribir la fila.',
        );
        $this->assertStringContainsString(
            'admin@ospos-saas.micronuba.net',
            $body,
            'A quién: viene del detalle, y ya no hay ninguna fila de la que sacarlo.',
        );
        $this->assertStringContainsString(esc(lang('Platform.action_account_deleted')), $body);
    }

    public function testTheNewestEntryComesFirst(): void
    {
        $this->seedEntry('deimorga@gmail.com', 'tenant.created', 'tenant', 'primero', '2026-08-01 10:00:00');
        $this->seedEntry('deimorga@gmail.com', 'tenant.deleted', 'tenant', 'ultimo', '2026-08-31 10:00:00');

        $body = (string) $this->asOperator()->get('platform/activity')->getBody();

        $this->assertLessThan(
            strpos($body, 'primero'),
            strpos($body, 'ultimo'),
            'La lista es cronológica inversa: lo último que pasó es lo primero que se lee.',
        );
    }

    public function testTheScreenSpeaksSpanish(): void
    {
        $this->seedEntry('deimorga@gmail.com', 'account.deleted', 'account', '9');

        $this->withLanguageCode('es-MX');

        try {
            $body = (string) $this->asOperator()->get('platform/activity')->getBody();
        } finally {
            $this->withLanguageCode('en');
        }

        $this->assertStringContainsString('Registro de actividad', $body);
        $this->assertStringContainsString('Cuenta eliminada', $body);
        $this->assertStringNotContainsString('Platform.', $body);
    }

    public function testAVisitorWithNoSessionIsSentToTheLoginForm(): void
    {
        $this->withSession([])->get('platform/activity')->assertRedirect();
    }

    // ==================== Andamiaje ====================

    /**
     * @return $this
     */
    private function asOperator(): self
    {
        $session = Services::session();
        $session->destroy();
        $session->set('platform_account_id', $this->operatorId);

        $this->withSession(['platform_account_id' => $this->operatorId]);

        return $this;
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

    private function seedEntry(
        ?string $actorEmail,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        string $createdAt = '2026-08-31 12:00:00',
    ): void {
        db_connect('platform')->table('platform_activity_log')->insert([
            'account_id'    => $actorEmail === null ? null : $this->operatorId,
            'account_email' => $actorEmail,
            'action'        => $action,
            'target_type'   => $targetType,
            'target_id'     => $targetId,
            'detail'        => null,
            'ip_address'    => '127.0.0.1',
            'created_at'    => $createdAt,
        ]);
    }

    private function withLanguageCode(string $code): void
    {
        $db = db_connect();

        if ($db->table('app_config')->where('key', 'language_code')->countAllResults() > 0) {
            $db->table('app_config')->where('key', 'language_code')->update(['value' => $code]);
        } else {
            $db->table('app_config')->insert(['key' => 'language_code', 'value' => $code]);
        }

        config(OSPOS::class)->update_settings();
    }
}
