<?php

declare(strict_types=1);

namespace Tests\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\OSPOS;

/**
 * La pantalla de superadministradores.
 *
 * LO QUE ESTOS CASOS INTENTAN DEMOSTRAR
 *
 * Que la pantalla contesta la pregunta para la que se hizo -- «¿cuál de estas cuentas no debería
 * existir?» -- y que ninguna de las salvaguardas depende de que el navegador colabore. Cada prueba
 * de baja termina preguntando lo mismo: después de esta petición, ¿sigue la fila ahí?
 *
 * LAS TRES CUENTAS SEMBRADAS SON LAS DE PRODUCCIÓN, MÁS UNA
 *
 * `deimorga@gmail.com` nació en la terminal pero se usa todos los días.
 * `admin@ospos-saas.micronuba.net` nació en la terminal y no la ha usado nadie nunca: es la
 * huérfana. `segunda@micronuba.net` se creó desde la consola y todavía no la ha estrenado nadie.
 *
 * Las tres comparten alguna señal con la huérfana y ninguna las dos. Si la pantalla marcara solo
 * por «nunca entró» marcaría dos, y si marcara solo por «nadie la creó desde aquí» marcaría otras
 * dos; la única que se lleva la marca es la que reúne las dos cosas. Esa es toda la prueba.
 *
 * SOBRE EL IDIOMA
 *
 * Platform_Controller fija es-MX en su constructor, pero App\Events\Load_config corre DESPUÉS
 * (post_controller_constructor) y, cuando PlatformContext::isPlatform() es falso -- que es
 * siempre bajo PHPUnit, donde no hay HTTP_HOST --, vuelve a poner el locale del negocio. Así que
 * afirmar español aquí exige mover app_config.language_code, igual que hace
 * tests/Controllers/PlatformAdminDeleteTest.php. En producción no hace falta porque en la
 * dirección de la consola las DOS piezas dicen es-MX.
 *
 * @internal
 */
final class PlatformAccountsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const OPERATOR_PASSWORD = 'la-de-siempre-2026';

    private int $operatorId;
    private int $orphanId;
    private int $secondId;

    protected function setUp(): void
    {
        parent::setUp();

        // La conexión agrupada `tests` guarda la lista de tablas de antes de migrar, y eso deja a
        // Config\OSPOS con valores por defecto incompletos.
        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();

        $this->createPlatformTables();

        $this->operatorId = $this->seedAccount('deimorga@gmail.com', true, '2026-08-31 09:00:00', null);
        $this->orphanId   = $this->seedAccount('admin@ospos-saas.micronuba.net', true, null, null);
        $this->secondId   = $this->seedAccount('segunda@micronuba.net', true, null, $this->operatorId);
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

    // ==================== El listado ====================

    public function testTheListingShowsNoLastLoginForTheAccountNobodyHasEverUsed(): void
    {
        $body = (string) $this->asOperator()->get('platform/accounts')->getBody();

        $this->assertStringContainsString('admin@ospos-saas.micronuba.net', $body);
        $this->assertStringContainsString(
            esc(lang('Platform.account_never_logged_in')),
            $body,
            'La columna de último ingreso tiene que decir algo, no quedarse en blanco.',
        );
    }

    public function testTheListingSaysWhichAccountsNobodyCreatedFromTheConsole(): void
    {
        $body = (string) $this->asOperator()->get('platform/accounts')->getBody();

        $this->assertStringContainsString(esc(lang('Platform.account_created_from_cli')), $body);
    }

    public function testTheListingNamesWhoCreatedAnAccountThatWasCreatedFromTheConsole(): void
    {
        $body = (string) $this->asOperator()->get('platform/accounts')->getBody();

        // El correo del operador sale dos veces: en su propia fila y como creador de la tercera.
        $this->assertSame(
            2,
            substr_count($body, 'deimorga@gmail.com'),
            'Quién creó cada cuenta se resuelve a un correo, no a un número.',
        );
    }

    /**
     * El caso que da sentido a la pantalla. Dos de las tres cuentas comparten una de las dos
     * señales; solo la huérfana reúne las dos, y solo ella queda marcada.
     */
    public function testOnlyTheAccountThatNobodyCreatedAndNobodyHasUsedIsMarked(): void
    {
        $body = (string) $this->asOperator()->get('platform/accounts')->getBody();

        $this->assertSame(1, substr_count($body, 'table-warning'), 'Se marca una fila, y solo una.');

        $this->assertMatchesRegularExpression(
            '/<tr class="table-warning">.*?admin@ospos-saas\.micronuba\.net.*?<\/tr>/s',
            $body,
            'La fila marcada tiene que ser la de la cuenta huérfana.',
        );
    }

    public function testNobodyIsOfferedALinkToDeleteTheirOwnAccount(): void
    {
        $body = (string) $this->asOperator()->get('platform/accounts')->getBody();

        $this->assertStringNotContainsString(
            'platform/accounts/' . $this->operatorId . '/delete',
            $body,
            'La fila propia no ofrece la baja.',
        );
        $this->assertStringContainsString('platform/accounts/' . $this->orphanId . '/delete', $body);
    }

    /**
     * La trampa en la que este proyecto ya cayó: una cadena escrita solo en es-ES es invisible.
     * CodeIgniter cae de es-MX a `es` -- que no existe -- y nunca al inglés, así que una clave que
     * falte sale como "Platform.loquesea" y una pantalla entera en la variante equivocada sale en
     * inglés sin dar el menor error.
     */
    public function testTheScreenSpeaksSpanish(): void
    {
        $this->withLanguageCode('es-MX');

        try {
            $body = (string) $this->asOperator()->get('platform/accounts')->getBody();
        } finally {
            $this->withLanguageCode('en');
        }

        $this->assertStringContainsString('Superadministradores', $body);
        $this->assertStringContainsString('Nunca se usó', $body);
        $this->assertStringNotContainsString(
            'Platform.',
            $body,
            'Una clave cruda en pantalla significa que falta en es-MX.',
        );
    }

    // ==================== El alta ====================

    public function testCreatingAnAccountRecordsWhoCreatedItAndLeavesATrace(): void
    {
        $this->asOperator()->post('platform/accounts/create', [
            'email'             => 'tercera@micronuba.net',
            'password'          => 'una-contrasena-larga',
            'password_confirm'  => 'una-contrasena-larga',
            'is_platform_admin' => '1',
        ]);

        $created = $this->accountRow('tercera@micronuba.net');

        $this->assertNotNull($created, 'La cuenta tiene que existir después del alta.');
        $this->assertSame(
            $this->operatorId,
            (int) $created->created_by_account_id,
            'Quién la creó es el dato que distingue una cuenta legítima de una huérfana.',
        );
        $this->assertSame(1, (int) $created->is_platform_admin);
        $this->assertNull($created->last_login_at);

        $entry = $this->lastActivity();
        $this->assertSame('account.created', $entry->action);
        $this->assertSame('deimorga@gmail.com', $entry->account_email);
        $this->assertSame('tercera@micronuba.net', json_decode((string) $entry->detail, true)['email']);
    }

    public function testTheCreatedPasswordNeverReachesTheActivityLog(): void
    {
        $this->asOperator()->post('platform/accounts/create', [
            'email'            => 'tercera@micronuba.net',
            'password'         => 'sale-en-el-registro-no',
            'password_confirm' => 'sale-en-el-registro-no',
        ]);

        $this->assertStringNotContainsString(
            'sale-en-el-registro-no',
            (string) $this->lastActivity()->detail,
            'Esta tabla la leen personas y se guarda para siempre.',
        );
    }

    public function testAPasswordShorterThanTheMinimumCreatesNothing(): void
    {
        $body = (string) $this->asOperator()->post('platform/accounts/create', [
            'email'            => 'corta@micronuba.net',
            'password'         => 'corta',
            'password_confirm' => 'corta',
        ])->getBody();

        $this->assertNull($this->accountRow('corta@micronuba.net'));
        $this->assertStringContainsString('name="email"', $body, 'Vuelve el formulario, no una página en blanco.');
        $this->assertStringContainsString('corta@micronuba.net', $body, 'Con el correo ya tecleado dentro.');
    }

    public function testTwoDifferentPasswordsCreateNothing(): void
    {
        $this->asOperator()->post('platform/accounts/create', [
            'email'            => 'dispar@micronuba.net',
            'password'         => 'una-contrasena-larga',
            'password_confirm' => 'otra-contrasena-larga',
        ]);

        $this->assertNull($this->accountRow('dispar@micronuba.net'));
    }

    public function testAnEmailThatIsAlreadyTakenCreatesNothing(): void
    {
        $before = $this->countAccounts();

        $this->asOperator()->post('platform/accounts/create', [
            'email'            => 'segunda@micronuba.net',
            'password'         => 'una-contrasena-larga',
            'password_confirm' => 'una-contrasena-larga',
        ]);

        $this->assertSame($before, $this->countAccounts());
    }

    public function testSomethingThatIsNotAnEmailCreatesNothing(): void
    {
        $before = $this->countAccounts();

        $this->asOperator()->post('platform/accounts/create', [
            'email'            => 'no-es-un-correo',
            'password'         => 'una-contrasena-larga',
            'password_confirm' => 'una-contrasena-larga',
        ]);

        $this->assertSame($before, $this->countAccounts());
    }

    // ==================== La baja ====================

    public function testTheConfirmationScreenAsksForTheEmailToBeTyped(): void
    {
        $body = (string) $this->asOperator()->get('platform/accounts/' . $this->orphanId . '/delete')->getBody();

        $this->assertStringContainsString('name="confirm_email"', $body, 'Se escribe el correo, no se marca una casilla.');
        $this->assertStringContainsString('admin@ospos-saas.micronuba.net', $body);
    }

    public function testTypingTheEmailWrongDeletesNothing(): void
    {
        $response = $this->asOperator()->post('platform/accounts/' . $this->orphanId . '/delete', [
            'confirm_email' => 'admin@ospos-saas.micronuba.ne',
        ]);

        $this->assertNotNull($this->accountRow('admin@ospos-saas.micronuba.net'));
        $response->assertRedirect();
        $this->assertNull($this->lastActivity(), 'Un rechazo no es una modificación y no deja fila.');
    }

    public function testAnEmptyConfirmationDeletesNothing(): void
    {
        $this->asOperator()->post('platform/accounts/' . $this->orphanId . '/delete', []);

        $this->assertNotNull($this->accountRow('admin@ospos-saas.micronuba.net'));
    }

    public function testTypingTheEmailExactlyDeletesTheAccountAndLeavesATrace(): void
    {
        $this->asOperator()->post('platform/accounts/' . $this->orphanId . '/delete', [
            'confirm_email' => 'admin@ospos-saas.micronuba.net',
        ]);

        $this->assertNull($this->accountRow('admin@ospos-saas.micronuba.net'), 'La cuenta huérfana se va.');

        $entry = $this->lastActivity();
        $this->assertSame('account.deleted', $entry->action);
        $this->assertSame((string) $this->orphanId, $entry->target_id);
        $this->assertSame(
            'admin@ospos-saas.micronuba.net',
            json_decode((string) $entry->detail, true)['email'],
            'El correo de la cuenta eliminada queda escrito: ya no hay de dónde volver a sacarlo.',
        );
    }

    /**
     * El correo se compara con hash_equals() sobre el valor recortado, así que un espacio pegado al
     * copiar no cuenta como error de tecleo -- un correo no puede llevar espacios, y recortarlos no
     * puede convertir una respuesta equivocada en una acertada.
     */
    public function testAPastedEmailWithSurroundingSpacesStillCounts(): void
    {
        $this->asOperator()->post('platform/accounts/' . $this->orphanId . '/delete', [
            'confirm_email' => "  admin@ospos-saas.micronuba.net\n",
        ]);

        $this->assertNull($this->accountRow('admin@ospos-saas.micronuba.net'));
    }

    public function testNobodyDeletesTheirOwnAccountEvenTypingTheirEmailRight(): void
    {
        $this->asOperator()->post('platform/accounts/' . $this->operatorId . '/delete', [
            'confirm_email' => 'deimorga@gmail.com',
        ]);

        $this->assertNotNull(
            $this->accountRow('deimorga@gmail.com'),
            'La regla la impone el modelo dentro de su transacción, no la pantalla.',
        );
        $this->assertNull($this->lastActivity());
    }

    public function testTheConfirmationScreenForYourOwnAccountOffersNothingToSubmit(): void
    {
        $body = (string) $this->asOperator()->get('platform/accounts/' . $this->operatorId . '/delete')->getBody();

        $this->assertStringNotContainsString('name="confirm_email"', $body);
        $this->assertStringContainsString(esc(lang('Platform.account_delete_refused_self')), $body);
    }

    /**
     * «No se puede eliminar al último», visto desde la pantalla.
     *
     * La consola no puede llegar a INTENTARLO por un camino normal: quien opera es administrador,
     * así que cualquier OTRO administrador implica que hay dos, y la regla del último solo se puede
     * disparar sobre uno mismo -- donde gana «ni a sí mismo», que se comprueba justo arriba -- o en
     * la carrera entre dos peticiones simultáneas, que es lo que resuelve la transacción del modelo
     * y lo que cubre tests/Models/PlatformAccountTest.php.
     *
     * Lo que sí ve el operador, y lo que se comprueba aquí, es que quedarse con uno solo se avisa
     * ANTES de que nadie lo intente, y que sobre esa única cuenta no queda ninguna baja que ofrecer.
     */
    public function testWithASingleAdministratorLeftTheScreenWarnsAndOffersNoDeletion(): void
    {
        $this->deleteAccountRow($this->orphanId);
        $this->deleteAccountRow($this->secondId);

        $body = (string) $this->asOperator()->get('platform/accounts')->getBody();

        $this->assertStringContainsString(esc(lang('Platform.accounts_only_one_admin')), $body);
        $this->assertStringNotContainsString('/delete', $body, 'No queda ninguna baja que ofrecer.');
    }

    public function testAnAccountThatDoesNotExistIs404(): void
    {
        // Sin override de 404 configurado (app/Config/Routing.php) CodeIgniter relanza en vez de
        // renderizar; en producción esa misma excepción es la que produce la página de 404.
        $this->expectException(PageNotFoundException::class);

        $this->asOperator()->get('platform/accounts/99999/delete');
    }

    // ==================== El desbloqueo ====================

    public function testUnlockingClearsTheCounterAndLeavesATrace(): void
    {
        $this->lockAccount($this->orphanId);

        $this->asOperator()->post('platform/accounts/' . $this->orphanId . '/unlock');

        $account = $this->accountRow('admin@ospos-saas.micronuba.net');
        $this->assertSame(0, (int) $account->failed_login_count);
        $this->assertNull($account->failed_login_first_at, 'La ventana se borra con el contador.');

        $entry = $this->lastActivity();
        $this->assertSame('account.unlocked', $entry->action);
        $this->assertSame('deimorga@gmail.com', $entry->account_email, 'Quién levantó el freno queda escrito.');
    }

    public function testUnlockingAnAccountThatWasNotLockedChangesNothing(): void
    {
        $this->asOperator()->post('platform/accounts/' . $this->secondId . '/unlock');

        $this->assertNull($this->lastActivity(), 'No hubo modificación, así que no hay nada que registrar.');
    }

    public function testTheListingOffersToUnlockOnlyTheAccountThatIsActuallyLocked(): void
    {
        $this->lockAccount($this->orphanId);

        $body = (string) $this->asOperator()->get('platform/accounts')->getBody();

        $this->assertSame(
            1,
            substr_count($body, '/unlock'),
            'El botón de desbloquear solo aparece donde hay algo que desbloquear.',
        );
        $this->assertStringContainsString(esc(lang('Platform.account_locked')), $body);
    }

    // ==================== La propia contraseña ====================

    public function testChangingTheOwnPasswordWorksAndLeavesATrace(): void
    {
        $before = (string) $this->accountRow('deimorga@gmail.com')->password_hash;

        $this->asOperator()->post('platform/accounts/password', [
            'password_current'     => self::OPERATOR_PASSWORD,
            'password_new'         => 'otra-contrasena-larga',
            'password_new_confirm' => 'otra-contrasena-larga',
        ]);

        $after = (string) $this->accountRow('deimorga@gmail.com')->password_hash;

        $this->assertNotSame($before, $after);
        $this->assertTrue(password_verify('otra-contrasena-larga', $after));

        $entry = $this->lastActivity();
        $this->assertSame('account.password_changed', $entry->action);
        $this->assertNull($entry->detail, 'Se registra que cambió, nunca a qué.');
    }

    public function testTheWrongCurrentPasswordChangesNothing(): void
    {
        $before = (string) $this->accountRow('deimorga@gmail.com')->password_hash;

        $body = (string) $this->asOperator()->post('platform/accounts/password', [
            'password_current'     => 'no-es-esta',
            'password_new'         => 'otra-contrasena-larga',
            'password_new_confirm' => 'otra-contrasena-larga',
        ])->getBody();

        $this->assertSame($before, (string) $this->accountRow('deimorga@gmail.com')->password_hash);
        $this->assertStringContainsString(esc(lang('Platform.password_change_failed_current')), $body);
        $this->assertNull($this->lastActivity());
    }

    public function testTwoDifferentNewPasswordsChangeNothing(): void
    {
        $before = (string) $this->accountRow('deimorga@gmail.com')->password_hash;

        $this->asOperator()->post('platform/accounts/password', [
            'password_current'     => self::OPERATOR_PASSWORD,
            'password_new'         => 'otra-contrasena-larga',
            'password_new_confirm' => 'otra-contrasena-larga-no',
        ]);

        $this->assertSame($before, (string) $this->accountRow('deimorga@gmail.com')->password_hash);
    }

    public function testANewPasswordShorterThanTheMinimumChangesNothing(): void
    {
        $before = (string) $this->accountRow('deimorga@gmail.com')->password_hash;

        $this->asOperator()->post('platform/accounts/password', [
            'password_current'     => self::OPERATOR_PASSWORD,
            'password_new'         => 'corta',
            'password_new_confirm' => 'corta',
        ]);

        $this->assertSame($before, (string) $this->accountRow('deimorga@gmail.com')->password_hash);
    }

    public function testReusingTheSamePasswordIsRefused(): void
    {
        $body = (string) $this->asOperator()->post('platform/accounts/password', [
            'password_current'     => self::OPERATOR_PASSWORD,
            'password_new'         => self::OPERATOR_PASSWORD,
            'password_new_confirm' => self::OPERATOR_PASSWORD,
        ])->getBody();

        $this->assertStringContainsString(esc(lang('Platform.password_change_failed_same')), $body);
        $this->assertNull($this->lastActivity());
    }

    // ==================== La puerta ====================

    public function testAVisitorWithNoSessionIsSentToTheLoginForm(): void
    {
        $this->withSession([])->get('platform/accounts')->assertRedirect();
    }

    // ==================== Andamiaje ====================

    /**
     * La sesión de la consola lleva `platform_account_id`, NO el `person_id` del punto de venta,
     * que pertenece a la tabla `employees` de un negocio y aquí no significa nada.
     *
     * Se rearma antes de CADA petición a propósito: FeatureTestTrait::call() sobrescribe $_SESSION
     * con su propia propiedad antes de despachar (ver populateGlobals()), así que una sesión puesta
     * una sola vez en setUp() ya no está en la segunda petición y el controlador ve a un visitante
     * anónimo.
     *
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

    /**
     * Construidas a mano, como en tests/Filters/TenantResolverTest.php: en este entorno el grupo
     * `platform` apunta al mismo esquema que `tests` pero con prefijo VACÍO (ver phpunit.xml.dist),
     * así que estas tablas conviven con las `ospos_` sin chocar. Correr el namespace Platform
     * entero chocaría con la tabla `tenants` que otros archivos construyen para sí mismos.
     */
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

    private function seedAccount(string $email, bool $isAdmin, ?string $lastLogin, ?int $createdBy): int
    {
        $platform = db_connect('platform');

        $platform->table('platform_accounts')->insert([
            'email'                 => $email,
            'password_hash'         => password_hash(self::OPERATOR_PASSWORD, PASSWORD_DEFAULT),
            'is_platform_admin'     => $isAdmin ? 1 : 0,
            'last_login_at'         => $lastLogin,
            'created_by_account_id' => $createdBy,
            'created_at'            => '2026-08-01 08:00:00',
        ]);

        return (int) $platform->insertID();
    }

    private function accountRow(string $email): ?object
    {
        return db_connect('platform')->table('platform_accounts')->where('email', $email)->get()->getRow();
    }

    private function countAccounts(): int
    {
        return db_connect('platform')->table('platform_accounts')->countAllResults();
    }

    private function deleteAccountRow(int $id): void
    {
        db_connect('platform')->table('platform_accounts')->where('id', $id)->delete();
    }

    /**
     * Tres fallos dentro de la ventana de dos horas: el estado exacto que deja PlatformAccount tras
     * el tercer intento equivocado.
     */
    private function lockAccount(int $id): void
    {
        db_connect('platform')->table('platform_accounts')->where('id', $id)->update([
            'failed_login_count'    => 3,
            'failed_login_first_at' => date('Y-m-d H:i:s', time() - 60),
        ]);
    }

    private function lastActivity(): ?object
    {
        return db_connect('platform')->table('platform_activity_log')
            ->orderBy('id', 'DESC')
            ->get(1)
            ->getRow();
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
