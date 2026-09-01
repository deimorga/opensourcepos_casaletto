<?php

namespace Tests\Filters;

use App\Filters\PlatformHost;
use App\Libraries\PlatformContext;
use App\Libraries\TenantContext;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * The console is supposed to live at one address. Until this filter existed it answered at every
 * address on the platform: https://<cualquier-cliente>.ospos-saas.micronuba.net/platform/login
 * returned 200, because the platform routes are registered globally and routes in CodeIgniter do
 * not know anything about the Host.
 *
 * Two decisions in here are worth more than the code:
 *
 *   A CLIENT'S SUBDOMAIN GETS 404, NOT A REDIRECT. Redirecting would be handing every client --
 *   and anyone poking at their address -- the address of the panel that administers all of them.
 *   The refusal does not name the console anywhere, and it renders no view and touches no database,
 *   for the same reason TenantResolver::refuse() does not: on that host the database in reach
 *   belongs to a business.
 *
 *   ANY OTHER HOST GETS 302, NOT 301. The old address of the panel has to keep working, but a 301
 *   is cached by the browser forever and there is no way to take it back if the console ever moves
 *   again.
 *
 * @internal
 */
final class PlatformHostFilterTest extends CIUnitTestCase
{
    private const APEX   = 'ospos-saas.micronuba.net';
    private const LEGACY = 'pos-casaletto.micronuba.net';
    private const TENANT = 'casaletto.ospos-saas.micronuba.net';

    protected function setUp(): void
    {
        parent::setUp();

        config(App::class)->platformHostnames        = [self::APEX];
        config(App::class)->allowedHostnameWildcards = ['.' . self::APEX];

        TenantContext::reset();
        PlatformContext::reset();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        PlatformContext::reset();

        parent::tearDown();
    }

    /**
     * Same two traps as TenantResolverTest: under PHPUnit Services::request() hands back a
     * CLIRequest whose getServer() is always null, and since CodeIgniter 4.7 the Host has to go
     * through the `superglobals` service rather than $_SERVER, which the request snapshots before
     * any of this runs.
     *
     * @return ResponseInterface|null what the filter returned: a refusal, a redirect, or null
     */
    private function runFor(string $host, string $path = 'platform/login')
    {
        service('superglobals')->setServer('HTTP_HOST', $host);

        $request = new IncomingRequest(
            config(App::class),
            new SiteURI(config(App::class), $path, $host),
            null,
            new UserAgent(),
        );

        return (new PlatformHost())->before($request);
    }

    // ========== La consola, donde sí vive ==========

    public function testTheConsoleIsServedOnItsOwnAddress(): void
    {
        $this->assertNull($this->runFor(self::APEX), 'The console must continue to the controller.');
    }

    public function testEveryPlatformPathIsServedThereAndNotOnlyTheLoginPage(): void
    {
        foreach (['platform', 'platform/login', 'platform/admin', 'platform/admin/paraiso/delete'] as $path) {
            $this->assertNull($this->runFor(self::APEX, $path), "{$path} must be served on the console.");
        }
    }

    // ========== El subdominio de un cliente: 404, y nada más ==========

    /**
     * The behaviour this filter was written for.
     */
    public function testAClientSubdomainIsRefusedWithNotFound(): void
    {
        TenantContext::set(2, 'paraisodelacanasta', 'tenant_paraisodelacanasta');

        $response = $this->runFor('paraisodelacanasta.' . self::APEX);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * The assertion that matters more than the status code: the refusal must not tell a client
     * where the panel that administers every client lives.
     */
    public function testTheRefusalNeverNamesTheConsole(): void
    {
        TenantContext::set(2, 'paraisodelacanasta', 'tenant_paraisodelacanasta');

        $response = $this->runFor('paraisodelacanasta.' . self::APEX);
        $body     = (string) $response->getBody();

        $this->assertStringNotContainsString(self::APEX, $body);
        $this->assertStringNotContainsString('platform', strtolower($body));
        $this->assertSame('', (string) $response->getHeaderLine('Location'), 'A refusal is not a redirect.');
    }

    /**
     * Self-contained for the same reason TenantResolver's refusal is: rendering an OSPOS view would
     * read app_config over a connection that belongs to a business.
     */
    public function testTheRefusalRendersNoViewAndLoadsNothing(): void
    {
        TenantContext::set(2, 'paraisodelacanasta', 'tenant_paraisodelacanasta');

        $body = (string) $this->runFor('paraisodelacanasta.' . self::APEX)->getBody();

        $this->assertStringStartsWith('<!doctype html>', $body);
        $this->assertStringNotContainsString('<link', $body, 'No stylesheet: the page must stand alone.');
        $this->assertStringNotContainsString('<script', $body, 'No script either.');
        $this->assertNotSame('', trim(strip_tags($body)), 'It still has to say something to whoever landed there.');
    }

    // ========== Cualquier otro host: 302 a la consola ==========

    /**
     * The panel used to live at Casaletto's address. That link is in somebody's bookmarks.
     */
    public function testTheOldAddressOfThePanelRedirectsToTheConsole(): void
    {
        $response = $this->runFor(self::LEGACY);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(302, $response->getStatusCode(), 'A 301 is cached forever and cannot be taken back.');
        $this->assertSame(self::APEX, parse_url($response->getHeaderLine('Location'), PHP_URL_HOST));
    }

    /**
     * Not getUri()->getPath(): that one carries the subdirectory the application is served from,
     * which under PHPUnit is "/vendor/bin/". Appending it to the console's address would send the
     * visitor to a page that does not exist.
     */
    public function testTheRedirectKeepsTheRequestedPathAndNothingElse(): void
    {
        $location = $this->runFor(self::LEGACY, 'platform/admin')->getHeaderLine('Location');

        $this->assertSame('/platform/admin', parse_url($location, PHP_URL_PATH));
    }

    /**
     * The path is attacker-influenced, and a path that starts with two slashes is the classic way
     * to turn "append the path" into a redirect to somebody else's site.
     */
    public function testTheRedirectCannotBeAimedAtAnotherSite(): void
    {
        // Desde el 2026-09-01 el filtro solo opina sobre rutas de consola, y "//evil…" no lo es:
        // en el host legacy pasa de largo, que es lo que debe hacer con el sitio de un negocio.
        $this->assertNull($this->runFor(self::LEGACY, '//evil.example.com/platform'));

        // Y cuando SI es una ruta de consola, el destino sigue siendo el apex y nadie mas.
        $location = $this->runFor(self::LEGACY, 'platform//evil.example.com')->getHeaderLine('Location');

        $this->assertSame(self::APEX, parse_url($location, PHP_URL_HOST));
    }

    /**
     * A client host that did NOT resolve -- an unregistered or suspended subdomain -- never gets
     * here in production, because TenantResolver refuses it first. If it ever did, it must not be
     * handed the console's address either.
     */
    public function testAnUnresolvedSubdomainOfTheClientDomainIsStillRefused(): void
    {
        $response = $this->runFor('nadie.' . self::APEX);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString(self::APEX, (string) $response->getBody());
    }

    /**
     * With no console configured -- a deployment that has not been given PLATFORM_HOSTNAMES yet --
     * there is nowhere to send anyone, and inventing a destination would be worse than doing
     * nothing. The legacy address keeps working exactly as it did.
     */
    public function testWithNoConsoleConfiguredNothingIsRedirected(): void
    {
        config(App::class)->platformHostnames = [];

        $this->assertNull($this->runFor(self::LEGACY));
    }

    // ===== Lo que se escapo el 2026-09-01, encontrado por el dueno en produccion =====

    public function testEnElApexNingunaOtraRutaLlegaAUnControlador(): void
    {
        // "/migrate" es Login::migrate(), que NO pide credenciales y corre las migraciones del POS
        // contra la conexion por defecto -- que en el apex es platform_control. Un solo POST sin
        // autenticar habria construido el esquema entero del punto de venta dentro de la base de
        // control de la plataforma.
        foreach (['migrate', 'login', 'sales', 'items/view/1'] as $path) {
            $response = $this->runFor(self::APEX, $path);

            $this->assertNotNull($response, "la ruta '$path' llego al controlador desde el apex");
            $this->assertSame(404, $response->getStatusCode(), "la ruta '$path' no fue rechazada");
            $this->assertNull($response->getHeaderLine('Location') ?: null, "la ruta '$path' redirige en vez de rechazar");
        }
    }

    public function testLaRaizDelApexLlevaALaConsola(): void
    {
        $response = $this->runFor(self::APEX, '');

        $this->assertNotNull($response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/platform/login', $response->getHeaderLine('Location'));
    }

    public function testUnNegocioSigueSirviendoSuPropioSitio(): void
    {
        // El filtro pasa a correr en TODAS las rutas: si opinara sobre las del punto de venta,
        // dejaria a los dos negocios sin poder vender.
        foreach (['', 'login', 'sales', 'items'] as $path) {
            $this->assertNull(
                $this->runFor(self::TENANT, $path),
                "el filtro interfirio con la ruta '$path' de un negocio"
            );
        }
    }
}
