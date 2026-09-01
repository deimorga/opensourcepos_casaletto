<?php

namespace App\Filters;

use App\Libraries\PlatformContext;
use App\Libraries\TenantContext;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;

/**
 * Keeps the platform console to the one address it belongs at.
 *
 * The routes under `platform/*` are registered globally, and CodeIgniter's routes know nothing
 * about the Host, so until this filter existed
 * https://<cualquier-cliente>.ospos-saas.micronuba.net/platform/login answered 200: the console
 * that administers every business was visible from the address of every business.
 *
 * WHY A FILTER AND NOT ['hostname' => ...] ON THE ROUTES
 *
 * RouteCollection snapshots HTTP_HOST when it is constructed, and under PHPUnit the request is a
 * CLIRequest with no host at all -- so a host-restricted route simply would not exist in any
 * feature test in this suite. A filter reads the Host from the request it is handed, which is both
 * testable and honest about when the decision is made.
 *
 * THREE BRANCHES, IN THIS ORDER
 *
 * 1. The console's own address: serve it.
 *
 * 2. A business's subdomain, resolved by TenantResolver: 404, self-contained, and NOT a redirect.
 *    Redirecting would be teaching a client -- and anyone probing their address -- where the panel
 *    that administers all of them lives, so the body does not mention it either. It renders no view
 *    and touches no database, exactly like TenantResolver::refuse(): on that host the connection in
 *    reach belongs to a business, and reading it to say "you are in the wrong place" would be worse
 *    than the problem.
 *
 * 3. Anything else -- Casaletto's own legacy address, where the panel used to live, plus staging
 *    and local hosts: 302 to the console, keeping the path. 302 and not 301: the old address is a
 *    link in somebody's bookmarks and has to keep working, but a 301 is cached by the browser
 *    forever and cannot be taken back if the console ever moves again.
 */
class PlatformHost implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $host          = $request->getServer('HTTP_HOST') ?? '';
        $isConsolePath = $this->isConsolePath($request);

        if (PlatformContext::matches($host)) {
            if ($isConsolePath) {
                return;
            }

            // On the console's own address, NOTHING but the console exists.
            //
            // Found in production on 2026-09-01, minutes after this filter first shipped scoped to
            // `platform/*` only: opening the apex at "/" fell through to OSPOS's default controller
            // -- the point-of-sale login -- which, because TenantResolver points the default
            // connection at platform_control here, decided the schema needed migrating and offered
            // a "Migrate" button. That button posts to Login::migrate(), which takes NO credentials
            // and runs the App namespace's migrations against the default connection. One
            // unauthenticated POST would have built the entire POS schema inside the platform's own
            // control database.
            //
            // The root path is a redirect because somebody typing the bare address means the
            // console. Every other path is refused outright, and refused for every method, so no
            // POST can reach a controller from here.
            return $this->isRootPath($request)
                ? $this->redirectToConsole($request, 'platform/login')
                : $this->refuse();
        }

        // A business's own site: the filter has no opinion about anything but console paths.
        if (! $isConsolePath) {
            return;
        }

        // A resolved business, or a host under a tenant wildcard that resolved to nothing. The
        // second can only be reached if TenantResolver ever stops refusing first; it is refused
        // here too rather than redirected, because "your address does not host the console" must
        // not be the same answer as "here is where the console is".
        if (TenantContext::isResolved() || $this->looksLikeABusinessAddress($host)) {
            return $this->refuse();
        }

        return $this->redirectToConsole($request);
    }

    /**
     * Is this request aimed at the console? Matched on the route path, so it is the same answer the
     * router would give, and it covers `platform` itself as well as everything beneath it without
     * also catching a business route that merely starts with those letters.
     */
    private function isConsolePath(RequestInterface $request): bool
    {
        $path = ltrim($this->routePath($request), '/');

        return $path === 'platform' || str_starts_with($path, 'platform/');
    }

    private function isRootPath(RequestInterface $request): bool
    {
        return ltrim($this->routePath($request), '/') === '';
    }

    /**
     * @param string|null $targetPath where to send them, when it is not "the same path over there".
     *                                The root of the console's own host MUST override it: keeping
     *                                the path would redirect "/" to "/", which is a loop.
     */
    private function redirectToConsole(RequestInterface $request, ?string $targetPath = null): ?ResponseInterface
    {
        $hostnames = config(App::class)->platformHostnames;

        if ($hostnames === []) {
            // No console configured. Inventing a destination would be worse than doing nothing.
            return null;
        }

        $path = $targetPath ?? $this->routePath($request);

        return service('response')
            ->setStatusCode(302)
            ->setBody('')
            ->removeHeader('Location')
            ->setHeader('Location', $this->consoleUrl($hostnames[0], $path));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }

    /**
     * Is this Host under one of the tenant wildcards? Same test TenantResolver uses to decide that
     * a Host belongs to the businesses' domain, kept here so an unregistered or suspended
     * subdomain is refused rather than pointed at the console.
     */
    private function looksLikeABusinessAddress(string $host): bool
    {
        foreach (config(App::class)->allowedHostnameWildcards as $suffix) {
            if ($suffix !== '' && str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The absolute URL of the same path on the console.
     *
     * The path is caller-controlled, so it is stripped of leading slashes before being appended:
     * "//evil.example.com/x" would otherwise turn "https://<consola>/" + path into a protocol-
     * relative URL pointing somewhere else entirely. The query string is deliberately dropped --
     * no platform route uses one, and not reflecting caller-supplied text into a Location header is
     * free.
     *
     * getPath() rather than getUri()->getPath(): the first is the ROUTE path, the second includes
     * whatever subdirectory the application is served from. Since baseURL is built from
     * SCRIPT_NAME, under PHPUnit that subdirectory is "/vendor/bin/" -- which would be appended to
     * the console's address and produce a link to nowhere.
     */
    private function consoleUrl(string $platformHostname, string $path): string
    {
        $scheme = config(App::class)->https_on ? 'https' : 'http';

        return $scheme . '://' . $platformHostname . '/' . ltrim($path, '/');
    }

    private function routePath(RequestInterface $request): string
    {
        return $request instanceof IncomingRequest
            ? $request->getPath()
            : $request->getUri()->getPath();
    }

    /**
     * The refusal page: self-contained, no view, no database, and no mention of the console.
     *
     * Deliberately indistinguishable from any other 404 on a business's own site. Whoever landed
     * here typed or followed an address that does not exist there, and that is all they learn.
     */
    private function refuse(): ResponseInterface
    {
        $title  = 'Página no encontrada';
        $detail = 'La dirección que abrió no existe en este sitio.';

        $body = '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>' . esc($title) . '</title>'
            . '<style>body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f7f7f8;'
            . 'color:#24292f;display:flex;min-height:100vh;align-items:center;justify-content:center;'
            . 'margin:0;padding:1.5rem}main{max-width:32rem;text-align:center}'
            . 'h1{font-size:1.35rem;margin:0 0 .6rem}p{margin:0;line-height:1.55;color:#57606a}</style>'
            . '</head><body><main><h1>' . esc($title) . '</h1><p>' . esc($detail) . '</p></main></body></html>';

        // Location is removed rather than merely not set. service('response') is a SHARED instance:
        // anything that put a Location on it earlier in the request would still be there, and a 404
        // that carries a Location is a redirect to whatever that was. Caught by a test that only
        // failed when the whole suite ran in one process -- which is exactly how it would have gone
        // unnoticed here.
        return service('response')
            ->setStatusCode(404)
            ->setContentType('text/html')
            ->removeHeader('Location')
            ->setBody($body);
    }
}
