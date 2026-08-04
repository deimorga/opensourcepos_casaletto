<?php

namespace App\Commands;

use App\Libraries\TenantProvisioner;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use RuntimeException;

/**
 * Registers an EXISTING, already-populated schema as a tenant --
 * Fase 10's onboarding path for Casaletto itself, which never migrates
 * data anywhere (schema-per-tenant means it keeps living where it
 * already is). The opposite of tenant:create, which always provisions
 * a brand-new empty schema.
 *
 * See App\Libraries\TenantProvisioner::adopt() for the safety checks
 * this refuses to skip (schema looks like real OSPOS, migrations
 * fully current, admin credential not the public upstream default).
 */
class TenantAdopt extends BaseCommand
{
    protected $group       = 'Platform';
    protected $name        = 'tenant:adopt';
    protected $description = 'Register an existing, already-populated schema as a tenant (no schema creation, no migration, no admin reset).';
    protected $arguments    = [
        'slug'           => 'Tenant subdomain slug: 1-20 lowercase letters/digits/hyphens.',
        'existingDbName' => 'Name of the existing database to adopt (ej. ospos).',
    ];

    public function run(array $params)
    {
        $slug           = $params[0] ?? null;
        $existingDbName = $params[1] ?? null;

        if (!$slug || !$existingDbName) {
            CLI::error('Usage: php spark tenant:adopt <slug> <existingDbName>');

            return 1;
        }

        try {
            $result = (new TenantProvisioner())->adopt($slug, $existingDbName);
        } catch (RuntimeException $e) {
            CLI::error($e->getMessage());

            return 1;
        }

        CLI::write('');
        CLI::write("Tenant '{$result['slug']}' adopted from existing database '{$result['db_name']}'.", 'green');
        CLI::write('No data was created, migrated, or modified -- only the platform.tenants registry row.');

        return 0;
    }
}
