<?php

namespace Tests\Config;

use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;

/**
 * The settings cache, and why the console must not go near it.
 *
 * Config\OSPOS caches a business's whole configuration -- name, currency, decimals, taxes, language
 * -- under a key suffixed with the resolved tenant slug. When nothing resolves, the key is the bare
 * string 'settings'.
 *
 * The trap is that CASALETTO DOES NOT RESOLVE EITHER. It trades on its own legacy address, which
 * matches no wildcard, so its configuration is the thing living under 'settings'. The console does
 * not resolve as a tenant, so without a short circuit it would read that key and show Casaletto's
 * configuration as its own -- and, worse, write it back with whatever it managed to load from the
 * control schema, which has no app_config at all. The console would be quietly rewriting the
 * settings of the business that is trading, once per deploy, with defaults.
 *
 * So on the console the settings are the built-in defaults, and the cache is neither read nor
 * written. This file is the proof.
 *
 * @internal
 */
final class OSPOSPlatformCacheTest extends CIUnitTestCase
{
    private const APEX      = 'ospos-saas.micronuba.net';
    private const CACHE_KEY = 'settings';

    /**
     * @var array<string, mixed>
     */
    private array $savedServer = [];

    /**
     * @var array<string, string>
     */
    private array $sentinel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->savedServer['PLATFORM_HOSTNAMES'] = $_SERVER['PLATFORM_HOSTNAMES'] ?? null;
        $this->savedServer['HTTP_HOST']          = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['PLATFORM_HOSTNAMES']           = self::APEX;

        // Casaletto's configuration, as it sits in the shared cache under the unsuffixed key.
        $this->sentinel = encode_array([
            'company'           => 'Casaletto, que está vendiendo',
            'language_code'     => 'es-MX',
            'quantity_decimals' => '3',
            'barcode_content'   => 'item_number',
        ]);

        service('cache')->save(self::CACHE_KEY, $this->sentinel);
    }

    protected function tearDown(): void
    {
        service('cache')->delete(self::CACHE_KEY);

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

    private function buildConfigFor(string $host): OSPOS
    {
        service('superglobals')->setServer('HTTP_HOST', $host);
        Factories::reset('config');

        return new OSPOS();
    }

    // ========== La consola ==========

    /**
     * The assertion this file exists for: building the console's configuration leaves Casaletto's
     * cached configuration byte for byte as it was.
     */
    public function testBuildingTheConsoleConfigurationLeavesTheBusinessCacheIntact(): void
    {
        $this->buildConfigFor(self::APEX);

        $this->assertSame($this->sentinel, service('cache')->get(self::CACHE_KEY));
    }

    public function testTheConsoleDoesNotEvenReadTheBusinessCache(): void
    {
        $settings = $this->buildConfigFor(self::APEX)->settings;

        $this->assertArrayNotHasKey('quantity_decimals', $settings, 'That value belongs to a business, not to the console.');
        $this->assertNotSame('Casaletto, que está vendiendo', $settings['company'] ?? null);
    }

    public function testTheConsoleStillGetsUsableDefaults(): void
    {
        $settings = $this->buildConfigFor(self::APEX)->settings;

        $this->assertArrayHasKey('language_code', $settings, 'Half the framework reads this key without checking.');
        $this->assertArrayHasKey('company', $settings);
    }

    /**
     * update_settings() DELETES before it rebuilds. Left unguarded it is the most destructive of
     * the two paths: every console request that refreshed its settings would throw away the cached
     * configuration of the business that is trading, and the next sale would pay to rebuild it.
     */
    public function testRefreshingTheConsoleConfigurationDoesNotDeleteTheBusinessCache(): void
    {
        $config = $this->buildConfigFor(self::APEX);

        $config->update_settings();

        $this->assertSame($this->sentinel, service('cache')->get(self::CACHE_KEY));
    }

    // ========== Un negocio, que sigue igual ==========

    /**
     * The other half of the guarantee: an ordinary request still reads the cache it always read.
     * Without this, "the console does not touch the cache" could be satisfied by nobody using it.
     */
    public function testAnOrdinaryRequestStillReadsTheCachedSettings(): void
    {
        $settings = $this->buildConfigFor('pos-casaletto.micronuba.net')->settings;

        $this->assertSame('Casaletto, que está vendiendo', $settings['company'] ?? null);
        $this->assertSame('3', $settings['quantity_decimals'] ?? null);
    }
}
