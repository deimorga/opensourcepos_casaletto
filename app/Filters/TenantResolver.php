<?php

namespace App\Filters;

use App\Libraries\TenantContext;
use Config\App;
use Config\Database;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Resolves which tenant a request belongs to from the Host header and,
 * if found, swaps the `default` connection's schema before anything
 * else touches the database -- Config\Session's constructor connects
 * as soon as session() is first called (typically inside
 * Secure_Controller), so this must run before that, which is why it's
 * the first entry in required.before rather than a global filter.
 *
 * Only `database` is swapped, not host/user/password: every tenant
 * shares the same DB server and credentials until Fase 7 (provisioning)
 * gives each one its own MySQL user with a GRANT limited to its own
 * schema.
 *
 * A Host that doesn't match any active tenant (including Casaletto's
 * own current subdomain, which isn't registered as a tenant yet) is
 * not an error -- it falls through and the `default` connection keeps
 * using whatever MYSQL_* env vars already configure, i.e. today's
 * single-tenant behavior, unchanged.
 */
class TenantResolver implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $host = $request->getServer('HTTP_HOST') ?? '';
        $slug = $this->extractSlug($host);

        if ($slug === null) {
            return;
        }

        $tenant = db_connect('platform')
            ->table('tenants')
            ->where('slug', $slug)
            ->where('status', 'active')
            ->get()
            ->getRow();

        if ($tenant === null) {
            return;
        }

        config(Database::class)->default['database'] = $tenant->db_name;

        TenantContext::set((int) $tenant->id, $tenant->slug, $tenant->db_name);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
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
