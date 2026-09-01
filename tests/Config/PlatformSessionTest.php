<?php

namespace Tests\Config;

use CodeIgniter\Config\Factories;
use CodeIgniter\Session\Handlers\DatabaseHandler;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Session;

/**
 * Where each kind of request keeps its session.
 *
 * The console needs a table of its own, in the control schema, because on its address the default
 * connection deliberately points at nothing a business owns. Giving it one is easy. Giving it one
 * WITHOUT moving anybody else's is the whole difficulty, and it is why the negative case below
 * matters more than the positive one:
 *
 *   Casaletto's session rows live in `ospos_sessions` inside its own database. If
 *   PlatformContext::matches() ever answers true for one host too many, every logged-in cashier is
 *   thrown out on the next request -- not gradually, all at once, mid-service -- and the sessions
 *   they had are looked for in a table that does not exist for them.
 *
 * Hence the exact-match rule, and hence this file.
 *
 * @internal
 */
final class PlatformSessionTest extends CIUnitTestCase
{
    private const APEX = 'ospos-saas.micronuba.net';

    /**
     * @var array<string, mixed>
     */
    private array $savedServer = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Configured through the environment rather than by writing on the config object, because
        // every case below calls Factories::reset('config') -- which throws away Config\App along
        // with Config\Session. Set the property instead and the freshly built App would come back
        // with an empty list, and each case would quietly assert the "no console configured"
        // behaviour while claiming to assert something else.
        $this->savedServer['PLATFORM_HOSTNAMES'] = $_SERVER['PLATFORM_HOSTNAMES'] ?? null;
        $this->savedServer['HTTP_HOST']          = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['PLATFORM_HOSTNAMES']           = self::APEX;

        // The console's table has to genuinely exist, or Session's constructor falls back to file
        // storage and every assertion below would pass for the wrong reason.
        $platform = db_connect('platform');
        $platform->query('DROP TABLE IF EXISTS `platform_sessions`');
        $platform->query(
            'CREATE TABLE `platform_sessions` (
                id VARCHAR(128) NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
                data BLOB NOT NULL,
                PRIMARY KEY (id, ip_address)
            )',
        );
        $platform->resetDataCache();
    }

    protected function tearDown(): void
    {
        db_connect('platform')->query('DROP TABLE IF EXISTS `platform_sessions`');
        db_connect('platform')->resetDataCache();

        foreach ($this->savedServer as $key => $value) {
            if ($value === null) {
                service('superglobals')->unsetServer($key);
            } else {
                service('superglobals')->setServer($key, $value);
            }
        }

        Factories::reset('config');

        parent::tearDown();
    }

    /**
     * Config objects are cached by Factories for the whole process, so without this every case
     * after the first would be handed the first one's answer and the file would prove nothing.
     */
    private function sessionConfigFor(string $host): Session
    {
        service('superglobals')->setServer('HTTP_HOST', $host);
        Factories::reset('config');

        return config(Session::class);
    }

    // ========== La consola ==========

    public function testTheConsoleKeepsItsSessionInTheControlSchema(): void
    {
        $config = $this->sessionConfigFor(self::APEX);

        $this->assertSame('platform', $config->DBGroup);
        $this->assertSame('platform_sessions', $config->savePath);
    }

    /**
     * The latent defect this change also had to fix. Session's constructor checked whether its
     * table existed by calling Database::connect() with NO group, so with DBGroup 'platform' it
     * asked the DEFAULT connection about `platform_sessions` -- a table that by construction is not
     * there (different schema, and the default group prefixes everything with `ospos_`). The check
     * failed, the driver silently downgraded to file storage, and the console's brand new table
     * would have sat empty forever with nothing anywhere saying why.
     */
    public function testTheConsoleActuallyUsesTheDatabaseAndDoesNotSilentlyFallBackToFiles(): void
    {
        $this->assertSame(DatabaseHandler::class, $this->sessionConfigFor(self::APEX)->driver);
    }

    // ========== Todos los demás, que no se pueden mover ==========

    /**
     * The assertion that protects the business that is trading. Not "is not platform" -- the exact
     * values, because a wrong-but-plausible pair here empties the shop of its logged-in users.
     */
    public function testCasalettoKeepsItsSessionExactlyWhereItAlwaysHas(): void
    {
        $config = $this->sessionConfigFor('pos-casaletto.micronuba.net');

        $this->assertNull($config->DBGroup, 'Null means the default connection, which is this business.');
        $this->assertSame('sessions', $config->savePath);
    }

    public function testABusinessSubdomainKeepsItsSessionInItsOwnDatabase(): void
    {
        $config = $this->sessionConfigFor('paraisodelacanasta.' . self::APEX);

        $this->assertNull($config->DBGroup);
        $this->assertSame('sessions', $config->savePath);
    }

    /**
     * The specific mistake: a suffix comparison instead of an exact one. It answers true for the
     * host above and moves that business's sessions into the console's table.
     */
    public function testALookAlikeHostDoesNotGetTheConsolesSessionTable(): void
    {
        foreach (['evil' . self::APEX, self::APEX . '.evil.com', 'x.' . self::APEX] as $host) {
            $config = $this->sessionConfigFor($host);

            $this->assertNull($config->DBGroup, "{$host} must not be treated as the console.");
            $this->assertSame('sessions', $config->savePath);
        }
    }

    public function testWithNoConsoleConfiguredNothingChangesForAnybody(): void
    {
        service('superglobals')->unsetServer('PLATFORM_HOSTNAMES');
        service('superglobals')->setServer('HTTP_HOST', self::APEX);
        Factories::reset('config');

        $this->assertSame([], config(App::class)->platformHostnames, 'Precondition.');

        $config = config(Session::class);

        $this->assertNull($config->DBGroup);
        $this->assertSame('sessions', $config->savePath);
    }
}
