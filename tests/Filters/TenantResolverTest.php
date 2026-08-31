<?php

namespace Tests\Filters;

use App\Filters\TenantResolver;
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
 */
class TenantResolverTest extends CIUnitTestCase
{
    private const SUFFIX = '.negocios.prueba';

    private string $originalDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(App::class)->allowedHostnameWildcards = [self::SUFFIX];

        $this->createRegistry();
        $this->seed('activo', 'active');
        $this->seed('suspendido', 'suspended');

        $db = config(Database::class);
        $this->originalDatabase = $db->{$db->defaultGroup}['database'];

        TenantContext::reset();
    }

    protected function tearDown(): void
    {
        $db = config(Database::class);
        $db->{$db->defaultGroup}['database'] = $this->originalDatabase;

        db_connect('platform')->query('DROP TABLE IF EXISTS `tenants`');
        TenantContext::reset();

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
            )'
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
            new UserAgent()
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
        $this->assertSame($this->originalDatabase, $this->activeDatabase(), 'The connection must not be touched.');
    }

    public function testTheBareWildcardDomainIsAlsoLeftAlone(): void
    {
        // "negocios.prueba" ends with ".negocios.prueba" only if you count the empty slug; the
        // filter must read that as "not a tenant request" rather than as a business named "".
        $response = $this->runFor(ltrim(self::SUFFIX, '.'));

        $this->assertNull($response);
        $this->assertSame($this->originalDatabase, $this->activeDatabase());
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
            $this->originalDatabase,
            $this->activeDatabase(),
            'A refused request must not have swapped the connection.'
        );
        $this->assertNull(TenantContext::slug(), 'Nothing was resolved, so nothing may be in context.');
    }

    public function testAnUnknownBusinessIsRefusedWithNotFound(): void
    {
        $response = $this->runFor('nadie' . self::SUFFIX);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame($this->originalDatabase, $this->activeDatabase());
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
        $suspended = $this->runFor('suspendido' . self::SUFFIX);
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
}
