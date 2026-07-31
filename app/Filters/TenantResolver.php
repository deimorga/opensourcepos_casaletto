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

        $defaultGroup = &config(Database::class)->default;
        $defaultGroup['database'] = $tenant->db_name;

        if (!empty($tenant->db_user) && !empty($tenant->db_password)) {
            $defaultGroup['username'] = $tenant->db_user;
            $defaultGroup['password'] = service('encrypter')->decrypt(base64_decode($tenant->db_password));
        }

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
