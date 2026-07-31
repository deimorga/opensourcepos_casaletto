<?php

namespace App\Libraries;

use Config\Database;
use RuntimeException;
use Throwable;

/**
 * Shared tenant-provisioning logic used by both `php spark tenant:create`
 * (app/Commands/TenantCreate.php) and the web-based business-management
 * platform (app/Controllers/PlatformAdmin.php, Fase 8) -- extracted so
 * the schema/user/migration/admin-reset sequence exists in exactly one
 * place instead of being duplicated between the CLI and the HTTP path.
 *
 * Uses a dedicated, narrowly-scoped `platform_provisioner` MySQL user
 * (env vars PLATFORM_PROVISION_USERNAME/PLATFORM_PROVISION_PASSWORD)
 * instead of root. This is a standing container credential (unlike the
 * original Fase 7 CLI-only design, which required MYSQL_ROOT_PASSWORD
 * for a single invocation and never as a container env var) -- the web
 * panel needs to provision synchronously from an authenticated HTTP
 * request, so *some* privileged credential has to live in the running
 * container. The privilege is scoped down accordingly: this user can
 * only CREATE/DROP databases and users matching `tenant_%` (see the
 * runbook in docs/Tecnico/multi-tenant-arquitectura.md section 11),
 * never full root -- same least-privilege principle already used for
 * each tenant's own dedicated GRANT.
 */
class TenantProvisioner
{
    /**
     * Never assignable to a real tenant -- kept in sync with the
     * reserved list documented in docs/Tecnico/multi-tenant-arquitectura.md
     * (Fase 6/7): these are infrastructure subdomains of ospos-saas.micronuba.net,
     * not business slugs.
     */
    private const RESERVED_SLUGS = ['staging', 'www', 'admin', 'platform', 'login', 'api', 'app'];

    /**
     * @return string|null Error message, or null if the slug is valid and free.
     */
    public function validateSlug(?string $slug): ?string
    {
        if ($slug === null || $slug === '') {
            return 'A slug is required.';
        }

        if (!preg_match('/^[a-z0-9-]{1,20}$/', $slug)) {
            return "Invalid slug '$slug' -- must be 1-20 lowercase letters, digits, or hyphens.";
        }

        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            return "Slug '$slug' is reserved and cannot be used for a tenant.";
        }

        if (db_connect('platform')->table('tenants')->where('slug', $slug)->countAllResults() > 0) {
            return "Tenant slug '$slug' already exists.";
        }

        return null;
    }

    /**
     * Provisions a new tenant end to end: schema, dedicated MySQL user
     * with GRANT limited to that one schema, migrations (App namespace,
     * reusing tenant:migrate-one in a fresh child process), reset of
     * the default admin account initial_schema.sql seeds, and the row
     * in platform.tenants.
     *
     * @return array{slug: string, db_name: string, admin_password: string}
     * @throws RuntimeException on any provisioning failure.
     */
    public function create(string $slug, ?string $companyName = null): array
    {
        $error = $this->validateSlug($slug);
        if ($error !== null) {
            throw new RuntimeException($error);
        }

        $companyName = $companyName !== null && $companyName !== '' ? $companyName : $slug;

        $provisionUser     = getenv('PLATFORM_PROVISION_USERNAME');
        $provisionPassword = getenv('PLATFORM_PROVISION_PASSWORD');

        if (!$provisionUser || !$provisionPassword) {
            throw new RuntimeException('PLATFORM_PROVISION_USERNAME/PLATFORM_PROVISION_PASSWORD env vars are required (see docs/Tecnico/multi-tenant-arquitectura.md section 11 for the one-time DBA runbook that creates this user).');
        }

        $dbIdentifier = 'tenant_' . str_replace('-', '_', $slug);
        $dbPassword   = bin2hex(random_bytes(16));
        $hostConfig   = config(Database::class)->default;

        try {
            $provisioner = Database::connect([
                'hostname' => $hostConfig['hostname'],
                'username' => $provisionUser,
                'password' => $provisionPassword,
                'DBDriver' => $hostConfig['DBDriver'],
                'database' => null,
                'charset'  => $hostConfig['charset'] ?? 'utf8mb4',
                'DBCollat' => $hostConfig['DBCollat'] ?? 'utf8mb4_general_ci',
            ], false);

            $provisioner->query("CREATE DATABASE `$dbIdentifier`");
            $provisioner->query("CREATE USER '$dbIdentifier'@'%' IDENTIFIED BY '$dbPassword'");
            $provisioner->query("GRANT ALL PRIVILEGES ON `$dbIdentifier`.* TO '$dbIdentifier'@'%'");
            // No FLUSH PRIVILEGES: GRANT/CREATE USER/DROP USER take effect
            // immediately, and FLUSH PRIVILEGES needs the RELOAD privilege,
            // which the scoped platform_provisioner user deliberately does
            // not have (see docs/Tecnico/multi-tenant-arquitectura.md
            // section 11 -- confirmed empirically, not assumed).
        } catch (Throwable $e) {
            throw new RuntimeException('Schema/user provisioning failed: ' . $e->getMessage(), 0, $e);
        }

        // Fresh child process, not an in-process config mutation -- see
        // the long comment in TenantMigrateOne.php for why the latter
        // isn't reliable inside a spark command. Absolute path to spark:
        // by the time a command's run() executes, spark's own bootstrap
        // has already chdir()'d into public/, so a relative "php spark
        // ..." can't find it. Same reasoning applies here even though
        // this code path can also be invoked from an HTTP controller.
        $sparkPath = ROOTPATH . 'spark';
        exec('MYSQL_DB_NAME=' . escapeshellarg($dbIdentifier) . ' php ' . escapeshellarg($sparkPath) . ' tenant:migrate-one 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                "Migration failed for $dbIdentifier -- NOT registering in platform.tenants. Schema/user already exist; fix and re-run tenant:migrate-one manually, or drop them and retry.\n"
                . implode("\n", $output)
            );
        }

        // initial_schema.sql seeds every fresh schema with Casaletto's
        // OWN admin account (username admin_casaletto and its real
        // bcrypt hash). Left as-is, every new tenant's default login
        // would BE Casaletto's real admin password. Connecting as the
        // tenant's own new user (not the provisioner) to do this
        // doubles as proof the GRANT actually restricts it to this one
        // schema, not just that it exists.
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
            throw new RuntimeException('Post-migration default-admin reset failed: ' . $e->getMessage(), 0, $e);
        }

        // service('encrypter')->encrypt() with the configured rawData=false
        // already returns a printable, storable string (hex HMAC + base64
        // ciphertext) -- an extra base64_encode() here just about doubles
        // the length for no benefit, and was overflowing the db_password
        // VARCHAR(255) column, silently truncating it and breaking
        // decryption later (confirmed empirically while testing Fase 8's
        // login flow: TenantResolver's decrypt() failed with "authentication
        // failed" because the stored ciphertext had been cut off).
        $encryptedDbPassword = service('encrypter')->encrypt($dbPassword);

        db_connect('platform')->table('tenants')->insert([
            'slug'        => $slug,
            'db_name'     => $dbIdentifier,
            'db_user'     => $dbIdentifier,
            'db_password' => $encryptedDbPassword,
            'status'      => 'active',
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        return [
            'slug'           => $slug,
            'db_name'        => $dbIdentifier,
            'admin_password' => $adminPassword,
        ];
    }

    /**
     * Registers an EXISTING, already-populated schema (ej. Casaletto's
     * own `ospos`) as a tenant, WITHOUT creating, migrating, or
     * resetting anything in it -- the opposite of create(), which
     * always provisions a brand-new empty schema. This is Fase 10's
     * onboarding path for Casaletto itself: since Casaletto never
     * migrates data anywhere (schema-per-tenant means it just keeps
     * living where it already is), "adopting" it is nothing more than
     * telling TenantResolver which slug maps to that existing schema.
     *
     * Runs three read-only safety checks first and refuses to proceed
     * if any fails, rather than silently working around them:
     *  1. The schema looks like a real OSPOS install (has the
     *     employees/app_config tables under the configured prefix).
     *  2. Its App-namespace migration history is fully current --
     *     adoption never runs migrations itself; an out-of-date schema
     *     must be migrated first (ej. via tenant:migrate-one) as its
     *     own deliberate, reviewable step.
     *  3. Its default admin account is not still the public upstream
     *     default (username `admin` / password `pointofsale`, hash_version
     *     1/MD5) -- adopting a business that never rotated this would
     *     silently expose it once reachable under the SaaS wildcard.
     *
     * Deliberately does NOT create a dedicated MySQL user/GRANT for the
     * adopted schema (unlike create()): platform_provisioner's wildcard
     * grant only covers `tenant_%` schemas, so granting on an existing,
     * differently-named schema needs a one-time manual DBA GRANT first
     * -- out of scope for this automated path. The tenant row is
     * inserted with db_user/db_password left null, so TenantResolver
     * falls back to the shared credentials the schema already uses
     * today (its own pre-existing behavior, unchanged). Upgrading it to
     * a dedicated user later is a separate, optional hardening step.
     *
     * @return array{slug: string, db_name: string}
     * @throws RuntimeException on any precondition failure.
     */
    public function adopt(string $slug, string $existingDbName): array
    {
        $error = $this->validateSlug($slug);
        if ($error !== null) {
            throw new RuntimeException($error);
        }

        if ($existingDbName === '') {
            throw new RuntimeException('An existing database name is required.');
        }

        if (db_connect('platform')->table('tenants')->where('db_name', $existingDbName)->countAllResults() > 0) {
            throw new RuntimeException("Database '$existingDbName' is already registered to a tenant.");
        }

        $hostConfig = config(Database::class)->default;
        $prefix     = $hostConfig['DBPrefix'] ?? 'ospos_';

        try {
            // Shared credentials, not platform_provisioner: the latter
            // only has DDL-level CREATE/DROP privileges, never data
            // access to a pre-existing schema it didn't create.
            $existingDb = Database::connect([
                'hostname' => $hostConfig['hostname'],
                'username' => $hostConfig['username'],
                'password' => $hostConfig['password'],
                'DBDriver' => $hostConfig['DBDriver'],
                'database' => $existingDbName,
                'DBPrefix' => $prefix,
                'charset'  => $hostConfig['charset'] ?? 'utf8mb4',
                'DBCollat' => $hostConfig['DBCollat'] ?? 'utf8mb4_general_ci',
            ], false);

            $hasTables = $existingDb->tableExists('employees') && $existingDb->tableExists('app_config');
        } catch (Throwable $e) {
            // Deliberately not just `catch (RuntimeException $e)` further
            // down to re-throw as-is: since PHP 8.1, mysqli's own
            // exception (mysqli_sql_exception) extends RuntimeException,
            // so a connection failure here would otherwise slip past our
            // own checks below unwrapped, surfacing a raw driver error
            // instead of a message that names which database failed.
            throw new RuntimeException("Could not connect to '$existingDbName': " . $e->getMessage(), 0, $e);
        }

        if (!$hasTables) {
            throw new RuntimeException("'$existingDbName' does not look like an OSPOS schema (missing employees/app_config tables).");
        }

        $latestAvailable = (new MY_Migration(config('Migrations')))->setNamespace('App')->get_latest_migration();

        $currentRow = $existingDb->table('migrations')
            ->select('version')
            ->where('namespace', 'App')
            ->orderBy('version', 'DESC')
            ->limit(1)
            ->get()
            ->getRow();
        $currentVersion = $currentRow ? (int) $currentRow->version : 0;

        if ($currentVersion !== $latestAvailable) {
            throw new RuntimeException(
                "'$existingDbName' is not on the latest App migration (has $currentVersion, latest is $latestAvailable). "
                . 'Migrate it first (ej. MYSQL_DB_NAME=' . $existingDbName . ' php spark tenant:migrate-one) and retry adoption.'
            );
        }

        $defaultAdmin = $existingDb->table('employees')
            ->where('username', 'admin')
            ->where('password', md5('pointofsale'))
            ->where('hash_version', '1')
            ->get()
            ->getRow();

        if ($defaultAdmin !== null) {
            throw new RuntimeException(
                "'$existingDbName' still has the public upstream default admin credential (admin/pointofsale). "
                . 'Change it before adopting this business as a tenant.'
            );
        }

        db_connect('platform')->table('tenants')->insert([
            'slug'       => $slug,
            'db_name'    => $existingDbName,
            'status'     => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return ['slug' => $slug, 'db_name' => $existingDbName];
    }

    /**
     * Toggles a tenant between 'active' and 'suspended'. TenantResolver
     * (Fase 4) already refuses to resolve a non-active tenant, so this
     * is enough to cut off access without touching its schema or data.
     */
    public function setStatus(string $slug, string $status): bool
    {
        if (!in_array($status, ['active', 'suspended'], true)) {
            throw new RuntimeException("Invalid status '$status'.");
        }

        return db_connect('platform')->table('tenants')
            ->where('slug', $slug)
            ->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Removes a tenant's platform.tenants row and revokes its dedicated
     * MySQL user, so it can no longer be resolved or connected to.
     *
     * Deliberately does NOT drop the tenant's schema by default -- the
     * same "never delete the backup immediately" caution this project
     * has used for every other destructive step (MariaDB upgrade
     * volumes, etc.). Pass $dropSchema=true only when the operator has
     * explicitly confirmed the client's data should be destroyed.
     */
    public function delete(string $slug, bool $dropSchema = false): bool
    {
        $platformDb = db_connect('platform');
        $tenant = $platformDb->table('tenants')->where('slug', $slug)->get()->getRow();

        if ($tenant === null) {
            throw new RuntimeException("Tenant slug '$slug' not found.");
        }

        $provisionUser     = getenv('PLATFORM_PROVISION_USERNAME');
        $provisionPassword = getenv('PLATFORM_PROVISION_PASSWORD');

        if ($provisionUser && $provisionPassword) {
            $hostConfig = config(Database::class)->default;

            try {
                $provisioner = Database::connect([
                    'hostname' => $hostConfig['hostname'],
                    'username' => $provisionUser,
                    'password' => $provisionPassword,
                    'DBDriver' => $hostConfig['DBDriver'],
                    'database' => null,
                    'charset'  => $hostConfig['charset'] ?? 'utf8mb4',
                    'DBCollat' => $hostConfig['DBCollat'] ?? 'utf8mb4_general_ci',
                ], false);

                $provisioner->query("DROP USER IF EXISTS `{$tenant->db_user}`@'%'");

                if ($dropSchema) {
                    $provisioner->query("DROP DATABASE IF EXISTS `{$tenant->db_name}`");
                }
            } catch (Throwable $e) {
                throw new RuntimeException('Tenant teardown failed: ' . $e->getMessage(), 0, $e);
            }
        }

        return $platformDb->table('tenants')->where('slug', $slug)->delete();
    }
}
