<?php

namespace App\Filters;

use App\Libraries\PlatformContext;
use App\Libraries\TenantContext;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;
use Config\Database;

/**
 * Resolves which tenant a request belongs to from the Host header and,
 * if found, swaps the `default` connection's schema (and, since Fase 7
 * provisioning gives each tenant its own MySQL user, credentials too)
 * before anything else touches the database -- Config\Session's
 * constructor connects as soon as session() is first called (typically
 * inside Secure_Controller), so this must run before that, which is
 * why it's the first entry in required.before rather than a global
 * filter.
 *
 * A tenant row without db_user/db_password (shouldn't happen for
 * anything provisioned via tenant:create, but tolerated rather than
 * fatal) falls back to whatever MYSQL_* env vars already configure for
 * host/user/password, only swapping `database` -- the pre-Fase-7
 * behavior.
 *
 * THREE DIFFERENT "NOT A TENANT", AND THEY MUST NOT BEHAVE THE SAME
 *
 * (There were two until 2026-09-01, when the platform console got an
 * address of its own. Anyone adding a fourth: the shape of the mistake
 * is always the same -- a new kind of host quietly inherits "fall
 * through to the default connection", which means Casaletto's database.)
 *
 * A Host that IS the platform console -- ospos-saas.micronuba.net, the
 * apex of the same domain whose subdomains are the businesses -- has to
 * be repointed at the control schema. It matches no wildcard (the apex
 * does not end in ".ospos-saas.micronuba.net"), so before this branch
 * existed it took the legacy path below, and the console that
 * administers every business would have been running on the database of
 * the business that is currently trading. This branch comes FIRST and
 * touches no registry: the console must not be takeable offline by a row
 * in a table it administers.
 *
 * A Host that matches NO configured wildcard -- Casaletto's own
 * pos-casaletto.micronuba.net, staging, localhost, anything in dev --
 * is not a tenant request at all. It falls through untouched and the
 * `default` connection keeps using whatever MYSQL_* env vars already
 * configure. That is the legacy single-tenant path and it stays.
 *
 * A Host that DOES match a wildcard but resolves to no active tenant is
 * refused, and that is a change made on 2026-08-30. It used to fall
 * through as well, on the reasoning -- correct at the time -- that
 * Casaletto was the only business and its subdomain was not registered,
 * so falling back to today's behavior was exactly right.
 *
 * That premise expired on 2026-08-03, when Casaletto was registered as a
 * tenant. What was left was this: ANY unregistered subdomain, and every
 * SUSPENDED one, silently served Casaletto's schema. Suspending a
 * business did not lock it out -- it handed it another business's till.
 * Authentication still stood in the way, so it was not a data leak; it
 * was the wrong failure mode, and the wrong one to keep once a second
 * paying tenant exists.
 *
 * THE REFUSAL RENDERS NO VIEW, ON PURPOSE
 *
 * Rendering an OSPOS view would read app_config -- theme, company name,
 * language -- over the `default` connection, which is precisely the
 * database this request must not touch. The response is therefore
 * self-contained HTML built here, with no database access and no view
 * layer. A refusal that queries the wrong schema to say "wrong schema"
 * would be worse than the bug it replaces.
 */
class TenantResolver implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $host = $request->getServer('HTTP_HOST') ?? '';

        if (PlatformContext::matches($host)) {
            $this->pointAtControlSchema();
            PlatformContext::markResolved();

            return;
        }

        $slug = $this->extractSlug($host);

        if ($slug === null) {
            return;
        }

        // Fetched WITHOUT the status filter so the two failures can be told
        // apart. A suspended business being told it is suspended is the
        // difference between one support call and an afternoon of them; the
        // information that disclosed -- that a slug exists on the platform --
        // is a business name on a subdomain, not a secret.
        $tenant = db_connect('platform')
            ->table('tenants')
            ->where('slug', $slug)
            ->get()
            ->getRow();

        if ($tenant === null) {
            return $this->refuse(
                404,
                'Este negocio no existe.',
                'La dirección no corresponde a ningún negocio de la plataforma. Revise que esté bien escrita.',
            );
        }

        if ($tenant->status !== 'active') {
            return $this->refuse(
                503,
                'Este negocio está suspendido.',
                'El acceso está temporalmente deshabilitado. Comuníquese con su proveedor del servicio.',
            );
        }

        // Mutate whichever group is actually active (Config\Database's
        // constructor points $defaultGroup at 'development'/'tests' outside
        // production), not a hardcoded 'default' -- otherwise this swap is
        // silently a no-op under CI_ENVIRONMENT=development (used by
        // docker-compose.dev.yml), where every Database::connect() call
        // resolves through the 'development' array instead. Confirmed
        // empirically while testing Fase 8's tenant login locally: the
        // config array showed the right tenant schema, but the actual
        // connection stayed on the untouched 'development' array. Both
        // docker-compose.staging.yml and docker-compose.prod.yml set
        // CI_ENVIRONMENT=production, where defaultGroup is 'default', so
        // this was never reachable there -- but local reproduction of any
        // tenant-specific issue needs this to work too.
        $dbConfig                 = config(Database::class);
        $activeGroup              = $dbConfig->defaultGroup;
        $defaultGroup             = &$dbConfig->{$activeGroup};
        $defaultGroup['database'] = $tenant->db_name;

        if (! empty($tenant->db_user) && ! empty($tenant->db_password)) {
            $defaultGroup['username'] = $tenant->db_user;
            $defaultGroup['password'] = service('encrypter')->decrypt($tenant->db_password);
        }

        TenantContext::set((int) $tenant->id, $tenant->slug, $tenant->db_name);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }

    /**
     * Repoints the active connection group at the platform control schema for a console request.
     *
     * ALL FOUR KEYS, not just `database`. The tenant swap below only replaces the credentials when
     * the tenant carries its own, so on a warm request the array can still hold some client's user
     * and password; copying the schema name alone would then try to open platform_control as a
     * database user granted nothing there. That fails as an unexplained outage of the console
     * rather than as a visible configuration mistake.
     *
     * The DBPrefix is deliberately NOT copied. The control schema uses an empty prefix while the
     * tenant groups use `ospos_`, so anything that reaches for the default connection here looks
     * for `platform_control.ospos_*` -- tables that do not exist. That is the failure mode we want:
     * an error, rather than a silent read of somebody's data. Everything the console genuinely
     * needs goes through db_connect('platform') explicitly.
     */
    private function pointAtControlSchema(): void
    {
        // Same reason as the tenant swap below for mutating whichever group is active rather than
        // a hardcoded 'default': outside production, defaultGroup is 'development' or 'tests'.
        $dbConfig     = config(Database::class);
        $activeGroup  = $dbConfig->defaultGroup;
        $defaultGroup = &$dbConfig->{$activeGroup};

        foreach (['hostname', 'username', 'password', 'database'] as $key) {
            $defaultGroup[$key] = $dbConfig->platform[$key];
        }
    }

    /**
     * The refusal page: self-contained, no view, no database.
     *
     * Returning a ResponseInterface from before() short-circuits the request,
     * so nothing downstream ever opens the `default` connection -- which is
     * the whole point. See the class comment.
     */
    private function refuse(int $status, string $title, string $detail): ResponseInterface
    {
        $body = '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . esc($title) . '</title>'
            . '<style>body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f7f7f8;'
            . 'color:#24292f;display:flex;min-height:100vh;align-items:center;justify-content:center;'
            . 'margin:0;padding:1.5rem}main{max-width:32rem;text-align:center}'
            . 'h1{font-size:1.35rem;margin:0 0 .6rem}p{margin:0;line-height:1.55;color:#57606a}</style>'
            . '</head><body><main><h1>' . esc($title) . '</h1><p>' . esc($detail) . '</p></main></body></html>';

        return service('response')
            ->setStatusCode($status)
            ->setContentType('text/html')
            ->setBody($body);
    }

    /**
     * Strips a configured wildcard suffix (App::$allowedHostnameWildcards,
     * e.g. ".midominio.com") off the request Host to get the tenant slug.
     * Returns null when the Host doesn't match any configured wildcard --
     * e.g. Casaletto's own exact-match hostname, or local/dev hosts.
     */
    private function extractSlug(string $host): ?string
    {
        $appConfig = config(App::class);

        foreach ($appConfig->allowedHostnameWildcards as $suffix) {
            if ($suffix !== '' && str_ends_with($host, $suffix)) {
                $slug = substr($host, 0, -strlen($suffix));

                return $slug !== '' ? $slug : null;
            }
        }

        return null;
    }
}
