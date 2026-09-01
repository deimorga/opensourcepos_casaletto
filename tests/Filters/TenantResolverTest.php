<?php

namespace Tests\Filters;

use App\Filters\TenantResolver;
use App\Libraries\PlatformContext;
use App\Libraries\TenantContext;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Database;

/**
 * Covers the filter that decides which business a request belongs to.
 *
 * This runs before everything on every request, so its failure modes are the expensive kind. Two
 * of them matter more than the rest and each has its own test below:
 *
 *   - A host that is NOT under a tenant wildcard -- Casaletto's own address, staging, localhost --
 *     must pass through untouched. Breaking this takes the trading business offline.
 *   - A host that IS under the wildcard but has no active business must be REFUSED, not served the
 *     default schema. Until 2026-08-30 it was served, which meant suspending a business handed it
 *     another business's till.
 *   - The platform console's own address must be repointed at the control schema. It matches no
 *     wildcard either, so before this branch existed it would have taken the first path above --
 *     and the console that administers every business would have run on the database of the
 *     business that is currently trading.
 *
 * @internal
 */
final class TenantResolverTest extends CIUnitTestCase
{
    private const SUFFIX = '.negocios.prueba';
    private const APEX   = 'negocios.prueba.consola';

    /**
     * @var array<string, mixed>
     */
    private array $originalGroup;

    protected function setUp(): void
    {
        parent::setUp();

        config(App::class)->allowedHostnameWildcards = [self::SUFFIX];
        config(App::class)->platformHostnames        = [self::APEX];

        $this->createRegistry();
        $this->seed('activo', 'active');
        $this->seed('suspendido', 'suspended');

        // All four connection keys, not just `database`. The filter swaps host, user and password
        // too when a tenant carries its own credentials, and the platform branch always does; a
        // teardown that restored only the schema would leave every later case in this file -- and
        // every later file in the run -- connecting as somebody else.
        $db                  = config(Database::class);
        $this->originalGroup = $db->{$db->defaultGroup};

        TenantContext::reset();
        PlatformContext::reset();
    }

    protected function tearDown(): void
    {
        $db = config(Database::class);

        foreach (['hostname', 'username', 'password', 'database'] as $key) {
            $db->{$db->defaultGroup}[$key] = $this->originalGroup[$key];
        }

        db_connect('platform')->query('DROP TABLE IF EXISTS `tenants`');
        TenantContext::reset();
        PlatformContext::reset();

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
                status VARCHAR(20) NOT NULL DEFAULT "active"
            )',
        );
    }

    private function seed(string $slug, string $status): void
    {
        db_connect('platform')->table('tenants')->insert([
            'slug'    => $slug,
            'db_name' => 'esquema_de_' . $slug,
            'db_user' => '',
            'status'  => $status,
        ]);
    }

    /**
     * @return ResponseInterface|null whatever the filter returned: a refusal, or null to continue
     */
    private function runFor(string $host)
    {
        // Two traps here, and both make these tests pass while proving nothing if missed.
        //
        // The request is built by hand rather than through Services::request(): under PHPUnit the
        // framework is in CLI mode and hands back a CLIRequest, whose getServer() is always null.
        //
        // And the Host goes through the `superglobals` service, not $_SERVER directly: since
        // CodeIgniter 4.7 the request reads its server array from that service, which snapshots
        // $_SERVER when it is first built -- before any of this runs. Writing to $_SERVER here
        // changes nothing the request will ever see.
        service('superglobals')->setServer('HTTP_HOST', $host);

        $request = new IncomingRequest(
            config(App::class),
            new SiteURI(config(App::class), 'https://' . $host . '/'),
            null,
            new UserAgent(),
        );

        return (new TenantResolver())->before($request);
    }

    private function activeDatabase(): string
    {
        $db = config(Database::class);

        return $db->{$db->defaultGroup}['database'];
    }

    // ========== El camino heredado, que no se puede romper ==========

    /**
     * The most important assertion in this file. Casaletto trades on an address that matches no
     * wildcard; if this filter ever touches that request, the shop stops.
     */
    public function testAHostOutsideTheWildcardIsLeftCompletelyAlone(): void
    {
        $response = $this->runFor('pos-casaletto.micronuba.net');

        $this->assertNull($response, 'A non-tenant host must continue to the application.');
        $this->assertSame($this->originalGroup['database'], $this->activeDatabase(), 'The connection must not be touched.');
    }

    public function testTheBareWildcardDomainIsAlsoLeftAlone(): void
    {
        // "negocios.prueba" ends with ".negocios.prueba" only if you count the empty slug; the
        // filter must read that as "not a tenant request" rather than as a business named "".
        $response = $this->runFor(ltrim(self::SUFFIX, '.'));

        $this->assertNull($response);
        $this->assertSame($this->originalGroup['database'], $this->activeDatabase());
    }

    // ========== El caso normal ==========

    public function testAnActiveBusinessGetsItsOwnSchema(): void
    {
        $response = $this->runFor('activo' . self::SUFFIX);

        $this->assertNull($response, 'An active business is served, not refused.');
        $this->assertSame('esquema_de_activo', $this->activeDatabase());
        $this->assertSame('activo', TenantContext::slug());
    }

    // ========== Lo que se arregló el 2026-08-30 ==========

    /**
     * The bug that prompted this file. Suspending a business used to hand it Casaletto's database.
     */
    public function testASuspendedBusinessIsRefusedAndNeverSeesTheDefaultSchema(): void
    {
        $response = $this->runFor('suspendido' . self::SUFFIX);

        $this->assertInstanceOf(ResponseInterface::class, $response, 'A suspended business must be refused.');
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(
            $this->originalGroup['database'],
            $this->activeDatabase(),
            'A refused request must not have swapped the connection.',
        );
        $this->assertNull(TenantContext::slug(), 'Nothing was resolved, so nothing may be in context.');
    }

    public function testAnUnknownBusinessIsRefusedWithNotFound(): void
    {
        $response = $this->runFor('nadie' . self::SUFFIX);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame($this->originalGroup['database'], $this->activeDatabase());
    }

    /**
     * The two failures have to be distinguishable, or a suspended client and a typo produce the
     * same support call.
     */
    public function testSuspendedAndUnknownDoNotLookTheSame(): void
    {
        // Read out immediately, not held as two objects: the filter returns service('response'),
        // which is shared, so a second call hands back the SAME instance with the first one's
        // values overwritten. Harmless in production -- one request, one response -- and a silent
        // false pass here, since both variables would show whatever the last call left behind.
        $suspended       = $this->runFor('suspendido' . self::SUFFIX);
        $suspendedStatus = $suspended->getStatusCode();
        $suspendedBody   = (string) $suspended->getBody();

        $unknown = $this->runFor('nadie' . self::SUFFIX);

        $this->assertSame(503, $suspendedStatus);
        $this->assertSame(404, $unknown->getStatusCode());
        $this->assertNotSame($suspendedBody, (string) $unknown->getBody());
    }

    /**
     * The refusal explains itself in the language the cashier reads, and explains itself WITHOUT
     * touching a database: rendering an OSPOS view would read app_config over the very connection
     * this request must not use.
     */
    public function testTheRefusalIsSelfContainedAndSaysSomethingUseful(): void
    {
        $body = (string) $this->runFor('suspendido' . self::SUFFIX)->getBody();

        $this->assertStringContainsString('suspendido', $body);
        $this->assertStringStartsWith('<!doctype html>', $body);
        $this->assertStringNotContainsString('<link', $body, 'No stylesheet: the page must stand alone.');
        $this->assertStringNotContainsString('<script', $body, 'No script either.');
    }

    // ========== La consola de plataforma ==========

    private function activeGroup(): array
    {
        $db = config(Database::class);

        return $db->{$db->defaultGroup};
    }

    /**
     * The reason the platform branch had to be added before the console could be served anywhere.
     * The apex matches no wildcard, so without it this request took the legacy path and every
     * default connection the console opened landed in a client's database.
     */
    public function testTheConsoleNeverRunsOnABusinessDatabase(): void
    {
        // In this environment the platform group deliberately points at the same schema as the
        // default one -- the test user cannot create a second database (see phpunit.xml.dist) --
        // so "it changed" has to be shown against a schema name that is unmistakably a client's.
        $db                                  = config(Database::class);
        $db->{$db->defaultGroup}['database'] = 'esquema_de_un_cliente';

        $response = $this->runFor(self::APEX);

        $this->assertNull($response, 'The console is served, not refused.');
        $this->assertNotSame('esquema_de_un_cliente', $this->activeGroup()['database']);
        $this->assertSame(
            config(Database::class)->platform['database'],
            $this->activeGroup()['database'],
            'The active connection must be repointed at the control schema.',
        );
    }

    /**
     * All four keys, not just the schema name. In production the control schema is reachable with
     * the shared credentials today, but a tenant that carries its own user leaves those credentials
     * in place on this array -- so copying `database` alone would try to open platform_control as
     * some client's restricted database user. It would fail in a way that looks like an outage of
     * the console rather than a configuration mistake.
     */
    public function testTheConsoleTakesTheWholeControlConnectionAndNotJustTheSchemaName(): void
    {
        // Leave the active group looking like a request that already resolved a tenant with its own
        // credentials, which is what a warm process can genuinely look like.
        $db                                  = config(Database::class);
        $db->{$db->defaultGroup}['username'] = 'usuario_de_un_cliente';
        $db->{$db->defaultGroup}['password'] = 'clave_de_un_cliente';

        $this->runFor(self::APEX);

        $platform = config(Database::class)->platform;

        foreach (['hostname', 'username', 'password', 'database'] as $key) {
            $this->assertSame($platform[$key], $this->activeGroup()[$key], "The `{$key}` key was left behind.");
        }
    }

    public function testTheConsoleIsNotATenantAndSaysSo(): void
    {
        $this->runFor(self::APEX);

        $this->assertTrue(PlatformContext::isResolved(), 'Everything downstream reads this to know where it is.');
        $this->assertFalse(TenantContext::isResolved(), 'The console belongs to no business.');
        $this->assertNull(TenantContext::slug());
    }

    /**
     * The apex is fetched from the registry by nobody: it is not a slug, and looking it up would
     * mean the console could be taken down by a row in a table it administers.
     */
    public function testTheConsoleIsServedEvenWithNoTenantRegistryAtAll(): void
    {
        db_connect('platform')->query('DROP TABLE IF EXISTS `tenants`');

        $this->assertNull($this->runFor(self::APEX));
        $this->assertTrue(PlatformContext::isResolved());
    }

    /**
     * Somebody could register a business whose slug spells the console's own address. The console
     * is decided by an exact host match, not by the registry, so the business is served its own
     * schema and the console is untouched.
     */
    public function testABusinessSubdomainIsStillABusinessEvenNextToTheConsole(): void
    {
        $this->runFor('activo' . self::SUFFIX);

        $this->assertSame('esquema_de_activo', $this->activeGroup()['database']);
        $this->assertFalse(PlatformContext::isResolved());
        $this->assertSame('activo', TenantContext::slug());
    }

    public function testCasalettosOwnAddressIsStillNotTheConsole(): void
    {
        $this->runFor('pos-casaletto.micronuba.net');

        $this->assertFalse(PlatformContext::isResolved());
        $this->assertSame($this->originalGroup['database'], $this->activeGroup()['database']);
    }
}
