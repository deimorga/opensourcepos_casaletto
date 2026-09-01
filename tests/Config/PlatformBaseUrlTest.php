<?php

namespace Tests\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * Config\App builds baseURL once, in its constructor, from a host it has validated against a
 * whitelist. Until PLATFORM_HOSTNAMES existed, the console's apex was not on any of those lists,
 * so it took the "rejected host" path and baseURL became allowedHostnames[0] -- a BUSINESS's
 * address.
 *
 * That is not cosmetic. Every URL the console emits comes from base_url(), including the action of
 * its own login form, so the superadministrator's password would have been posted to a client's
 * domain. This file exists to keep that from coming back.
 *
 * @internal
 */
final class PlatformBaseUrlTest extends CIUnitTestCase
{
    private const APEX   = 'ospos-saas.micronuba.net';
    private const CLIENT = 'pos-casaletto.micronuba.net';

    /**
     * @var array<string, mixed>
     */
    private array $savedServer = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['HTTP_HOST', 'ALLOWED_HOSTNAMES', 'ALLOWED_HOSTNAME_WILDCARDS', 'PLATFORM_HOSTNAMES'] as $key) {
            $this->savedServer[$key] = $_SERVER[$key] ?? null;
        }

        // Mirrors production: one business on its own legacy address, every other business on a
        // subdomain wildcard, and the console on the apex of that same wildcard domain.
        $_SERVER['ALLOWED_HOSTNAMES']          = self::CLIENT;
        $_SERVER['ALLOWED_HOSTNAME_WILDCARDS'] = '.' . self::APEX;
        $_SERVER['PLATFORM_HOSTNAMES']         = self::APEX;
    }

    protected function tearDown(): void
    {
        foreach ($this->savedServer as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }

        parent::tearDown();
    }

    private function baseUrlFor(string $host): string
    {
        $_SERVER['HTTP_HOST'] = $host;

        return (new App())->baseURL;
    }

    public function testTheEnvironmentVariableIsReadIntoTheConfiguredList(): void
    {
        $_SERVER['PLATFORM_HOSTNAMES'] = ' ' . self::APEX . ' , , staging.' . self::APEX . ' ';

        $this->assertSame(
            [self::APEX, 'staging.' . self::APEX],
            (new App())->platformHostnames,
            'Whitespace is trimmed and empty entries dropped, same as ALLOWED_HOSTNAMES.',
        );
    }

    /**
     * The reason this file exists.
     */
    public function testTheConsoleBuildsItsUrlsAgainstItsOwnApexAndNotAgainstABusiness(): void
    {
        $baseUrl = $this->baseUrlFor(self::APEX);

        $this->assertStringContainsString(self::APEX, $baseUrl);
        $this->assertStringNotContainsString(self::CLIENT, $baseUrl, 'The console must never post to a client domain.');
    }

    /**
     * The received Host is not echoed back into baseURL. Traefik routes case-insensitively, so a
     * request with a strangely-cased Host arrives here; returning it verbatim would put
     * caller-chosen bytes into every link on the page.
     */
    public function testAnOddlyCasedApexIsAcceptedButCanonicalised(): void
    {
        $baseUrl = $this->baseUrlFor('OSPOS-SaaS.Micronuba.NET');

        $this->assertStringContainsString(self::APEX, $baseUrl);
        $this->assertStringNotContainsString('SaaS', $baseUrl);
    }

    // ========== Lo que no puede cambiar ==========

    public function testCasalettosOwnAddressIsUntouched(): void
    {
        $this->assertStringContainsString(self::CLIENT, $this->baseUrlFor(self::CLIENT));
    }

    public function testABusinessSubdomainIsStillServedUnderItsOwnAddress(): void
    {
        $host = 'paraisodelacanasta.' . self::APEX;

        $this->assertStringContainsString($host, $this->baseUrlFor($host));
    }

    public function testAnUnknownHostStillFallsBackToTheFirstAllowedHostname(): void
    {
        $this->assertStringContainsString(self::CLIENT, $this->baseUrlFor('quien-sabe.example.com'));
    }

    /**
     * A deployment configured with a platform host and nothing else used to read index 0 of an
     * empty allowedHostnames array on this path. No such deployment exists today, which is exactly
     * why nobody would have found it before it happened.
     */
    public function testAPlatformOnlyConfigurationDoesNotReadPastTheEndOfAnEmptyList(): void
    {
        // Set to a value that is non-empty (so the .env fallback is not consulted) but contains no
        // usable entry, which is how a real misconfiguration would look.
        $_SERVER['ALLOWED_HOSTNAMES']          = ' , ';
        $_SERVER['ALLOWED_HOSTNAME_WILDCARDS'] = ' , ';

        $this->assertSame([], (new App())->allowedHostnames, 'Precondition: the list really is empty.');

        $this->assertStringContainsString(self::APEX, $this->baseUrlFor('quien-sabe.example.com'));
    }
}
