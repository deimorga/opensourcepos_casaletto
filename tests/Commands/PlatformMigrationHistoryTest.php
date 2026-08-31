<?php

namespace Tests\Commands;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\OSPOS;

/**
 * The Platform namespace's migration history was found in production on
 * 2026-08-31 living inside `ospos` -- Casaletto's database -- because the
 * built-in `migrate` command always writes its history on the DEFAULT
 * connection no matter what `-g` says (MigrationRunner::latest() calls
 * ensureTable() before setGroup()). These tests cover the two commands that
 * move ownership of that history to platform_control.
 *
 * First command tests in this suite, hence StreamFilterTrait: spark commands
 * write straight to STDOUT, so the output has to be captured at the stream
 * level to be asserted on.
 *
 * In the test environment the `platform` group points at the same schema as
 * `default` but with an EMPTY prefix (see phpunit.xml.dist), so the two history
 * tables are genuinely distinct: `migrations` for the platform group,
 * `ospos_migrations` for the default one. That is what makes this testable
 * without a second database.
 *
 * @internal
 */
final class PlatformMigrationHistoryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    private const SEEDED = [
        ['version' => '20260730000000', 'class' => 'Platform\\Database\\Migrations\\CreatePlatformTenants'],
        ['version' => '20260730000001', 'class' => 'Platform\\Database\\Migrations\\CreatePlatformAccounts'],
    ];

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    protected function setUp(): void
    {
        parent::setUp();

        // The grouped `tests` connection caches the pre-migration table list,
        // which leaves Config\OSPOS with incomplete defaults.
        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();

        $this->cleanUp();

        // A platform schema that has already been provisioned: `tenants` exists.
        db_connect('platform')->query(
            'CREATE TABLE `tenants` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(100) NOT NULL UNIQUE,
                db_name VARCHAR(100) NOT NULL
            )',
        );

        $this->seedSourceHistory();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $platformDb = db_connect('platform');
        $platformDb->query('DROP TABLE IF EXISTS `tenants`');
        $platformDb->query('DROP TABLE IF EXISTS `migrations`');

        db_connect()->table(config('Migrations')->table)
            ->where('namespace', 'Platform')
            ->delete();
    }

    private function seedSourceHistory(): void
    {
        $table = config('Migrations')->table;

        foreach (self::SEEDED as $i => $row) {
            db_connect()->table($table)->insert([
                'version'   => $row['version'],
                'class'     => $row['class'],
                'group'     => 'platform',
                'namespace' => 'Platform',
                'time'      => 1756600000 + $i,
                'batch'     => 1,
            ]);
        }
    }

    private function platformHistoryCount(): int
    {
        $platformDb = db_connect('platform');

        if (! $platformDb->tableExists('migrations')) {
            return 0;
        }

        return $platformDb->table('migrations')->where('namespace', 'Platform')->countAllResults();
    }

    // ===== Lo que impide re-crear las tablas de la plataforma =====

    public function testMigrateSeNiegaCuandoLaPlataformaNoTieneHistorialPropio(): void
    {
        command('platform:migrate');

        $this->assertStringContainsString('no migration history of its own', $this->getStreamFilterBuffer());
        $this->assertStringContainsString('platform:adopt-history', $this->getStreamFilterBuffer());
        $this->assertSame(0, $this->platformHistoryCount(), 'no debe haber escrito historial al negarse');
    }

    // ===== La adopcion =====

    public function testAdoptImportaElHistorialExistente(): void
    {
        command('platform:adopt-history');

        $this->assertSame(count(self::SEEDED), $this->platformHistoryCount());

        $imported = db_connect('platform')->table('migrations')
            ->where('namespace', 'Platform')->orderBy('version', 'ASC')->get()->getResult();

        $this->assertSame(self::SEEDED[0]['version'], $imported[0]->version);
        $this->assertSame(self::SEEDED[0]['class'], $imported[0]->class);
        $this->assertSame('platform', $imported[0]->group);
    }

    public function testAdoptNoBorraLasFilasDeLaBaseDelCliente(): void
    {
        command('platform:adopt-history');

        $remaining = db_connect()->table(config('Migrations')->table)
            ->where('namespace', 'Platform')->countAllResults();

        $this->assertSame(
            count(self::SEEDED),
            $remaining,
            'las filas del esquema del cliente se dejan a proposito: son la red que impide que el comando antiguo re-cree las tablas',
        );
    }

    public function testAdoptSeNiegaSiLaPlataformaYaTieneSuHistorial(): void
    {
        command('platform:adopt-history');
        $this->resetStreamFilterBuffer();

        command('platform:adopt-history');

        $this->assertStringContainsString('already owns its Platform history', $this->getStreamFilterBuffer());
        $this->assertSame(count(self::SEEDED), $this->platformHistoryCount(), 'no debe duplicar filas');
    }
}
