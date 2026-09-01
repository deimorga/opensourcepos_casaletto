<?php

declare(strict_types=1);

namespace Tests\Database;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Platform\Database\Migrations\CreatePlatformSessions;

/**
 * The console's session table, in the control schema.
 *
 * There is exactly one thing here that is easy to get wrong and impossible to notice until it
 * bites: Config\Session::$matchIP is true in this project, so CodeIgniter's DatabaseHandler reads
 * and writes rows keyed by (id, ip_address). With a primary key on `id` alone the handler still
 * runs -- it just cannot find the row again once the client's address changes, and the console
 * silently logs the superadministrator out mid-task with no error anywhere. The framework's own
 * manual says as much next to that setting; app/Database/Migrations/sqlscripts/
 * 3.4.1_migrate_sessions_table.sql is where OSPOS already did it right for the POS.
 *
 * @internal
 */
final class PlatformSessionsMigrationTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Migration files are named with their version prefix, so they do not satisfy PSR-4 and no
        // autoloader will find them -- CodeIgniter's MigrationRunner requires them by path. The
        // runner is deliberately not used here: it would also apply the other four Platform
        // migrations to the shared test schema and collide with TenantResolverTest's own `tenants`
        // table (see phpunit.xml.dist for why the platform group points at the test schema).
        require_once APPPATH . 'Platform/Database/Migrations/20260901000000_CreatePlatformSessions.php';

        $this->dropTable();
        (new CreatePlatformSessions())->up();
        db_connect('platform')->resetDataCache();
    }

    protected function tearDown(): void
    {
        $this->dropTable();

        parent::tearDown();
    }

    private function dropTable(): void
    {
        db_connect('platform')->query('DROP TABLE IF EXISTS `platform_sessions`');
        db_connect('platform')->resetDataCache();
    }

    public function testTheTableIsCreatedOnThePlatformGroup(): void
    {
        $this->assertTrue(db_connect('platform')->tableExists('platform_sessions'));
    }

    /**
     * The assertion this file exists for.
     */
    public function testThePrimaryKeyCoversBothTheIdAndTheAddress(): void
    {
        $primary = null;

        foreach (db_connect('platform')->getIndexData('platform_sessions') as $index) {
            if ($index->name === 'PRIMARY') {
                $primary = $index;
            }
        }

        $this->assertNotNull($primary, 'Without a primary key the handler cannot lock a session row at all.');
        $this->assertSame(
            ['id', 'ip_address'],
            $primary->fields,
            'Config\Session::$matchIP is true, so a key on id alone loses the session the moment the address changes.',
        );
    }

    /**
     * The garbage collector deletes by timestamp on every request that opens a session, so it is
     * the one column that is read without the key.
     */
    public function testTheTimestampIsIndexedForTheGarbageCollector(): void
    {
        $names = array_column(db_connect('platform')->getIndexData('platform_sessions'), 'name');

        $this->assertContains('platform_sessions_timestamp', $names);
    }

    public function testTheColumnsMatchWhatTheDatabaseHandlerWritesAndReads(): void
    {
        $fields = array_column(db_connect('platform')->getFieldData('platform_sessions'), 'name');

        sort($fields);

        $this->assertSame(['data', 'id', 'ip_address', 'timestamp'], $fields);
    }

    /**
     * A migration that cannot be undone is a migration nobody dares to run.
     */
    public function testItIsReversible(): void
    {
        (new CreatePlatformSessions())->down();
        db_connect('platform')->resetDataCache();

        $this->assertFalse(db_connect('platform')->tableExists('platform_sessions'));
    }

    /**
     * The platform group carries an empty DBPrefix while the tenant groups carry `ospos_`. Naming
     * the table `platform_sessions` rather than `sessions` is what keeps the console's rows from
     * being confused with a business's own `sessions` table if the two ever share a schema -- which
     * is exactly what happens in this test environment (see phpunit.xml.dist).
     */
    public function testTheNameDoesNotCollideWithATenantSessionsTable(): void
    {
        $this->assertNotSame(
            'platform_sessions',
            config(Database::class)->default['DBPrefix'] . 'sessions',
        );
    }
}
