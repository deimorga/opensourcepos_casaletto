<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Provisions a new tenant end to end: schema, a dedicated MySQL user
 * with GRANT limited to that one schema (the defense-in-depth layer
 * docs/Tecnico/multi-tenant-arquitectura.md describes since the
 * architecture was first written), migrations (App namespace, reusing
 * Fase 5's tenant:migrate-one), a reset of the default admin account
 * initial_schema.sql seeds (never Casaletto's own credentials -- see
 * the long comment below), and the row in platform.tenants that
 * TenantResolver (Fase 4) reads from.
 *
 * Requires MYSQL_ROOT_PASSWORD to be set for THIS invocation only
 * (e.g. `MYSQL_ROOT_PASSWORD=... php spark tenant:create demo1`) --
 * deliberately not a standing env var on the app container, since
 * normal request traffic never needs root DB access, only this rare,
 * operator-run provisioning action does.
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

    /**
     * Never assignable to a real tenant -- staging in particular
     * because docker-compose.prod.yml's tenant wildcard technically
     * matches the literal string "staging.pos-casaletto.micronuba.net"
     * (see Fase 6). Traefik router priority already protects against
     * that collision; this is the second, independent layer.
     */
    private const RESERVED_SLUGS = ['staging', 'www', 'admin', 'platform', 'login', 'api', 'app'];

    public function run(array $params)
    {
        $slug        = $params[0] ?? null;
        $companyName = $params[1] ?? $slug;

        if (!$this->validSlug($slug)) {
            return 1;
        }

        $rootPassword = getenv('MYSQL_ROOT_PASSWORD');

        if (!$rootPassword) {
            CLI::error('MYSQL_ROOT_PASSWORD env var is required for this one invocation (not a standing container env var).');

            return 1;
        }

        $platformDb = db_connect('platform');

        if ($platformDb->table('tenants')->where('slug', $slug)->countAllResults() > 0) {
            CLI::error("Tenant slug '$slug' already exists.");

            return 1;
        }

        $dbIdentifier = 'tenant_' . str_replace('-', '_', $slug);
        $dbPassword   = bin2hex(random_bytes(16));
        $hostConfig   = config(Database::class)->default;

        try {
            $root = Database::connect([
                'hostname' => $hostConfig['hostname'],
                'username' => 'root',
                'password' => $rootPassword,
                'DBDriver' => $hostConfig['DBDriver'],
                'database' => null,
                'charset'  => $hostConfig['charset'] ?? 'utf8mb4',
                'DBCollat' => $hostConfig['DBCollat'] ?? 'utf8mb4_general_ci',
            ], false);

            $root->query("CREATE DATABASE `$dbIdentifier`");
            $root->query("CREATE USER '$dbIdentifier'@'%' IDENTIFIED BY '$dbPassword'");
            $root->query("GRANT ALL PRIVILEGES ON `$dbIdentifier`.* TO '$dbIdentifier'@'%'");
            $root->query('FLUSH PRIVILEGES');
        } catch (Throwable $e) {
            CLI::error('Schema/user provisioning failed: ' . $e->getMessage());

            return 1;
        }

        CLI::write("Schema and dedicated user created: $dbIdentifier", 'green');

        // Fresh child process, not an in-process config mutation -- see
        // the long comment in TenantMigrateOne.php for why the latter
        // isn't reliable inside a spark command. Absolute path to spark:
        // by the time this command's run() executes, spark's own
        // bootstrap has already chdir()'d into public/, so a relative
        // "php spark ..." can't find it.
        $sparkPath = ROOTPATH . 'spark';
        exec('MYSQL_DB_NAME=' . escapeshellarg($dbIdentifier) . ' php ' . escapeshellarg($sparkPath) . ' tenant:migrate-one 2>&1', $output, $exitCode);

        foreach ($output as $line) {
            CLI::write($line);
        }

        if ($exitCode !== 0) {
            CLI::error("Migration failed for $dbIdentifier -- NOT registering in platform.tenants. Schema/user already exist; fix and re-run tenant:migrate-one manually, or drop them and retry tenant:create.");

            return 1;
        }

        // initial_schema.sql seeds every fresh schema with Casaletto's
        // OWN admin account (username admin_casaletto and its real
        // bcrypt hash, baked in when that credential was rotated -- see
        // the branching-deploy-policy memory). Left as-is, every new
        // tenant's default login would BE Casaletto's real admin
        // password. Connecting as the tenant's own new user (not root)
        // to do this doubles as proof the GRANT actually restricts it
        // to this one schema, not just that it exists.
        $adminPassword = bin2hex(random_bytes(8));

        try {
            $tenantDb = Database::connect([
                'hostname' => $hostConfig['hostname'],
                'username' => $dbIdentifier,
                'password' => $dbPassword,
                'DBDriver' => $hostConfig['DBDriver'],
                'database' => $dbIdentifier,
                'DBPrefix' => 'ospos_',
                'charset'  => $hostConfig['charset'] ?? 'utf8mb4',
                'DBCollat' => $hostConfig['DBCollat'] ?? 'utf8mb4_general_ci',
            ], false);

            $tenantDb->table('employees')->where('person_id', 1)->update([
                'username'     => 'admin',
                'password'     => password_hash($adminPassword, PASSWORD_DEFAULT),
                'hash_version' => 2,
            ]);

            $tenantDb->table('app_config')->where('key', 'company')->update(['value' => $companyName]);
        } catch (Throwable $e) {
            CLI::error('Post-migration default-admin reset failed: ' . $e->getMessage());

            return 1;
        }

        $encryptedDbPassword = base64_encode(service('encrypter')->encrypt($dbPassword));

        $platformDb->table('tenants')->insert([
            'slug'        => $slug,
            'db_name'     => $dbIdentifier,
            'db_user'     => $dbIdentifier,
            'db_password' => $encryptedDbPassword,
            'status'      => 'active',
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        CLI::write('');
        CLI::write("Tenant '$slug' provisioned.", 'green');
        CLI::write("  Default admin login -- username: admin  password: $adminPassword");
        CLI::write('  Relay this password securely and have the client change it on first login.');

        return 0;
    }

    private function validSlug(?string $slug): bool
    {
        if ($slug === null || $slug === '') {
            CLI::error('Usage: php spark tenant:create <slug> [companyName]');

            return false;
        }

        if (!preg_match('/^[a-z0-9-]{1,20}$/', $slug)) {
            CLI::error("Invalid slug '$slug' -- must be 1-20 lowercase letters, digits, or hyphens.");

            return false;
        }

        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            CLI::error("Slug '$slug' is reserved and cannot be used for a tenant.");

            return false;
        }

        return true;
    }
}
