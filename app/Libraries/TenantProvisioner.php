<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
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
 *
 * ENTREGA 3: APROVISIONAR DEJA DE SER «CREAR EL ESQUEMA»
 *
 * Hasta hoy create() dejaba un esquema migrado y poco más: escribía una sola clave de configuración
 * -- el nombre de la empresa -- y el resto se quedaba con la semilla estadounidense de
 * initial_schema.sql. Un negocio nacía sin poder vender al peso, con el código de barras apuntando
 * al identificador interno, en inglés, y con su administrador llamándose «John Doe». Ahora aplica el
 * perfil de configuración completo (App\Libraries\TenantConfigProfile, D12), le pone nombre a la
 * persona, y guarda en platform_control el nombre del negocio y una copia cifrada de la contraseña
 * inicial.
 *
 * Y aparecen aquí las dos operaciones que hasta ahora solo existían atadas a crear un esquema desde
 * cero: adminCredential() consulta esa contraseña mientras siga siendo válida, y
 * resetAdminPassword() la rehace sin recrear el negocio (D5).
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
     * El usuario que create() deja como administrador del negocio nuevo. D9: uno solo, con todos
     * los permisos, que después crea a los demás.
     */
    public const DEFAULT_ADMIN_USERNAME = 'admin';

    /**
     * `people.last_name` y `tenants.company_name` son los dos VARCHAR(255); `app_config.value` es
     * VARCHAR(500). Manda el más corto. Un nombre más largo se RECHAZA en vez de recortarse: el
     * truncamiento mudo es exactamente el defecto que ya rompió `db_password` en este módulo.
     */
    private const MAX_COMPANY_NAME = 255;

    // Los tres estados en que puede estar la contraseña del administrador de un negocio (D5). Son
    // excluyentes y la ficha muestra una cosa distinta en cada uno.
    //
    // NONE      -- la plataforma nunca guardó una copia. Es el caso de Casaletto y Paraíso, dados
    //              de alta antes de que esto existiera, y el de cualquier negocio cuya copia se
    //              borró porque el cliente ya cambió la contraseña. Solo queda restablecer.
    // AVAILABLE -- el hash que hay hoy en el negocio sigue siendo el que escribimos: la copia es la
    //              contraseña de verdad y se puede mostrar.
    // CHANGED   -- el hash difiere (o el usuario ya no existe). El cliente la cambió; la copia se
    //              borra en ese mismo momento y este estado solo se ve una vez.
    public const CREDENTIAL_NONE      = 'none';
    public const CREDENTIAL_AVAILABLE = 'available';
    public const CREDENTIAL_CHANGED   = 'changed';

    /**
     * La copia está pero no se puede descifrar: la clave de cifrado cambió bajo los pies. No es un
     * estado del negocio sino una avería de la plataforma, y se distingue de CHANGED a propósito --
     * decirle a alguien «el cliente la cambió» cuando lo que pasó es que perdimos la llave manda a
     * buscar el problema al sitio equivocado. Ver §9.1 del técnico.
     */
    public const CREDENTIAL_UNREADABLE = 'unreadable';

    /**
     * No se pudo llegar al negocio para comprobar nada. Tampoco es un estado de la contraseña, y
     * sobre todo NO se borra la copia: dar por cambiada una contraseña porque la base estaba caída
     * un segundo destruiría la única copia que existe. Se distingue de los demás para que la ficha
     * pueda decir «no lo sé» en vez de afirmar algo que no comprobó.
     */
    public const CREDENTIAL_UNREACHABLE = 'unreachable';

    /**
     * @return string|null Error message, or null if the slug is valid and free.
     */
    public function validateSlug(?string $slug): ?string
    {
        if ($slug === null || $slug === '') {
            return 'A slug is required.';
        }

        if (! preg_match('/^[a-z0-9-]{1,20}$/', $slug)) {
            return "Invalid slug '{$slug}' -- must be 1-20 lowercase letters, digits, or hyphens.";
        }

        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            return "Slug '{$slug}' is reserved and cannot be used for a tenant.";
        }

        if (db_connect('platform')->table('tenants')->where('slug', $slug)->countAllResults() > 0) {
            return "Tenant slug '{$slug}' already exists.";
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
     * @return array{slug: string, db_name: string, admin_username: string, admin_password: string}
     *
     * @throws RuntimeException on any provisioning failure.
     */
    public function create(string $slug, ?string $companyName = null): array
    {
        $error = $this->validateSlug($slug);
        if ($error !== null) {
            throw new RuntimeException($error);
        }

        $companyName = $this->validateCompanyName($companyName, $slug);

        $provisionUser     = getenv('PLATFORM_PROVISION_USERNAME');
        $provisionPassword = getenv('PLATFORM_PROVISION_PASSWORD');

        if (! $provisionUser || ! $provisionPassword) {
            throw new RuntimeException('PLATFORM_PROVISION_USERNAME/PLATFORM_PROVISION_PASSWORD env vars are required (see docs/Tecnico/multi-tenant-arquitectura.md section 11 for the one-time DBA runbook that creates this user).');
        }

        $dbIdentifier = 'tenant_' . str_replace('-', '_', $slug);
        $dbPassword   = bin2hex(random_bytes(16));
        $hostConfig   = $this->hostConfig();

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

            $provisioner->query("CREATE DATABASE `{$dbIdentifier}`");
            $provisioner->query("CREATE USER '{$dbIdentifier}'@'%' IDENTIFIED BY '{$dbPassword}'");
            $provisioner->query("GRANT ALL PRIVILEGES ON `{$dbIdentifier}`.* TO '{$dbIdentifier}'@'%'");
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

        [$output, $exitCode] = $this->runMigration($sparkPath, $dbIdentifier, $dbPassword);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                "Migration failed for {$dbIdentifier} -- NOT registering in platform.tenants. Schema/user already exist; fix and re-run tenant:migrate-one manually, or drop them and retry.\n"
                . implode("\n", $output),
            );
        }

        // initial_schema.sql seeds every fresh schema with Casaletto's
        // OWN admin account (username admin_casaletto and its real
        // bcrypt hash). Left as-is, every new tenant's default login
        // would BE Casaletto's real admin password. Connecting as the
        // tenant's own new user (not the provisioner) to do this
        // doubles as proof the GRANT actually restricts it to this one
        // schema, not just that it exists.
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

            $admin = $this->seedInitialAdmin($tenantDb, $companyName);
        } catch (Throwable $e) {
            throw new RuntimeException('Post-migration default-admin reset failed: ' . $e->getMessage(), 0, $e);
        }

        $adminPassword = $admin['password'];
        $adminHash     = $admin['hash'];

        // service('encrypter')->encrypt() with the configured rawData=false
        // already returns a printable, storable string (hex HMAC + base64
        // ciphertext) -- an extra base64_encode() here just about doubles
        // the length for no benefit, and was overflowing the db_password
        // VARCHAR(255) column, silently truncating it and breaking
        // decryption later (confirmed empirically while testing Fase 8's
        // login flow: TenantResolver's decrypt() failed with "authentication
        // failed" because the stored ciphertext had been cut off).
        $encryptedDbPassword = service('encrypter')->encrypt($dbPassword);
        $now                 = date('Y-m-d H:i:s');

        db_connect('platform')->table('tenants')->insert([
            'slug' => $slug,
            // Se guarda además de escribirlo dentro del negocio. Leerlo del negocio para pintar el
            // listado abriría una conexión por fila y dejaría la lista sin dibujar en cuanto un
            // negocio estuviera suspendido o con su base caída.
            'company_name' => $companyName,
            'db_name'      => $dbIdentifier,
            'db_user'      => $dbIdentifier,
            'db_password'  => $encryptedDbPassword,
            'status'       => 'active',
            'created_at'   => $now,
            'updated_at'   => $now,

            // D5: la copia cifrada y el hash que acabamos de escribir en el negocio. La consola
            // muestra la primera solo mientras `employees.password` siga siendo el segundo.
            'admin_username'        => $admin['username'],
            'admin_password_hash'   => $adminHash,
            'admin_password_cipher' => service('encrypter')->encrypt($adminPassword),
            'admin_password_set_at' => $now,
        ]);

        return [
            'slug'           => $slug,
            'db_name'        => $dbIdentifier,
            'admin_username' => $admin['username'],
            'admin_password' => $adminPassword,
        ];
    }

    /**
     * Todo lo que hay que escribir DENTRO de un esquema recién migrado para que deje de ser un
     * OSPOS de fábrica y sea el negocio de este cliente: el usuario, la contraseña, el nombre de la
     * persona y el perfil de configuración.
     *
     * ES PÚBLICO Y RECIBE LA CONEXIÓN PORQUE SI NO, NO SE PUEDE PROBAR
     *
     * Estaba todo dentro de create(), atado a crear un esquema y un usuario de MySQL nuevos. En el
     * entorno de pruebas el usuario de la base NO puede crear bases de datos -- a propósito -- así
     * que nada de esto tenía cobertura posible, y son justamente los dos ajustes que más caro han
     * salido en este proyecto. Separado, se le pasa cualquier esquema OSPOS y se comprueba lo que
     * dejó escrito.
     *
     * LAS TRES ESCRITURAS VAN JUNTAS Y EN ESTE ORDEN
     *
     * La credencial primero, porque es la que puede fallar por una clave única. Luego el nombre de
     * la persona, que es la fila que la semilla deja en «John Doe» y que hasta hoy no se tocaba.
     * Y el perfil al final, que incluye el idioma del empleado -- después del UPDATE de `employees`,
     * nunca antes, o el primero lo sobreescribiría.
     *
     * @param string      $companyName ya validado por validateCompanyName()
     * @param int         $personId    el empleado inicial: `person_id` 1 en un esquema sembrado
     * @param string|null $username    solo para las pruebas; en producción siempre el de serie
     *
     * @return array{username: string, password: string, hash: string}
     */
    public function seedInitialAdmin(
        BaseConnection $tenantDb,
        string $companyName,
        int $personId = 1,
        ?string $username = null,
    ): array {
        $username ??= self::DEFAULT_ADMIN_USERNAME;
        $password = $this->generateAdminPassword();
        $hash     = password_hash($password, PASSWORD_DEFAULT);

        // initial_schema.sql siembra cada esquema nuevo con el usuario y el hash bcrypt REALES de
        // Casaletto. Sin este reemplazo, la contraseña de administrador de todo negocio nuevo sería
        // la de otro cliente. Ver docs/Tecnico/multi-tenant-arquitectura.md §16.
        $tenantDb->table('employees')->where('person_id', $personId)->update([
            'username'     => $username,
            'password'     => $hash,
            'hash_version' => 2,
        ]);

        // La fila de `people`, que hasta hoy NO se tocaba: la semilla la deja en «John Doe» y así se
        // llamaba el administrador de todo negocio nuevo. Es el mismo `person_id` que el update de
        // arriba, a propósito -- son dos tablas de la misma persona, y separarlos en dos pasos con
        // condiciones distintas es exactamente cómo se llegó a que una quedara sin cambiar.
        $tenantDb->table('people')->where('person_id', $personId)->update([
            'first_name' => TenantConfigProfile::ADMIN_FIRST_NAME,
            'last_name'  => $companyName,
        ]);

        // El perfil «Colombia · comercio al detal» (D12), que sustituye al UPDATE de una sola clave
        // que había aquí. Escribe `app_config` Y la fila del empleado: el idioma vive en los dos
        // sitios y el del empleado gana, así que un perfil que solo escriba el primero deja al
        // negocio hablando el idioma de la semilla.
        (new TenantConfigProfile())->applyTo($tenantDb, $companyName, $personId);

        return ['username' => $username, 'password' => $password, 'hash' => $hash];
    }

    /**
     * El nombre de la empresa, comprobado antes de que llegue a ninguna tabla.
     *
     * Vacío cae al slug, que es lo que hacía este código desde siempre. Demasiado largo se rechaza:
     * `people.last_name` y `tenants.company_name` son VARCHAR(255) y MySQL no está en modo estricto
     * en todas partes, así que un nombre de 300 caracteres se guardaría cortado en unos sitios y
     * entero en otros sin decir nada. Este proyecto ya perdió un día por un truncamiento mudo.
     */
    private function validateCompanyName(?string $companyName, string $slug): string
    {
        $companyName = trim((string) $companyName);

        if ($companyName === '') {
            return $slug;
        }

        // mb_strlen y no strlen: el límite de la columna es en bytes, pero rechazar por bytes le
        // diría a un nombre con tildes que es más largo de lo que se ve escrito. 255 caracteres
        // multibyte podrían pasarse de 255 bytes, así que se comprueban las dos cosas.
        if (mb_strlen($companyName) > self::MAX_COMPANY_NAME || strlen($companyName) > self::MAX_COMPANY_NAME) {
            throw new RuntimeException(
                'The company name is too long (maximum ' . self::MAX_COMPANY_NAME . ' characters). Nothing was created.',
            );
        }

        return $companyName;
    }

    /**
     * La contraseña que se le entrega al cliente. 16 caracteres hexadecimales, 64 bits de entropía,
     * exactamente como estaba antes de que esto fuera un método: extraerlo es lo que permite
     * restablecerla sin recrear el negocio, que es todo el punto de resetAdminPassword().
     */
    private function generateAdminPassword(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Las credenciales compartidas y el servidor, leídos de una instancia NUEVA de Config\Database.
     *
     * Y no de `config(Database::class)`, que es la compartida y que TenantResolver reescribe en
     * sitio: en una petición de la consola de plataforma, `pointAtControlSchema()` ya le puso a ese
     * arreglo el host, el usuario y la contraseña de `platform_control`. Usarlo aquí haría que un
     * negocio adoptado se intentara abrir con el usuario de la plataforma, que no tiene ningún
     * permiso en el esquema del cliente -- y fallaría solo en Casaletto, solo desde la consola, y
     * solo en producción. Una instancia nueva vuelve a leer las variables MYSQL_* del entorno, que
     * es lo que TenantResolver no toca.
     *
     * @return array<string, mixed>
     */
    private function hostConfig(): array
    {
        return (new Database())->default;
    }

    /**
     * Environment for the migration child process.
     *
     * The whole reason this method exists: the child has to connect as the tenant's OWN MySQL
     * user, not as the application's. Until 2026-08-30 only MYSQL_DB_NAME was overridden, so the
     * subprocess connected with the shared credentials -- which hold privileges on `ospos` and
     * `platform_control` and nothing else. Every attempt died on "Access denied for user ... to
     * database 'tenant_...'" AFTER the schema and the user had already been created, leaving a
     * half-built tenant behind and no row in platform.tenants. The user this connects as was
     * created seconds earlier by create(), with ALL PRIVILEGES on exactly this one schema.
     *
     * Built from the full current environment rather than a curated list: the child boots the
     * whole framework and needs CI_ENVIRONMENT, the PLATFORM_DB_* group, the encryption key and
     * whatever else the container passes in. Naming them here would mean this breaks, silently and
     * much later, the first time somebody adds one.
     *
     * @return array<string, string>
     */
    private function migrationEnvironment(string $dbIdentifier, string $dbPassword): array
    {
        $env = getenv();

        $env['MYSQL_DB_NAME']  = $dbIdentifier;
        $env['MYSQL_USERNAME'] = $dbIdentifier;
        $env['MYSQL_PASSWORD'] = $dbPassword;

        return $env;
    }

    /**
     * Runs `tenant:migrate-one` against the new schema, as that schema's own user.
     *
     * proc_open rather than exec(): the password has to reach the child through its environment,
     * and `exec("MYSQL_PASSWORD=... php spark ...")` would put a freshly minted database
     * credential on a command line, where `ps` shows it to every process on the host for as long
     * as the migration runs. In the env array it stays out of the process table, and out of any
     * shell history or trace that records command lines.
     *
     * @return array{0: list<string>, 1: int} the child's output lines, and its exit status
     */
    private function runMigration(string $sparkPath, string $dbIdentifier, string $dbPassword): array
    {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            ['php', $sparkPath, 'tenant:migrate-one'],
            $descriptors,
            $pipes,
            ROOTPATH,
            $this->migrationEnvironment($dbIdentifier, $dbPassword),
        );

        if (! is_resource($process)) {
            return [['Could not start the migration process.'], 1];
        }

        // Both pipes are drained before proc_close, which waits on the child: reading afterwards
        // would deadlock on a process that fills a pipe buffer, and a failed migration is exactly
        // the case that prints a lot.
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $lines    = preg_split('/\r\n|\r|\n/', trim($stdout . "\n" . $stderr)) ?: [];

        return [array_values(array_filter($lines, static fn ($line) => trim($line) !== '')), $exitCode];
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
     *
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
            throw new RuntimeException("Database '{$existingDbName}' is already registered to a tenant.");
        }

        // hostConfig() y no config(Database::class): ese arreglo lo reescribe TenantResolver en
        // sitio, y aquí se usan el usuario y la contraseña compartidos, no solo el nombre del
        // servidor. Ver el comentario del método.
        $hostConfig = $this->hostConfig();
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
            throw new RuntimeException("Could not connect to '{$existingDbName}': " . $e->getMessage(), 0, $e);
        }

        if (! $hasTables) {
            throw new RuntimeException("'{$existingDbName}' does not look like an OSPOS schema (missing employees/app_config tables).");
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
                "'{$existingDbName}' is not on the latest App migration (has {$currentVersion}, latest is {$latestAvailable}). "
                . 'Migrate it first (ej. MYSQL_DB_NAME=' . $existingDbName . ' php spark tenant:migrate-one) and retry adoption.',
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
                "'{$existingDbName}' still has the public upstream default admin credential (admin/pointofsale). "
                . 'Change it before adopting this business as a tenant.',
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
     * La contraseña del administrador de un negocio, si todavía se puede enseñar (D5).
     *
     * LA REGLA, EN UNA FRASE: se muestra mientras el hash que hay hoy en el negocio siga siendo el
     * que escribimos nosotros. En cuanto difiere, el cliente la cambió y la copia deja de ser
     * verdad -- y una copia que ya no es verdad es peor que ninguna, porque manda a alguien a
     * intentar entrar con una contraseña que no funciona.
     *
     * Por eso este método BORRA la copia cuando detecta el cambio, en vez de limitarse a no
     * mostrarla. Es la única forma de que la respuesta no dependa de cuándo se preguntó, y de que la
     * contraseña vieja de un cliente no se quede cifrada en nuestra base para siempre.
     *
     * SE CONSULTA EL NEGOCIO DE VERDAD, NO UNA MARCA
     *
     * Sería más barato guardar una bandera «ya la cambió» y consultarla. Sería también mentira: el
     * cliente cambia su contraseña desde su propio punto de venta, que no le avisa a esta consola de
     * nada. La única fuente de verdad es la fila de `employees` de ese negocio, y hay que ir a
     * leerla.
     *
     * Lanza SOLO si el slug no existe, que es un error de programación o una dirección inventada.
     * Un negocio inalcanzable NO es una excepción: es el estado `unreachable`, porque esta es la
     * pantalla desde la que se reactiva un negocio suspendido y no puede caerse justo ahí.
     *
     * @return array{state: string, username: string|null, password: string|null, set_at: string|null}
     *
     * @throws RuntimeException if no business carries that slug.
     */
    public function adminCredential(string $slug): array
    {
        $tenant = $this->requireTenant($slug);

        $username = trim((string) ($tenant->admin_username ?? ''));
        $hash     = (string) ($tenant->admin_password_hash ?? '');
        $cipher   = (string) ($tenant->admin_password_cipher ?? '');
        $setAt    = $tenant->admin_password_set_at ?? null;

        // Nunca hubo copia (Casaletto y Paraíso, dados de alta antes de que esto existiera), o ya
        // se borró. No hay nada que comprobar y no hay por qué abrir una conexión al negocio.
        if ($hash === '' || $cipher === '') {
            return $this->credential(self::CREDENTIAL_NONE, $username === '' ? null : $username);
        }

        try {
            $currentHash = $this->currentAdminHash($tenant, $username);
        } catch (Throwable $e) {
            // La ficha del negocio no puede caerse porque su base esté suspendida o inalcanzable, y
            // sobre todo la copia NO se borra aquí: no comprobamos nada, así que no sabemos nada.
            log_message('error', "No se pudo comprobar la contraseña del negocio '{$slug}': " . $e->getMessage());

            return $this->credential(self::CREDENTIAL_UNREACHABLE, $username, null, $setAt);
        }

        if ($currentHash === null || ! hash_equals($hash, $currentHash)) {
            $this->forgetAdminCredential($slug);

            return $this->credential(self::CREDENTIAL_CHANGED, $username);
        }

        try {
            $password = service('encrypter')->decrypt($cipher);
        } catch (Throwable $e) {
            // La copia NO se borra aquí. Si lo que falló es la clave de cifrado, borrarla
            // convertiría un problema reparable -- restaurar la clave -- en una pérdida definitiva.
            log_message('critical', "No se pudo descifrar la contraseña guardada del negocio '{$slug}': " . $e->getMessage());

            return $this->credential(self::CREDENTIAL_UNREADABLE, $username, null, $setAt);
        }

        return $this->credential(self::CREDENTIAL_AVAILABLE, $username, $password, $setAt);
    }

    /**
     * Genera una contraseña nueva para el administrador de un negocio y la deja guardada como si
     * acabara de darse de alta: escrita en el negocio, cifrada aquí, y consultable otra vez.
     *
     * Es la mitad que faltaba de D5. La lógica ya existía dentro de create() -- generar, hacer el
     * hash, escribirlo en `employees` -- y estaba atada a crear un esquema desde cero, así que la
     * única forma de recuperar un negocio sin contraseña era rehacerlo entero.
     *
     * EL USUARIO SE PIDE, NO SE SUPONE
     *
     * Un negocio provisionado por nosotros tiene `admin`. Casaletto, que es adoptado, tiene
     * `admin_casaletto`, y sus seis empleados son personas reales. Inventar el usuario aquí
     * significaría, en el mejor caso, no encontrar a nadie y en el peor cambiarle la contraseña al
     * empleado equivocado en el negocio que está vendiendo. Si el usuario no existe en ese negocio,
     * esto se niega y lo dice.
     *
     * @param string|null $username a quién. Por defecto, el que la plataforma tenga guardado.
     *
     * @return array{slug: string, username: string, password: string}
     *
     * @throws RuntimeException if the slug is unknown, the user does not exist, or the write fails.
     */
    public function resetAdminPassword(string $slug, ?string $username = null): array
    {
        $tenant = $this->requireTenant($slug);

        $username = trim((string) ($username ?? $tenant->admin_username ?? ''));

        if ($username === '') {
            $username = self::DEFAULT_ADMIN_USERNAME;
        }

        // La conexión va DENTRO del try. Abrirla descifra la contraseña de base de datos del
        // negocio, y si la clave de cifrado ya no es la que la guardó, eso lanza una excepción de
        // cifrado -- no una RuntimeException. Fuera del try saldría de aquí sin envolver, la
        // pantalla que llama solo atrapa RuntimeException, y una avería de la plataforma se vería
        // como un error 500 sin explicación en vez de como un mensaje que nombra el negocio.
        try {
            $tenantDb = $this->connectToTenant($tenant);
            $exists   = $tenantDb->table('employees')->where('username', $username)->countAllResults() > 0;
        } catch (Throwable $e) {
            throw new RuntimeException("Could not read the employees of '{$slug}': " . $e->getMessage(), 0, $e);
        }

        if (! $exists) {
            throw new RuntimeException(
                "Business '{$slug}' has no employee with the username '{$username}', so there is nothing to reset. "
                . 'Nothing was changed.',
            );
        }

        $password = $this->generateAdminPassword();
        $hash     = password_hash($password, PASSWORD_DEFAULT);

        try {
            $tenantDb->table('employees')->where('username', $username)->update([
                'password'     => $hash,
                'hash_version' => 2,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException("Could not write the new password into '{$slug}': " . $e->getMessage(), 0, $e);
        }

        // Solo después de que el negocio la tenga. Al revés, un fallo al escribir en el negocio
        // dejaría en la consola una contraseña que allí no abre nada, que es la única forma de que
        // esta pantalla mienta.
        $this->rememberAdminCredential($slug, $username, $password, $hash);

        return ['slug' => $slug, 'username' => $username, 'password' => $password];
    }

    /**
     * Toggles a tenant between 'active' and 'suspended'. TenantResolver
     * (Fase 4) already refuses to resolve a non-active tenant, so this
     * is enough to cut off access without touching its schema or data.
     */
    public function setStatus(string $slug, string $status): bool
    {
        if (! in_array($status, ['active', 'suspended'], true)) {
            throw new RuntimeException("Invalid status '{$status}'.");
        }

        return db_connect('platform')->table('tenants')
            ->where('slug', $slug)
            ->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Lo que el negocio tiene HOY en las claves que le interesan a la ficha, leído de su propia
     * `app_config`.
     *
     * Se lee del negocio y no se repite lo que el perfil dice que debería haber. Un perfil que se
     * aplicó mal, o un valor que el cliente cambió después, se ven aquí; una tabla que repitiera las
     * constantes de TenantConfigProfile no enseñaría nada y afirmaría que todo está bien pase lo que
     * pase, que es la peor clase de pantalla.
     *
     * Devuelve null en la clave que el negocio no tenga, para poder distinguir «vacía» de «no está».
     *
     * @param list<string> $keys
     *
     * @return array<string, string|null>
     *
     * @throws RuntimeException if the slug is unknown or the business cannot be reached.
     */
    public function currentSettings(string $slug, array $keys): array
    {
        $tenant = $this->requireTenant($slug);
        $found  = array_fill_keys($keys, null);

        if ($keys === []) {
            return $found;
        }

        try {
            $rows = $this->connectToTenant($tenant)
                ->table('app_config')
                ->select('key, value')
                ->whereIn('key', $keys)
                ->get()
                ->getResult();
        } catch (Throwable $e) {
            throw new RuntimeException("Could not read the settings of '{$slug}': " . $e->getMessage(), 0, $e);
        }

        foreach ($rows as $row) {
            $found[$row->key] = (string) $row->value;
        }

        return $found;
    }

    /**
     * La fila del negocio, o un error que lo nombra. Un slug que nadie registró no puede acabar en
     * un `null` que el resto del método interprete como «no tiene contraseña guardada».
     *
     * @throws RuntimeException when no business carries that slug.
     */
    private function requireTenant(string $slug): object
    {
        $tenant = db_connect('platform')->table('tenants')->where('slug', $slug)->get()->getRow();

        if ($tenant === null) {
            throw new RuntimeException("Tenant slug '{$slug}' not found.");
        }

        return $tenant;
    }

    /**
     * El hash que el negocio tiene HOY para ese usuario, o null si ese usuario ya no está.
     *
     * Se busca por nombre de usuario y no por `person_id`, porque lo que la ficha promete es que
     * ESA pareja usuario/contraseña sigue abriendo el negocio. Un `person_id` que cambió de nombre
     * de usuario ya no cumple la promesa aunque su hash coincida.
     *
     * No se filtra por `deleted`: un administrador dado de baja tampoco entra, pero su fila sigue
     * ahí, y devolver su hash haría que la consola siguiera ofreciendo una contraseña inútil.
     * Devolver el hash y dejar que la comparación decida es lo correcto -- si el cliente lo dio de
     * baja sin cambiar la contraseña, el hash coincide, y lo que sobra es un aviso que esta entrega
     * no tiene dónde poner. Queda anotado.
     */
    private function currentAdminHash(object $tenant, string $username): ?string
    {
        if ($username === '') {
            return null;
        }

        try {
            $row = $this->connectToTenant($tenant)
                ->table('employees')
                ->select('password')
                ->where('username', $username)
                ->get()
                ->getRow();
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Could not read the employees of '{$tenant->slug}': " . $e->getMessage(),
                0,
                $e,
            );
        }

        return $row === null ? null : (string) $row->password;
    }

    /**
     * Abre una conexión al esquema de un negocio, por los DOS caminos que existen.
     *
     * Un negocio provisionado por create() tiene su propio usuario de MySQL, cifrado en la fila. Un
     * negocio ADOPTADO -- Casaletto, que es el que está vendiendo -- tiene `db_user` y `db_password`
     * vacíos y cae a las credenciales compartidas, porque adopt() nunca le creó un usuario propio.
     * Un método que asuma usuario dedicado falla justo ahí. Es la misma bifurcación que hace
     * TenantResolver en cada petición, escrita aquí porque este código no corre dentro de una.
     */
    private function connectToTenant(object $tenant): BaseConnection
    {
        $hostConfig = $this->hostConfig();

        $username = (string) $hostConfig['username'];
        $password = (string) $hostConfig['password'];

        if (! empty($tenant->db_user) && ! empty($tenant->db_password)) {
            $username = (string) $tenant->db_user;
            $password = service('encrypter')->decrypt($tenant->db_password);
        }

        return Database::connect([
            'hostname' => $hostConfig['hostname'],
            'username' => $username,
            'password' => $password,
            'DBDriver' => $hostConfig['DBDriver'],
            'database' => $tenant->db_name,
            'DBPrefix' => $hostConfig['DBPrefix'] ?? 'ospos_',
            'charset'  => $hostConfig['charset'] ?? 'utf8mb4',
            'DBCollat' => $hostConfig['DBCollat'] ?? 'utf8mb4_general_ci',
        ], false);
    }

    /**
     * Guarda la copia consultable. Cifrada, y SOLO en `platform_control`.
     *
     * Sin `base64_encode()` encima: `service('encrypter')->encrypt()` con `rawData=false` ya
     * devuelve texto imprimible (HMAC en hexadecimal + base64 del resto), y la capa de más fue
     * exactamente lo que desbordó `tenants.db_password` en VARCHAR(255), lo truncó en silencio y
     * rompió el descifrado sin un solo error. `admin_password_cipher` es TEXT por la misma razón.
     */
    private function rememberAdminCredential(string $slug, string $username, string $password, string $hash): void
    {
        db_connect('platform')->table('tenants')->where('slug', $slug)->update([
            'admin_username'        => $username,
            'admin_password_hash'   => $hash,
            'admin_password_cipher' => service('encrypter')->encrypt($password),
            'admin_password_set_at' => date('Y-m-d H:i:s'),
            'updated_at'            => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Borra la copia en cuanto deja de ser verdad. El usuario se conserva: la ficha sigue pudiendo
     * decir de quién era la contraseña que ya no se puede ver, y el restablecimiento sabe a quién
     * apuntar sin que nadie lo teclee.
     */
    private function forgetAdminCredential(string $slug): void
    {
        db_connect('platform')->table('tenants')->where('slug', $slug)->update([
            'admin_password_hash'   => null,
            'admin_password_cipher' => null,
            'admin_password_set_at' => null,
            'updated_at'            => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array{state: string, username: string|null, password: string|null, set_at: string|null}
     */
    private function credential(
        string $state,
        ?string $username = null,
        ?string $password = null,
        ?string $setAt = null,
    ): array {
        return [
            'state'    => $state,
            'username' => $username,
            'password' => $password,
            'set_at'   => $setAt,
        ];
    }

    /**
     * Was this tenant ADOPTED (registered from an already-existing
     * schema by adopt()) rather than provisioned by create()?
     *
     * The tell is db_user: adopt() deliberately creates no dedicated
     * MySQL user -- see its docblock -- and inserts the row with
     * db_user/db_password left empty, so the schema keeps running on
     * the shared credentials it already used. create(), by contrast,
     * always writes a db_user equal to the schema name.
     *
     * This is not a cosmetic distinction. Casaletto is the real case,
     * and its db_name is `ospos`: the database the business trades on,
     * which never belonged to the platform and must not be torn down by
     * it. Whatever else changes, an adopted tenant's schema was here
     * first.
     */
    public function isAdopted(object $tenant): bool
    {
        return trim((string) ($tenant->db_user ?? '')) === '';
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
     *
     * Refuses outright to touch an ADOPTED tenant, and refuses BEFORE
     * opening the provisioning connection. This method is the only door
     * to `DROP DATABASE` in the whole application, so the guarantee
     * belongs here rather than in the one screen that happens to call
     * it today: a command, a script or a second screen would each have
     * to re-implement a check that lives in a controller. Tearing an
     * adopted tenant down is a deliberate DBA operation, by hand, with
     * a backup taken first -- never something a web request does.
     */
    public function delete(string $slug, bool $dropSchema = false): bool
    {
        $platformDb = db_connect('platform');
        $tenant     = $platformDb->table('tenants')->where('slug', $slug)->get()->getRow();

        if ($tenant === null) {
            throw new RuntimeException("Tenant slug '{$slug}' not found.");
        }

        if ($this->isAdopted($tenant)) {
            throw new RuntimeException(
                "Tenant '{$slug}' was adopted, not provisioned: its schema '{$tenant->db_name}' existed before the "
                . 'platform did and has no dedicated database user. It cannot be deleted from here. Unregister it by '
                . 'hand, with a backup taken first, if that is really what you want.',
            );
        }

        $provisionUser     = getenv('PLATFORM_PROVISION_USERNAME');
        $provisionPassword = getenv('PLATFORM_PROVISION_PASSWORD');

        if ($provisionUser && $provisionPassword) {
            $hostConfig = $this->hostConfig();

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
