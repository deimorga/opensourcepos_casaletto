<?php

namespace App\Libraries;

use Config\App;

/**
 * The mirror image of TenantContext (app/Libraries/TenantContext.php): where that one answers
 * "which business is this request for", this one answers "is this request for the platform
 * console at all". Same shape on purpose -- a static holder rather than a Service, so that the
 * two configuration classes that must ask it (Config\Session, Config\OSPOS) can do so from inside
 * their own constructors, which is far too early for dependency injection.
 *
 * matches() IS THE DEFINITION, AND THERE MUST BE ONLY ONE
 *
 * Six places need to know whether a Host belongs to the console: TenantResolver, PlatformHost,
 * Config\Session, Config\OSPOS, Load_config and Config\App::getValidHost(). Written out six
 * times, five of them stay right and one drifts -- and the cheapest drift to write is a suffix
 * test (`str_ends_with($host, 'ospos-saas.micronuba.net')`), which answers TRUE for every single
 * business subdomain on the platform. That one line would move Casaletto's session to the
 * console's table and hand Casaletto's settings cache to the console. So the comparison lives
 * here, exactly once, and it is an exact match against a configured list.
 *
 * WHY EXACT AND NOT A SUFFIX
 *
 * The console is one host: ospos-saas.micronuba.net (staging.ospos-saas.micronuba.net in
 * staging). Every other host under that domain is a paying business. There is no shape of suffix
 * rule that separates those two -- only a list does.
 *
 * ON CASE
 *
 * Host headers are case-insensitive (RFC 7230) and Traefik's Host() matcher treats them so: a
 * request for `OSPOS-SaaS.micronuba.net` reaches this container. Config\App::getValidHost() has to
 * accept it too, or baseURL falls back to another host entirely and the console's login form would
 * post the superadministrator's password somewhere else. Both sides therefore compare lowercased,
 * and getValidHost() returns the configured spelling rather than whatever arrived.
 *
 * ON THE PORT
 *
 * The comparison is against the raw Host header, which carries the port on any non-default port
 * -- the same verbatim comparison Config\App::$allowedHostnames already uses. Local setups behind
 * Traefik's :8090 entrypoint must therefore configure PLATFORM_HOSTNAMES with the port included,
 * exactly as docker-compose.local.yml already does for ALLOWED_HOSTNAMES.
 */
final class PlatformContext
{
    /**
     * The one locale the console speaks.
     *
     * Not negotiated, and not read from any business's app_config -- the console belongs to no
     * business and has no configuration of its own to read. Fixed to es-MX because that is the
     * single Spanish variant this project uses: a string written in es-ES is invisible to an es-MX
     * request and the screen comes out in English without a single error anywhere.
     *
     * Two places need it and neither can be reached from the other: App\Events\Load_config, which
     * runs on post_controller_constructor, and App\Controllers\Platform_Controller, whose
     * constructor runs BEFORE that event and can already redirect with a translated message.
     */
    public const LOCALE = 'es-MX';

    /**
     * Set by TenantResolver once it has taken the platform branch and repointed the active
     * connection group at the control schema. Distinct from isPlatform(): this one records that
     * something was DONE, not merely that the address says so.
     */
    private static bool $resolved = false;

    /**
     * The single definition of "this host is the platform console".
     */
    public static function matches(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        $host = strtolower($host);

        foreach (config(App::class)->platformHostnames as $platformHostname) {
            if (strtolower($platformHostname) === $host) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is the request currently being served addressed to the console?
     *
     * Derived from the request Host rather than from the mark below, because the callers that need
     * it most -- Config\Session's and Config\OSPOS's constructors -- can be reached before
     * TenantResolver has run (and under `php spark`, where no filter runs at all). Deriving it
     * means they can never disagree with the router about which application this is.
     *
     * Reads $_SERVER directly, matching Config\App::getValidHost(). CodeIgniter 4.7's
     * `superglobals` service writes through to $_SERVER on setServer(), so tests that set the Host
     * that way are seen here too.
     */
    public static function isPlatform(): bool
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';

        return is_string($host) && self::matches($host);
    }

    public static function markResolved(): void
    {
        self::$resolved = true;
    }

    public static function isResolved(): bool
    {
        return self::$resolved;
    }

    /**
     * Test-only: clears state between PHPUnit cases, since a static property would otherwise leak
     * across tests in the same process.
     */
    public static function reset(): void
    {
        self::$resolved = false;
    }
}
