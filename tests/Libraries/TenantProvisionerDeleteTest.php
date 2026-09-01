<?php

namespace Tests\Libraries;

use App\Libraries\TenantProvisioner;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\OSPOS;
use RuntimeException;

/**
 * The guard that stands between the platform console and `DROP DATABASE ospos`.
 *
 * Casaletto is an ADOPTED tenant: its schema existed before the platform did, so adopt()
 * registered it without creating a dedicated MySQL user and left db_user empty. Its db_name is
 * `ospos` -- the database the trading business sells from. Until this guard existed, delete()
 * would happily have been handed that row.
 *
 * The refusal lives in the library, not only in the controller, because delete() is the single
 * door to `DROP DATABASE`: a future command, a future screen or a future script reaches the same
 * method, and none of them will re-implement the check.
 *
 * Nothing here provisions or drops anything for real -- the test database user cannot CREATE or
 * DROP a database, by design. What can be pinned down, and is the whole point, is that the
 * refusal happens BEFORE the provisioning connection is ever opened. That is provable without
 * provisioning credentials because the teardown block is already guarded by
 * `if ($provisionUser && $provisionPassword)`: seed credentials that cannot possibly work, and
 * the error message says which of the two paths ran.
 */
final class TenantProvisionerDeleteTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private TenantProvisioner $provisioner;

    protected function setUp(): void
    {
        parent::setUp();

        // The grouped `tests` connection caches the pre-migration table list, which leaves
        // Config\OSPOS with incomplete defaults.
        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();

        $this->forgetProvisionCredentials();
        $this->createRegistry();

        // Casaletto, as it really is in production: adopted, no dedicated user, and its schema is
        // the one the shop trades on.
        $this->seed('casaletto', 'ospos', '');
        // A normal, provisioned business: its own schema, its own MySQL user.
        $this->seed('paraiso', 'tenant_paraiso', 'tenant_paraiso');

        $this->provisioner = new TenantProvisioner();
    }

    protected function tearDown(): void
    {
        db_connect('platform')->query('DROP TABLE IF EXISTS `tenants`');
        $this->forgetProvisionCredentials();

        parent::tearDown();
    }

    /**
     * The platform group points at the test schema with an empty prefix, so `tenants` sits beside
     * the `ospos_`-prefixed tables without colliding. See phpunit.xml.dist.
     */
    private function createRegistry(): void
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
    }

    private function seed(string $slug, string $dbName, string $dbUser): void
    {
        db_connect('platform')->table('tenants')->insert([
            'slug'    => $slug,
            'db_name' => $dbName,
            'db_user' => $dbUser,
            'status'  => 'active',
        ]);
    }

    private function rowFor(string $slug): ?object
    {
        return db_connect('platform')->table('tenants')->where('slug', $slug)->get()->getRow();
    }

    /**
     * Credentials that reach a real MySQL server and are certainly rejected by it. Anything that
     * opens the provisioning connection with these fails loudly, which is what makes "we never got
     * there" an assertion instead of a hope.
     */
    private function useUnusableProvisionCredentials(): void
    {
        putenv('PLATFORM_PROVISION_USERNAME=nadie_con_este_nombre');
        putenv('PLATFORM_PROVISION_PASSWORD=clave-que-no-sirve');
    }

    private function forgetProvisionCredentials(): void
    {
        putenv('PLATFORM_PROVISION_USERNAME');
        putenv('PLATFORM_PROVISION_PASSWORD');
    }

    // ========== Qué es un tenant adoptado ==========

    public function testATenantWithoutItsOwnDatabaseUserIsAdopted(): void
    {
        $this->assertTrue($this->provisioner->isAdopted($this->rowFor('casaletto')));
    }

    public function testAProvisionedTenantIsNotAdopted(): void
    {
        $this->assertFalse($this->provisioner->isAdopted($this->rowFor('paraiso')));
    }

    /**
     * The column is NOT NULL in the migration, but adopt() inserts without naming it and older
     * rows were written by hand. A null db_user means exactly what an empty one means: nobody ever
     * created a user for this schema.
     */
    public function testAMissingDatabaseUserCountsAsAdoptedToo(): void
    {
        $this->assertTrue($this->provisioner->isAdopted((object) ['db_user' => null]));
        $this->assertTrue($this->provisioner->isAdopted((object) ['slug' => 'sin_columna']));
    }

    // ========== El freno ==========

    public function testDeletingAnAdoptedTenantIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/adopted/i');

        $this->provisioner->delete('casaletto');
    }

    public function testARefusedDeletionLeavesTheRegistryRowExactlyWhereItWas(): void
    {
        try {
            $this->provisioner->delete('casaletto', true);
        } catch (RuntimeException) {
            // The refusal is asserted above; here only its consequences matter.
        }

        $this->assertNotNull($this->rowFor('casaletto'), 'The business must still be registered.');
    }

    /**
     * The assertion this file exists for. `DROP DATABASE ospos` is one statement away inside that
     * connection block, so the refusal must come first -- not after a connection, not inside the
     * try/catch that would report it as a teardown failure.
     */
    public function testTheRefusalHappensBeforeTheProvisioningConnectionIsOpened(): void
    {
        $this->useUnusableProvisionCredentials();

        try {
            $this->provisioner->delete('casaletto', true);
            $this->fail('An adopted tenant must not be deletable.');
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression('/adopted/i', $e->getMessage());
            $this->assertStringNotContainsString(
                'teardown',
                $e->getMessage(),
                'A teardown error means the connection was opened first -- one statement away from DROP DATABASE.',
            );
        }
    }

    /**
     * The control for the test above: with the same unusable credentials, a tenant that is NOT
     * adopted really does reach the connection and really does blow up there. Without this, the
     * clean refusal above could just as well mean the credentials were never used at all.
     */
    public function testTheSameCredentialsDoBlowUpWhenTheConnectionIsActuallyOpened(): void
    {
        $this->useUnusableProvisionCredentials();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/teardown/i');

        $this->provisioner->delete('paraiso');
    }

    // ========== Lo que el freno no puede romper ==========

    public function testANormalBusinessIsStillDeletable(): void
    {
        $this->assertTrue($this->provisioner->delete('paraiso'));
        $this->assertNull($this->rowFor('paraiso'), 'The registry row must be gone.');
    }

    public function testAnUnknownSlugIsStillReportedAsNotFound(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/i');

        $this->provisioner->delete('no-existe');
    }
}
