<?php

namespace Tests\Events;

use App\Events\Load_config;
use CodeIgniter\Config\Factories;
use CodeIgniter\Session\Handlers\ArrayHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\Mock\MockSession;
use Config\App;
use Config\OSPOS;
use Config\Services;

/**
 * Load_config runs on post_controller_constructor -- on EVERY request, before any controller does
 * anything -- and its first act is to destroy the session when the database schema is behind the
 * migration files. For the point of sale that is right: a half-migrated schema must not be sold
 * through.
 *
 * On the platform console it is fatal. The console's connection points at the control schema, which
 * has no `ospos_migrations` table at all, so get_current_version() returns 0, it can never equal
 * the newest migration's version, and the session would be destroyed ONCE PER REQUEST. The login
 * form would set a session and the very next request would throw it away: nobody could ever be
 * logged in, and nothing anywhere would say why.
 *
 * HOW THIS IS PROVED WITHOUT FAKING A HALF-MIGRATED DATABASE
 *
 * The obvious way to test "it does not destroy the session" is to put the schema out of date and
 * watch. That was tried, by inserting a future version into the migration history, and it is a trap:
 * every class in this suite with $refresh = true rolls the whole schema back and re-applies it, so a
 * stray history row leaves the database half-built for the NEXT full run. The suite then failed on a
 * dozen unrelated tests in tests/Models and passed again on the run after.
 *
 * The stronger assertion costs nothing instead. Load_config assigns `public Session $session` only
 * on the ordinary path; on the console it returns before that line. An unassigned typed property is
 * not `isset()`, so "the console never even asks for the session service" is directly observable --
 * and if the property is never assigned, destroy() is unreachable by construction, which is a better
 * guarantee than watching one particular call not happen.
 *
 * @internal
 */
final class LoadConfigPlatformTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    private const APEX = 'ospos-saas.micronuba.net';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    /**
     * @var array<string, mixed>
     */
    private array $savedServer = [];

    private RecordingSession $recordingSession;
    private int $savedScale;
    private string $savedTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();

        // load_config() writes PROCESS-WIDE state: bcmath's scale, PHP's default timezone and the
        // language service's locale. All three outlive the test, and bcscale decides how much
        // precision every later money calculation in the run keeps. Restored in tearDown.
        $this->savedScale    = bcscale();
        $this->savedTimezone = date_default_timezone_get();

        $this->savedServer['PLATFORM_HOSTNAMES'] = $_SERVER['PLATFORM_HOSTNAMES'] ?? null;
        $this->savedServer['HTTP_HOST']          = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['PLATFORM_HOSTNAMES']           = self::APEX;

        // The ordinary path really does call session(). A double keeps that from opening and
        // writing a database-backed session as a side effect of a test about configuration.
        $sessionConfig          = config('Session');
        $this->recordingSession = new RecordingSession(new ArrayHandler($sessionConfig, '0.0.0.0'), $sessionConfig);
        $this->recordingSession->setLogger(service('logger'));
        $this->recordingSession->start();
    }

    protected function tearDown(): void
    {
        bcscale($this->savedScale);
        date_default_timezone_set($this->savedTimezone);

        foreach ($this->savedServer as $key => $value) {
            if ($value === null) {
                service('superglobals')->unsetServer($key);
            } else {
                service('superglobals')->setServer($key, $value);
            }
        }

        Factories::reset('config');
        // Clears the injected double as well as the instance -- otherwise every later class in the
        // run would be handed this test's session.
        Services::resetSingle('session');
        Services::resetSingle('language');

        parent::tearDown();
    }

    private function runFor(string $host): Load_config
    {
        service('superglobals')->setServer('HTTP_HOST', $host);
        Factories::reset('config');
        Services::injectMock('session', $this->recordingSession);

        $event = new Load_config();
        $event->load_config();

        return $event;
    }

    // ========== La consola ==========

    /**
     * The assertion this file exists for.
     */
    public function testTheConsoleNeverReachesTheCodeThatDestroysTheSession(): void
    {
        $event = $this->runFor(self::APEX);

        $this->assertFalse(
            isset($event->session),
            'The console must return before the session is even fetched; the console cannot be '
            . 'logged into at all if every request throws the session away.',
        );
        $this->assertFalse($this->recordingSession->destroyed);
    }

    /**
     * The control. Without it, the assertion above could be satisfied by a Load_config that does
     * nothing at all for anybody.
     */
    public function testAnOrdinaryRequestStillGoesThroughTheMigrationCheck(): void
    {
        $event = $this->runFor('pos-casaletto.micronuba.net');

        $this->assertTrue(isset($event->session), 'The point of sale still checks its schema on every request.');
    }

    public function testTheConsoleFixesItsOwnLocaleInsteadOfReadingABusinessConfiguration(): void
    {
        $this->runFor(self::APEX);

        $this->assertSame('es-MX', Services::language()->getLocale());
    }

    public function testTheConsoleFixesItsOwnTimezone(): void
    {
        $this->runFor(self::APEX);

        $this->assertSame(config(App::class)->appTimezone, date_default_timezone_get());
    }

    /**
     * bcscale is process-wide and the console has no app_config to derive it from -- the ordinary
     * path reads currency and tax decimals out of a business's settings. Leaving it unset would
     * carry whatever the previous request happened to choose.
     */
    public function testTheConsoleLeavesUsableArithmeticPrecisionBehindIt(): void
    {
        $this->runFor(self::APEX);

        $this->assertGreaterThanOrEqual(2, bcscale());
    }

    /**
     * The console has no app_config to read, so it must not end up holding a business's settings
     * either -- see Tests\Config\OSPOSPlatformCacheTest for why the unsuffixed cache key is
     * Casaletto's.
     */
    public function testTheConsoleDoesNotAdoptABusinessConfiguration(): void
    {
        $this->runFor(self::APEX);

        $this->assertArrayNotHasKey('quantity_decimals', config(OSPOS::class)->settings);
    }
}

/**
 * A session that remembers whether it was destroyed, and that never touches the real PHP session.
 * Session::destroy() is a no-op under ENVIRONMENT=testing, so the call has to be observed rather
 * than its effect.
 */
class RecordingSession extends MockSession
{
    public bool $destroyed = false;

    public function destroy()
    {
        $this->destroyed = true;
    }
}
