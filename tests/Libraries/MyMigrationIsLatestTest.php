<?php

declare(strict_types=1);

namespace Tests\Libraries;

use App\Libraries\MY_Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * The one boolean that decides whether anybody can stay logged in.
 *
 * app/Events/Load_config.php is hooked on post_controller_constructor, so it runs on EVERY request,
 * and its first act is:
 *
 *     if (! $migration->is_latest()) { $this->session->destroy(); }
 *
 * When is_latest() returns false the login form sets a session and the very next request throws it
 * away: nobody can log in, the till does not open, and every health check stays green -- the deploy
 * succeeded, Apache answers HTTP 200, the database is up. It has already happened once in this
 * project. Nothing anywhere says why.
 *
 * WHY EACH CASE BELOW IS HERE
 *
 * The comparison is strict equality (`$latest_version === $current_version`), not `>=`. That makes
 * THREE states, not two, and the third is the one that closes the till: a database AHEAD of the
 * files. That is what a rollback to an image older than the schema produces -- see the rule in
 * AGENTS.md about never rolling an image back past the database -- and until this file existed
 * nothing tested it.
 *
 * The fourth case is the namespace filter in get_current_version(). The migration history table is
 * shared by every registered namespace, and in production `ospos_migrations` really does carry
 * Platform rows. As of 2026-09-23 App's newest file (2026-09-23) happens to sit numerically above
 * Platform's newest (2026-09-09), so an unfiltered read would match by luck; the next Platform
 * migration dated after App's takes that luck away, which is exactly the failure the long comment
 * in MY_Migration::get_current_version() describes. The test does not depend on which namespace
 * currently happens to be higher: it writes a Platform row above anything on disk and demands that
 * App's own version is still what comes back.
 *
 * HOW THE SHARED DATABASE IS PROTECTED
 *
 * This suite runs every test file against ONE database with $refresh = false, so nothing puts it
 * back between tests, and a stray row in the migration history is the worst thing to leave behind:
 * it is what tests/Events/LoadConfigPlatformTest.php warns about in its own docblock, because a
 * leftover future version leaves the schema half-built for the next full run and the failures then
 * surface a dozen files away from the cause. So setUp() snapshots the WHOLE table and tearDown()
 * writes it back verbatim, rows and ids included -- not a list of "the versions I touch", which
 * stops being complete the moment somebody adds a migration.
 *
 * @internal
 */
final class MyMigrationIsLatestTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    /**
     * A version no migration file can plausibly ever carry, so "the database is ahead of the files"
     * is unambiguous however many migrations are added later.
     */
    private const FUTURE = '29991231000000';

    /**
     * The entire migration history as it was found, restored verbatim in tearDown().
     *
     * @var list<array<string, mixed>>
     */
    private array $historyBefore = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The driver answers tableExists() from a schema list built when the process started.
        Database::connect()->resetDataCache();

        $this->historyBefore = Database::connect()
            ->table('migrations')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
    }

    protected function tearDown(): void
    {
        Database::connect()->table('migrations')->emptyTable();

        if ($this->historyBefore !== []) {
            Database::connect()->table('migrations')->insertBatch($this->historyBefore);
        }

        parent::tearDown();
    }

    /**
     * The state every working deployment is in. Files and database agree, and it is the only one of
     * the four that lets a session survive to the next request.
     */
    public function testASchemaThatMatchesTheFilesIsTheOnlyStateThatKeepsTheSessionAlive(): void
    {
        $latest = $this->newestVersionOnDisk();

        $this->historyIs([[$latest, 'App']]);

        $this->assertSame((int) $latest, MY_Migration::get_current_version());
        $this->assertTrue(
            $this->runner()->is_latest(),
            'Files and database agree, so Load_config must leave the session alone.',
        );
    }

    /**
     * A schema BEHIND the files: the deploy landed but a migration did not run. Refusing it is the
     * point of the check -- a half-migrated schema must not be sold through.
     */
    public function testASchemaBehindTheFilesIsRefused(): void
    {
        $versions = $this->versionsOnDisk();
        $previous = $versions[count($versions) - 2];

        $this->historyIs([[$previous, 'App']]);

        $this->assertSame((int) $previous, MY_Migration::get_current_version());
        $this->assertFalse($this->runner()->is_latest());
    }

    /**
     * THE ROLLBACK CASE, and the reason this file was written.
     *
     * An image whose newest migration sits BELOW the database's -- a rollback to a tag older than
     * the schema -- is not "close enough": the comparison is `===`, so it is just as false as a
     * missing migration, and Load_config destroys the session on every request. The symptom is
     * that nobody can log in while every check is green. The cure is to roll the image and the
     * database backup back together.
     */
    public function testASchemaAheadOfTheFilesIsRefusedJustTheSame(): void
    {
        $latest = $this->newestVersionOnDisk();

        $this->historyIs([[$latest, 'App'], [self::FUTURE, 'App']]);

        $this->assertGreaterThan(
            (int) $latest,
            MY_Migration::get_current_version(),
            'The fixture has to leave the database ahead of the files for this test to mean anything.',
        );
        $this->assertFalse(
            $this->runner()->is_latest(),
            'Strict equality: a database ahead of the image closes the till exactly like one behind it.',
        );
    }

    /**
     * The namespace filter. `ospos_migrations` is shared, and Platform's version numbers move on
     * their own; without `WHERE namespace = 'App'` a Platform row above App's newest file makes
     * is_latest() false forever, on every request, for every business.
     */
    public function testHistoryFromAnotherNamespaceDoesNotCount(): void
    {
        $latest = $this->newestVersionOnDisk();

        $this->historyIs([[$latest, 'App'], [self::FUTURE, 'Platform']]);

        $this->assertSame(
            (int) $latest,
            MY_Migration::get_current_version(),
            'A Platform row must not be read as this schema\'s own version.',
        );
        $this->assertSame(
            (int) self::FUTURE,
            MY_Migration::get_current_version('Platform'),
            'The filter has to select per namespace, not ignore the Platform rows altogether.',
        );
        $this->assertTrue($this->runner()->is_latest());
    }

    /**
     * A schema with no App history at all: a container on its very first boot, before
     * scripts/migrate-tenants.sh has run. It reads as version 0, which is also what
     * Controllers/Login.php turns into `is_new_install`, and is_latest() is false -- correctly, as
     * there is no schema yet to sell through.
     */
    public function testAnEmptyHistoryReadsAsAFreshInstall(): void
    {
        $this->historyIs([]);

        $this->assertSame(0, MY_Migration::get_current_version());
        $this->assertFalse($this->runner()->is_latest());
    }

    /**
     * The other half of the comparison, which no database row can influence: the newest migration
     * FILE in the App namespace. Asserted against the directory listing rather than against a
     * hard-coded number, so it keeps holding as migrations are added -- and so that a framework
     * upgrade that changes how findMigrations() or getMigrationNumber() behave fails here, in a
     * test, instead of at a register on a Monday morning.
     */
    public function testTheLatestVersionIsTheNewestFileInTheAppNamespace(): void
    {
        $this->assertSame((int) $this->newestVersionOnDisk(), $this->runner()->get_latest_migration());
    }

    /**
     * The runner exactly as app/Events/Load_config.php builds it, namespace included: without the
     * setNamespace('App') call, findMigrations() scans every registered namespace and the value
     * compared against App's history is whichever namespace happens to number highest.
     */
    private function runner(): MY_Migration
    {
        $runner = new MY_Migration(config('Migrations'));
        $runner->setNamespace('App');

        return $runner;
    }

    /**
     * Replaces the migration history with exactly the given [version, namespace] pairs. Restored in
     * tearDown(); see the class docblock.
     *
     * @param list<array{0: string, 1: string}> $rows
     */
    private function historyIs(array $rows): void
    {
        Database::connect()->table('migrations')->emptyTable();

        foreach ($rows as [$version, $namespace]) {
            Database::connect()->table('migrations')->insert([
                'version'   => $version,
                'class'     => 'Tests\\Libraries\\NotARealMigration',
                'group'     => config(Database::class)->defaultGroup,
                'namespace' => $namespace,
                'time'      => time(),
                'batch'     => 1,
            ]);
        }
    }

    /**
     * Every App migration version on disk, ascending, read the same way the runner reads them: the
     * 14-digit prefix of each file name.
     *
     * @return list<string>
     */
    private function versionsOnDisk(): array
    {
        $versions = [];

        foreach (glob(APPPATH . 'Database/Migrations/*.php') ?: [] as $path) {
            if (preg_match('/^(\d{14})_/', basename($path), $matches) === 1) {
                $versions[] = $matches[1];
            }
        }

        sort($versions);

        $this->assertGreaterThan(1, count($versions), 'Without the migration files there is nothing to compare.');

        return $versions;
    }

    private function newestVersionOnDisk(): string
    {
        $versions = $this->versionsOnDisk();

        return $versions[count($versions) - 1];
    }
}
