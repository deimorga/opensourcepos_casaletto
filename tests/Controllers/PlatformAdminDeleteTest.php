<?php

namespace Tests\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\OSPOS;

/**
 * The delete screen of the platform console, which until now was a checkbox and a button.
 *
 * Two businesses are seeded the way production really looks: `casaletto`, adopted, whose schema is
 * `ospos` -- the database the shop is trading on right now -- and `paraiso`, provisioned normally
 * with its own schema and its own MySQL user. Every test below asks the same question in a
 * different way: after this request, is the row still there?
 *
 * The registry row is the assertion because it is the one thing this suite can observe. The test
 * database user cannot DROP DATABASE, so no test here can prove a schema was not dropped by
 * looking for the schema. What it can prove -- and what actually protects the shop -- is that the
 * request was refused as a whole: nothing was unregistered, so nothing downstream ran either.
 *
 * The library-level guarantee, that delete() itself refuses an adopted tenant before it opens the
 * connection that holds DROP DATABASE, is in tests/Libraries/TenantProvisionerDeleteTest.php. This
 * file covers the screen; that one covers the door.
 */
final class PlatformAdminDeleteTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private int $adminAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        // The grouped `tests` connection caches the pre-migration table list, which leaves
        // Config\OSPOS with incomplete defaults.
        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();

        // No provisioning credentials in the test environment, so TenantProvisioner::delete()
        // skips its DDL block entirely (it is guarded by `if ($provisionUser && $provisionPassword)`)
        // and only the registry row moves. That is deliberate: these tests exercise the screen's
        // decisions, never a real teardown.
        putenv('PLATFORM_PROVISION_USERNAME');
        putenv('PLATFORM_PROVISION_PASSWORD');

        $this->createPlatformTables();
        $this->seedTenant('casaletto', 'ospos', '');
        $this->seedTenant('paraiso', 'tenant_paraiso', 'tenant_paraiso');
        $this->adminAccountId = $this->seedPlatformAdmin();
    }

    protected function tearDown(): void
    {
        $platform = db_connect('platform');
        $platform->query('DROP TABLE IF EXISTS `tenants`');
        $platform->query('DROP TABLE IF EXISTS `platform_accounts`');

        parent::tearDown();
    }

    /**
     * Built by hand, like tests/Filters/TenantResolverTest.php does: the platform group points at
     * the test schema with an EMPTY prefix (see phpunit.xml.dist), so `tenants` and
     * `platform_accounts` sit beside the `ospos_`-prefixed tables without colliding.
     */
    private function createPlatformTables(): void
    {
        $platform = db_connect('platform');

        $platform->query('DROP TABLE IF EXISTS `tenants`');
        $platform->query(
            'CREATE TABLE `tenants` (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(100) NOT NULL UNIQUE,
                db_name VARCHAR(100) NOT NULL,
                db_user VARCHAR(100) NOT NULL DEFAULT "",
                db_password VARCHAR(255) NULL,
                status VARCHAR(20) NOT NULL DEFAULT "active",
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )',
        );

        $platform->query('DROP TABLE IF EXISTS `platform_accounts`');
        $platform->query(
            'CREATE TABLE `platform_accounts` (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                is_platform_admin TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )',
        );
    }

    private function seedTenant(string $slug, string $dbName, string $dbUser): void
    {
        db_connect('platform')->table('tenants')->insert([
            'slug'    => $slug,
            'db_name' => $dbName,
            'db_user' => $dbUser,
            'status'  => 'active',
        ]);
    }

    private function seedPlatformAdmin(): int
    {
        $platform = db_connect('platform');

        $platform->table('platform_accounts')->insert([
            'email'             => 'super@micronuba.net',
            'password_hash'     => password_hash('irrelevante-aqui', PASSWORD_DEFAULT),
            'is_platform_admin' => 1,
        ]);

        return (int) $platform->insertID();
    }

    /**
     * The panel's session key is platform_account_id -- NOT the POS's person_id, which belongs to
     * a tenant's own employees table and means nothing here.
     *
     * Re-armed before every single request on purpose: FeatureTestTrait::call() overwrites
     * $_SESSION with its own property before dispatching (see populateGlobals()), so a session set
     * once in setUp() is gone by the second request and the controller sees an anonymous visitor.
     */
    private function asPlatformAdmin(): void
    {
        $session = Services::session();
        $session->destroy();
        $session->set('platform_account_id', $this->adminAccountId);

        $this->withSession(['platform_account_id' => $this->adminAccountId]);
    }

    private function isRegistered(string $slug): bool
    {
        return db_connect('platform')->table('tenants')->where('slug', $slug)->countAllResults() > 0;
    }

    private function deleteRequest(string $slug, array $post): TestResponse
    {
        $this->asPlatformAdmin();

        return $this->post('platform/admin/' . $slug . '/delete', $post);
    }

    // ========== La confirmación ==========

    public function testTheConfirmationScreenAsksForTheSlugToBeTyped(): void
    {
        $this->asPlatformAdmin();

        $body = (string) $this->get('platform/admin/paraiso/delete')->getBody();

        $this->assertStringContainsString('name="confirm_slug"', $body, 'The slug must be typed, not ticked.');
        $this->assertStringContainsString('paraiso', $body);
    }

    /**
     * Dropping the schema is a second, graver decision, and it has its own confirmation: the
     * database name. For Casaletto that word would be `ospos`, which says far more about what is
     * at stake than its slug does.
     */
    public function testDroppingTheSchemaHasItsOwnSeparateConfirmationField(): void
    {
        $this->asPlatformAdmin();

        $body = (string) $this->get('platform/admin/paraiso/delete')->getBody();

        $this->assertStringContainsString('name="confirm_db_name"', $body);
        $this->assertStringContainsString('tenant_paraiso', $body, 'The screen names the database at risk.');
    }

    public function testAnAdoptedBusinessGetsNoDeleteFormAtAllButIsToldWhy(): void
    {
        $this->asPlatformAdmin();

        $body = (string) $this->get('platform/admin/casaletto/delete')->getBody();

        $this->assertStringNotContainsString('name="confirm_slug"', $body, 'There must be nothing to submit.');
        $this->assertStringContainsString('ospos', $body, 'The screen says which schema it is protecting.');
    }

    /**
     * The trap this project has already fallen into: a string written only in es-ES is invisible.
     * CodeIgniter falls back from es-MX to `es` -- a directory that does not exist here -- and
     * never to English, so a missing key would render as "Platform.confirm_slug_label" and a
     * whole screen written in the wrong Spanish renders in English without a single error.
     */
    public function testTheScreenSpeaksSpanishWhenTheApplicationDoes(): void
    {
        // Not an Accept-Language header and not Config\App::$defaultLocale: neither decides
        // anything here. App\Events\Load_config runs on every request and overwrites the locale
        // with current_language_code(), which reads app_config.language_code from the database.
        $this->withLanguageCode('es-MX');

        try {
            $this->asPlatformAdmin();
            $body = (string) $this->get('platform/admin/paraiso/delete')->getBody();
        } finally {
            $this->withLanguageCode('en');
        }

        $this->assertStringContainsString('Eliminar negocio', $body);
        $this->assertStringContainsString('Escriba', $body, 'The confirmation instructions must be translated too.');
        $this->assertStringNotContainsString('Platform.', $body, 'A key rendered raw means it is missing from es-MX.');
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

    public function testAConfirmationScreenForABusinessThatDoesNotExistIs404(): void
    {
        $this->asPlatformAdmin();

        // With no 404 override configured (app/Config/Routing.php), CodeIgniter re-throws rather
        // than rendering; in production the same exception is what produces the 404 page.
        $this->expectException(PageNotFoundException::class);

        $this->get('platform/admin/no-existe/delete');
    }

    // ========== Lo que no borra nada ==========

    public function testWithoutTypingTheSlugNothingIsDeleted(): void
    {
        $response = $this->deleteRequest('paraiso', []);

        $this->assertTrue($this->isRegistered('paraiso'), 'An empty confirmation must delete nothing.');
        $response->assertRedirect();
    }

    public function testTypingTheSlugWrongDeletesNothing(): void
    {
        $this->deleteRequest('paraiso', ['confirm_slug' => 'paraso']);

        $this->assertTrue($this->isRegistered('paraiso'));
    }

    /**
     * A near miss is the realistic mistake: the operator opened the wrong row, or pasted the
     * neighbouring business's name.
     */
    public function testTypingAnotherBusinessSlugDeletesNeitherOfThem(): void
    {
        $this->deleteRequest('paraiso', ['confirm_slug' => 'casaletto']);

        $this->assertTrue($this->isRegistered('paraiso'));
        $this->assertTrue($this->isRegistered('casaletto'));
    }

    /**
     * The one that matters. Casaletto's schema is `ospos`; before this screen was fixed, a
     * checkbox and a button stood between the console and it.
     */
    public function testAnAdoptedBusinessIsRefusedEvenWithItsSlugTypedPerfectly(): void
    {
        $this->deleteRequest('casaletto', ['confirm_slug' => 'casaletto', 'drop_schema' => '1', 'confirm_db_name' => 'ospos']);

        $this->assertTrue($this->isRegistered('casaletto'), 'An adopted business is not deletable from the console.');
    }

    public function testAnAnonymousVisitorDeletesNothing(): void
    {
        $session = Services::session();
        $session->destroy();
        $this->withSession([]);

        $this->post('platform/admin/paraiso/delete', ['confirm_slug' => 'paraiso']);

        $this->assertTrue($this->isRegistered('paraiso'), 'The panel is closed to anyone not logged in.');
    }

    // ========== El borrado del esquema ==========

    /**
     * Asking for the schema to be destroyed without confirming its name refuses the WHOLE request
     * -- the business is not unregistered either. Half-doing what was asked would leave the
     * operator with a business they can no longer reach and a database they meant to destroy.
     */
    public function testAskingToDropTheSchemaWithoutNamingItRefusesEverything(): void
    {
        $this->deleteRequest('paraiso', ['confirm_slug' => 'paraiso', 'drop_schema' => '1']);

        $this->assertTrue($this->isRegistered('paraiso'));
    }

    public function testNamingTheSchemaWrongRefusesEverythingToo(): void
    {
        $this->deleteRequest('paraiso', [
            'confirm_slug'    => 'paraiso',
            'drop_schema'     => '1',
            'confirm_db_name' => 'tenant_paraso',
        ]);

        $this->assertTrue($this->isRegistered('paraiso'));
    }

    // ========== Lo que sí borra ==========

    public function testTypingTheSlugExactlyDeletesTheBusiness(): void
    {
        $this->deleteRequest('paraiso', ['confirm_slug' => 'paraiso']);

        $this->assertFalse($this->isRegistered('paraiso'), 'A correctly confirmed deletion must go through.');
    }

    /**
     * The counterpart to the two refusals above: with both words typed, the deletion proceeds. The
     * gate is the confirmation, not a blanket refusal that would quietly make the feature useless.
     */
    public function testBothWordsTypedCorrectlyDeletesTheBusinessAndItsSchema(): void
    {
        $this->deleteRequest('paraiso', [
            'confirm_slug'    => 'paraiso',
            'drop_schema'     => '1',
            'confirm_db_name' => 'tenant_paraiso',
        ]);

        $this->assertFalse($this->isRegistered('paraiso'));
    }

    // ========== El listado ==========

    public function testTheListingOffersNoDeleteLinkForAnAdoptedBusinessAndSaysWhy(): void
    {
        $this->asPlatformAdmin();

        $body = (string) $this->get('platform/admin')->getBody();

        $this->assertStringNotContainsString('platform/admin/casaletto/delete', $body);
        $this->assertStringContainsString('platform/admin/paraiso/delete', $body, 'Normal businesses stay deletable.');
        $this->assertStringContainsString('casaletto', $body, 'It still has to be listed and manageable.');
    }
}
