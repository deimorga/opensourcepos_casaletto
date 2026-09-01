<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\PlatformAccount;
use App\Models\PlatformActivity;
use CodeIgniter\Session\Handlers\ArrayHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockSession;
use Config\Services;

/**
 * The console's record of what it changed (D6, section 7.1 of the technical design).
 *
 * The test that matters most here is the last one: after the orphan account is deleted, the row
 * saying who deleted it still names them. That is the entire reason `account_email` is a column
 * instead of a join, and it is a property that only shows up once something has actually been
 * removed -- which is why it is tested against a real deletion and not against an insert.
 *
 * @internal
 */
final class PlatformActivityTest extends CIUnitTestCase
{
    private PlatformActivity $activity;
    private PlatformAccount $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        $sessionConfig = config('Session');
        $session       = new MockSession(new ArrayHandler($sessionConfig, '0.0.0.0'), $sessionConfig);
        $session->setLogger(service('logger'));
        $session->start();
        Services::injectMock('session', $session);

        $this->createTables();

        $this->activity = new PlatformActivity();
        $this->accounts = new PlatformAccount();
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

    private function createTables(): void
    {
        $platform = db_connect('platform');

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
                UNIQUE KEY platform_recovery_codes_hash (code_hash)
            )',
        );

        $platform->resetDataCache();
    }

    private function seedAdmin(string $email): int
    {
        $platform = db_connect('platform');

        $platform->table('platform_accounts')->insert([
            'email'             => $email,
            'password_hash'     => password_hash('irrelevante', PASSWORD_DEFAULT),
            'is_platform_admin' => 1,
        ]);

        return (int) $platform->insertID();
    }

    private function rows(): array
    {
        return db_connect('platform')->table('platform_activity_log')->orderBy('id')->get()->getResult();
    }

    public function testRecordWritesTheActionWithATimestamp(): void
    {
        $this->activity->record(PlatformActivity::TENANT_SUSPENDED, PlatformActivity::TARGET_TENANT, 'paraisodelacanasta');

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('tenant.suspended', $rows[0]->action);
        $this->assertSame('tenant', $rows[0]->target_type);
        $this->assertSame('paraisodelacanasta', $rows[0]->target_id);
        $this->assertNotNull($rows[0]->created_at);
    }

    /**
     * The actor is taken from the session when it is not named, so a controller never has to
     * remember to pass it -- forgetting would produce an anonymous row that looks legitimate.
     */
    public function testTheActorIsTakenFromTheSessionWhenNotGiven(): void
    {
        $id = $this->seedAdmin('dueno@micronuba.net');
        session()->set('platform_account_id', $id);

        $this->activity->record(PlatformActivity::TENANT_CREATED, PlatformActivity::TARGET_TENANT, 'nuevo');

        $rows = $this->rows();

        $this->assertSame($id, (int) $rows[0]->account_id);
        $this->assertSame('dueno@micronuba.net', $rows[0]->account_email);
    }

    /**
     * There is no session under `php spark`, and a command that cannot record anything would be a
     * command that quietly does its work off the record.
     */
    public function testItRecordsWithoutASessionToo(): void
    {
        $this->activity->record(PlatformActivity::TENANT_DELETED, PlatformActivity::TARGET_TENANT, 'viejo');

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->account_id);
        $this->assertNull($rows[0]->account_email);
    }

    public function testTheDetailIsStoredAsJsonAndComesBackAsAnArray(): void
    {
        $this->activity->record(
            PlatformActivity::TENANT_SCHEMA_DROPPED,
            PlatformActivity::TARGET_TENANT,
            'viejo',
            ['db_name' => 'tenant_viejo', 'motivo' => 'baja solicitada'],
        );

        $rows = $this->rows();

        $this->assertSame(
            ['db_name' => 'tenant_viejo', 'motivo' => 'baja solicitada'],
            json_decode($rows[0]->detail, true),
        );
    }

    public function testAnEmptyDetailIsNullAndNotAnEmptyJsonObject(): void
    {
        $this->activity->record(PlatformActivity::ACCOUNT_UNLOCKED, PlatformActivity::TARGET_ACCOUNT, '7');

        $this->assertNull($this->rows()[0]->detail);
    }

    /**
     * THE TEST THIS FILE EXISTS FOR.
     *
     * The orphan account is deleted, and the row that says who deleted it stays readable -- because
     * the email was copied onto the row when it was written, not looked up when it is read.
     */
    public function testTheLogStillNamesTheActorAfterTheirAccountIsGone(): void
    {
        $real   = $this->seedAdmin('dueno@micronuba.net');
        $orphan = $this->seedAdmin('admin@ospos-saas.micronuba.net');

        session()->set('platform_account_id', $real);
        $this->activity->record(
            PlatformActivity::ACCOUNT_DELETED,
            PlatformActivity::TARGET_ACCOUNT,
            (string) $orphan,
            ['email' => 'admin@ospos-saas.micronuba.net'],
        );

        // And now the actor themselves is retired, the way the second superadministrator will be
        // rotated some day.
        $this->accounts->deleteAccount($real, $orphan);

        $rows = $this->rows();

        $this->assertNull(
            db_connect('platform')->table('platform_accounts')->where('id', $real)->get()->getRow(),
            'The actor really is gone.',
        );
        $this->assertSame('dueno@micronuba.net', $rows[0]->account_email, 'The log must survive its subject.');
        $this->assertSame('account.deleted', $rows[0]->action);
    }

    /**
     * D6 in one test: modifications are recorded, accesses are not. A successful login writes
     * `last_login_at` and nothing here; the row that does exist is the one for the brake tripping,
     * because that is a real change of state that outlives the request.
     */
    public function testTheActionsCoverModificationsAndNotAccesses(): void
    {
        $actions = [
            PlatformActivity::TENANT_CREATED,
            PlatformActivity::TENANT_SUSPENDED,
            PlatformActivity::TENANT_ACTIVATED,
            PlatformActivity::TENANT_DELETED,
            PlatformActivity::TENANT_SCHEMA_DROPPED,
            // La Entrega 3: restablecerle la contraseña al administrador de un negocio. Se anota
            // quién y a qué usuario, NUNCA la contraseña -- esta tabla la leen personas y se guarda
            // para siempre.
            PlatformActivity::TENANT_PASSWORD_RESET,
            PlatformActivity::ACCOUNT_CREATED,
            PlatformActivity::ACCOUNT_DELETED,
            PlatformActivity::ACCOUNT_PASSWORD_CHANGED,
            PlatformActivity::ACCOUNT_LOCKED,
            PlatformActivity::ACCOUNT_UNLOCKED,
            PlatformActivity::ACCOUNT_TOTP_ENABLED,
            PlatformActivity::ACCOUNT_TOTP_DISABLED,
            // Nivel 2: entrar al punto de venta de un cliente, y escribir algo estando dentro.
            PlatformActivity::SUPPORT_ENTERED,
            PlatformActivity::SUPPORT_WRITE,
        ];

        $this->assertSame($actions, array_values(PlatformActivity::ACTIONS));

        foreach ($actions as $action) {
            $this->assertNotSame('account.logged_in', $action);
            $this->assertLessThanOrEqual(60, strlen($action), 'The column is VARCHAR(60).');
        }
    }

    /**
     * The listing is reverse-chronological, which is the ORDER BY the created_at index exists for.
     */
    public function testRecentReturnsTheNewestFirst(): void
    {
        $this->activity->record(PlatformActivity::TENANT_CREATED, PlatformActivity::TARGET_TENANT, 'primero');
        $this->activity->record(PlatformActivity::TENANT_CREATED, PlatformActivity::TARGET_TENANT, 'segundo');

        $recent = $this->activity->recent(10);

        $this->assertSame('segundo', $recent[0]->target_id);
        $this->assertSame('primero', $recent[1]->target_id);
    }
}
