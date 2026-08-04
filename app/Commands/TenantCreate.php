<?php

namespace App\Commands;

use App\Libraries\TenantProvisioner;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use RuntimeException;

/**
 * Provisions a new tenant end to end -- thin CLI wrapper around
 * App\Libraries\TenantProvisioner, which holds the actual schema/user/
 * migration/admin-reset sequence shared with the web-based business-
 * management platform (app/Controllers/PlatformAdmin.php, Fase 8).
 *
 * Requires PLATFORM_PROVISION_USERNAME/PLATFORM_PROVISION_PASSWORD to
 * be set as standing container env vars -- a dedicated MySQL user
 * scoped to CREATE/DROP on `tenant_%` schemas/users only, never root.
 * See docs/Tecnico/multi-tenant-arquitectura.md section 11 for the
 * one-time DBA runbook that creates this user.
 */
class TenantCreate extends BaseCommand
{
    protected $group       = 'Platform';
    protected $name        = 'tenant:create';
    protected $description = 'Provision a new tenant (schema, dedicated MySQL user, migrations, default admin reset, platform.tenants row).';
    protected $arguments    = [
        'slug'        => 'Tenant subdomain slug: 1-20 lowercase letters/digits/hyphens.',
        'companyName' => 'Optional display name for app_config.company (defaults to the slug).',
    ];

    public function run(array $params)
    {
        $slug        = $params[0] ?? null;
        $companyName = $params[1] ?? null;

        try {
            $result = (new TenantProvisioner())->create((string) $slug, $companyName);
        } catch (RuntimeException $e) {
            CLI::error($e->getMessage());

            return 1;
        }

        CLI::write("Schema and dedicated user created: {$result['db_name']}", 'green');
        CLI::write('');
        CLI::write("Tenant '{$result['slug']}' provisioned.", 'green');
        CLI::write("  Default admin login -- username: admin  password: {$result['admin_password']}");
        CLI::write('  Relay this password securely and have the client change it on first login.');

        return 0;
    }
}
