<?php

namespace Tests\Filters;

use App\Filters\PlatformHost;
use App\Libraries\PlatformContext;
use App\Libraries\TenantContext;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\App;
use Config\Database;
use Config\Filters;
use Config\OSPOS;

/**
 * PlatformHostFilterTest proves the filter decides correctly. This one proves it is actually
 * ATTACHED -- the failure that would leave the console visible from every client's address with a
 * green unit test right next to it.
 *
 * @internal
 */
final class PlatformHostWiringTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private const APEX = 'ospos-saas.micronuba.net';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    /**
     * @var array<string, mixed>
     */
    private array $originalGroup = [];

    protected function setUp(): void
    {
        parent::setUp();

        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();

        config(App::class)->platformHostnames        = [self::APEX];
        config(App::class)->allowedHostnameWildcards = ['.' . self::APEX];

        // A real request runs the whole chain, so TenantResolver looks the business up for real.
        // Its schema is the test schema itself: the point here is the filter's decision, not the
        // isolation, which TenantResolverTest already covers.
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
        $platform->table('tenants')->insert([
            'slug'    => 'paraisodelacanasta',
            'db_name' => config(Database::class)->{config(Database::class)->defaultGroup}['database'],
            'db_user' => '',
            'status'  => 'active',
        ]);

        $this->originalGroup = config(Database::class)->{config(Database::class)->defaultGroup};

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

    public function testTheFilterIsRegisteredForEveryPlatformUri(): void
    {
        $filters = config(Filters::class);

        $this->assertArrayHasKey('platformhost', $filters->aliases);
        $this->assertSame(PlatformHost::class, $filters->aliases['platformhost']);
        $this->assertSame(
            ['platform', 'platform/*'],
            $filters->filters['platformhost']['before'] ?? null,
            'Both the bare segment and everything under it: platform/admin/<slug>/delete is a '
            . 'platform URI too.',
        );
    }

    /**
     * End to end through the framework's own filter machinery, which is the part a unit test
     * cannot check: that the URI patterns above really match, and that this filter runs AFTER
     * tenantresolver so a resolved business is already in context by the time it decides.
     */
    public function testAResolvedBusinessGetsNotFoundOnTheConsoleLogin(): void
    {
        service('superglobals')->setServer('HTTP_HOST', 'paraisodelacanasta.' . self::APEX);

        $result = $this->call('get', 'platform/login');

        $this->assertSame(404, $result->response()->getStatusCode());
        $this->assertStringNotContainsString(self::APEX, (string) $result->response()->getBody());
    }

    public function testTheSameIsTrueOfTheAdminPanelItself(): void
    {
        service('superglobals')->setServer('HTTP_HOST', 'paraisodelacanasta.' . self::APEX);

        $result = $this->call('get', 'platform/admin');

        $this->assertSame(404, $result->response()->getStatusCode());
    }

    /**
     * The other half: the routes still work where they are supposed to. Without this, "nobody can
     * reach the console" would pass too.
     */
    public function testTheConsoleLoginStillAnswersOnItsOwnAddress(): void
    {
        service('superglobals')->setServer('HTTP_HOST', self::APEX);

        $result = $this->call('get', 'platform/login');

        $this->assertSame(200, $result->response()->getStatusCode());
    }

    /**
     * Nothing outside `platform` is touched. The point of sale is every other URI in this
     * application, and it is what the business is selling through.
     */
    public function testNoOtherUriGoesThroughThisFilter(): void
    {
        $filters = config(Filters::class);

        foreach ($filters->filters as $alias => $rules) {
            if ($alias === 'platformhost') {
                continue;
            }

            $this->assertNotContains(PlatformHost::class, (array) $alias);
        }

        $this->assertCount(1, $filters->filters, 'This filter is the only URI-scoped one in the project.');
    }
}
