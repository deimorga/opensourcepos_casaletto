<?php

namespace Tests\Libraries;

use App\Libraries\TenantProvisioner;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionMethod;

/**
 * Covers the environment handed to the migration subprocess.
 *
 * This is the assertion that would have caught the 2026-08-30 failure. Provisioning creates the
 * schema and a dedicated MySQL user, then shells out to `tenant:migrate-one` -- and that child was
 * only told which DATABASE to use, never which USER. It connected with the application's shared
 * credentials, which hold privileges on `ospos` and `platform_control` and nothing else, and died
 * on "Access denied" after the schema and user already existed. The panel reported a failure and
 * left a half-built tenant behind.
 *
 * There is no test here that provisions for real: the test database user cannot CREATE DATABASE,
 * by design. What can be pinned down is the contract with the child process, which is where the
 * defect lived.
 */
class TenantProvisionerEnvTest extends CIUnitTestCase
{
    private function environmentFor(string $identifier, string $password): array
    {
        $method = new ReflectionMethod(TenantProvisioner::class, 'migrationEnvironment');
        $method->setAccessible(true);

        return $method->invoke(new TenantProvisioner(), $identifier, $password);
    }

    /**
     * The defect, stated as an assertion.
     */
    public function testTheChildConnectsAsTheTenantsOwnUserAndNotTheApplications(): void
    {
        $env = $this->environmentFor('tenant_prueba', 'clave-secreta');

        $this->assertSame('tenant_prueba', $env['MYSQL_DB_NAME']);
        $this->assertSame('tenant_prueba', $env['MYSQL_USERNAME'], 'The shared user has no rights on a new schema.');
        $this->assertSame('clave-secreta', $env['MYSQL_PASSWORD']);
    }

    /**
     * The child boots the whole framework, so it needs everything the container passes in -- the
     * platform connection above all, since the migration registry lives there. Inheriting and
     * overriding three keys is what keeps this from breaking silently the day somebody adds a
     * fourth variable.
     */
    public function testEverythingElseInTheEnvironmentIsInherited(): void
    {
        putenv('OSPOS_PRUEBA_HEREDADA=presente');

        try {
            $env = $this->environmentFor('tenant_prueba', 'x');

            $this->assertSame('presente', $env['OSPOS_PRUEBA_HEREDADA'] ?? null);
            $this->assertGreaterThan(3, count($env), 'A curated list would drop what the child needs.');
        } finally {
            putenv('OSPOS_PRUEBA_HEREDADA');
        }
    }

    /**
     * The three keys are overridden, not appended to whatever the parent already had -- the parent
     * process is connected as the application user, to the application's schema.
     */
    public function testTheParentsOwnConnectionSettingsDoNotLeakThrough(): void
    {
        putenv('MYSQL_USERNAME=usuario_de_la_app');
        putenv('MYSQL_PASSWORD=clave_de_la_app');
        putenv('MYSQL_DB_NAME=ospos');

        try {
            $env = $this->environmentFor('tenant_otro', 'clave-del-tenant');

            $this->assertSame('tenant_otro', $env['MYSQL_USERNAME']);
            $this->assertSame('clave-del-tenant', $env['MYSQL_PASSWORD']);
            $this->assertSame('tenant_otro', $env['MYSQL_DB_NAME']);
        } finally {
            putenv('MYSQL_USERNAME');
            putenv('MYSQL_PASSWORD');
            putenv('MYSQL_DB_NAME');
        }
    }

    /**
     * The password travels in the child's environment, never on a command line where `ps` would
     * show it to every process on the host for as long as the migration runs.
     *
     * Asserted through the mechanism rather than by grepping the source for the string: the
     * explanation of why the old form was wrong necessarily quotes the old form, so a text search
     * flags the comment that documents the fix. What can be checked cheaply and without spawning a
     * real migration is that the process is started from an argument LIST -- proc_open with an
     * array never goes through a shell, so there is no command line to leak into.
     */
    public function testTheMigrationIsStartedWithoutAShell(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Libraries/TenantProvisioner.php');
        $body = substr($source, (int) strpos($source, 'private function runMigration'));

        $this->assertStringContainsString('proc_open', $body);
        $this->assertStringContainsString('[\'php\', $sparkPath, \'tenant:migrate-one\']', $body,
            'An argument list, not a string: a string would be run through a shell.');
        $this->assertStringNotContainsString('exec(', $body);
    }
}
