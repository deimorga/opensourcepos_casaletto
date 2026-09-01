<?php

namespace Tests\Libraries;

use App\Libraries\PlatformContext;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * PlatformContext::matches() is the ONE definition of "this request is for the platform console".
 * Session storage, the settings cache, the config-loading event and two filters all ask it. If it
 * says yes for a host that belongs to a business, that business loses its sessions and its
 * settings in the same request -- so the cases that matter most here are the ones where it must
 * say NO.
 *
 * @internal
 */
final class PlatformContextTest extends CIUnitTestCase
{
    private const APEX = 'ospos-saas.micronuba.net';

    protected function setUp(): void
    {
        parent::setUp();

        config(App::class)->platformHostnames = [self::APEX];
        PlatformContext::reset();
    }

    protected function tearDown(): void
    {
        PlatformContext::reset();

        parent::tearDown();
    }

    public function testTheConfiguredApexMatches(): void
    {
        $this->assertTrue(PlatformContext::matches(self::APEX));
    }

    /**
     * The assertion that protects every business on the platform. A suffix comparison -- the
     * obvious way to write this -- would answer true here, and every tenant would be handed the
     * console's session table and the console's settings.
     */
    public function testABusinessSubdomainOfTheSameDomainDoesNotMatch(): void
    {
        $this->assertFalse(PlatformContext::matches('paraisodelacanasta.' . self::APEX));
        $this->assertFalse(PlatformContext::matches('casaletto.' . self::APEX));
    }

    public function testCasalettosOwnLegacyAddressDoesNotMatch(): void
    {
        $this->assertFalse(PlatformContext::matches('pos-casaletto.micronuba.net'));
    }

    /**
     * "evilospos-saas.micronuba.net" ends with the apex as a plain string. Only an exact match
     * keeps it out.
     */
    public function testALookAlikeDomainDoesNotMatch(): void
    {
        $this->assertFalse(PlatformContext::matches('evil' . self::APEX));
        $this->assertFalse(PlatformContext::matches(self::APEX . '.evil.com'));
    }

    public function testAnEmptyHostDoesNotMatch(): void
    {
        $this->assertFalse(PlatformContext::matches(''));
    }

    /**
     * Host headers are case-insensitive per RFC 7230 and Traefik's Host() matcher treats them that
     * way, so a request with an odd-cased Host reaches this container. It has to be recognised
     * here, because Config\App::getValidHost() recognises it too -- and if only one of the two
     * did, baseURL and the session would disagree about which application is being served.
     */
    public function testTheHostIsMatchedWithoutRegardToCase(): void
    {
        $this->assertTrue(PlatformContext::matches('OSPOS-SaaS.Micronuba.NET'));
    }

    public function testWithNothingConfiguredNothingIsThePlatform(): void
    {
        config(App::class)->platformHostnames = [];

        $this->assertFalse(PlatformContext::matches(self::APEX));
        $this->assertFalse(PlatformContext::matches(''));
    }

    // ========== La marca que deja TenantResolver ==========

    public function testResolutionIsNotAssumedUntilTheFilterSaysSo(): void
    {
        $this->assertFalse(PlatformContext::isResolved());

        PlatformContext::markResolved();

        $this->assertTrue(PlatformContext::isResolved());
    }

    public function testResetClearsTheMark(): void
    {
        PlatformContext::markResolved();
        PlatformContext::reset();

        $this->assertFalse(PlatformContext::isResolved());
    }

    /**
     * isPlatform() is derived from the Host and not from the mark, because Config\Session and
     * Config\OSPOS are built at moments when the filter may not have run yet (spark, a test, a
     * request that died in an earlier filter). Deriving it means those two can never disagree
     * with the router about which application this is.
     */
    public function testIsPlatformFollowsTheRequestHostWithoutWaitingForTheFilter(): void
    {
        service('superglobals')->setServer('HTTP_HOST', self::APEX);
        $this->assertTrue(PlatformContext::isPlatform());

        service('superglobals')->setServer('HTTP_HOST', 'pos-casaletto.micronuba.net');
        $this->assertFalse(PlatformContext::isPlatform());
    }

    /**
     * `php spark` has no Host at all. It must never look like the console: the platform migration
     * command already picks its own connection, and a CLI run that thought it was the console
     * would take the settings-cache short circuit with it.
     */
    public function testACommandLineRunWithNoHostIsNotThePlatform(): void
    {
        service('superglobals')->unsetServer('HTTP_HOST');

        $this->assertFalse(PlatformContext::isPlatform());
    }
}
