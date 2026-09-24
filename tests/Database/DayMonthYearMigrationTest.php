<?php

namespace Tests\Database;

use App\Database\Migrations\Migration_DayMonthYearDateFormat;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\OSPOS;

/**
 * 20260924010000_DayMonthYearDateFormat: day/month/year for a Spanish-speaking business still on
 * upstream's US default; nothing else is touched.
 *
 * The test database is shared between test files: every key this file changes is put back, and the
 * cached settings with it.
 *
 * @internal
 */
final class DayMonthYearMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const KEYS = ['language_code', 'dateformat'];

    /** @var array<string, string|null> */
    private array $before = [];

    protected function setUp(): void
    {
        parent::setUp();

        Database::connect()->resetDataCache();

        foreach (self::KEYS as $key) {
            $row                = Database::connect()->table('app_config')->where('key', $key)->get()->getRowArray();
            $this->before[$key] = $row === null ? null : (string) $row['value'];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->before as $key => $value) {
            $builder = Database::connect()->table('app_config');

            if ($value === null) {
                $builder->where('key', $key)->delete();
            } else {
                $builder->replace(['key' => $key, 'value' => $value]);
            }
        }

        config(OSPOS::class)->update_settings();

        parent::tearDown();
    }

    public function testASpanishBusinessOnTheUsDefaultMovesToDayMonthYear(): void
    {
        $this->set('language_code', 'es-MX');
        $this->set('dateformat', 'm/d/Y');

        $this->runMigration();

        $this->assertSame('d/m/Y', $this->value('dateformat'));
    }

    public function testTheTwoDigitYearVariantMovesToo(): void
    {
        $this->set('language_code', 'es-MX');
        $this->set('dateformat', 'm/d/y');

        $this->runMigration();

        $this->assertSame('d/m/y', $this->value('dateformat'));
    }

    public function testAFormatSomebodyChoseIsLeftAlone(): void
    {
        $this->set('language_code', 'es-MX');
        $this->set('dateformat', 'Y/m/d');

        $this->runMigration();

        $this->assertSame('Y/m/d', $this->value('dateformat'));
    }

    public function testABusinessInEnglishKeepsTheUsOrder(): void
    {
        $this->set('language_code', 'en');
        $this->set('dateformat', 'm/d/Y');

        $this->runMigration();

        $this->assertSame('m/d/Y', $this->value('dateformat'));
    }

    private function runMigration(): void
    {
        ob_start();

        try {
            (new Migration_DayMonthYearDateFormat())->up();
        } finally {
            ob_end_clean();
        }
    }

    private function set(string $key, string $value): void
    {
        Database::connect()->table('app_config')->replace(['key' => $key, 'value' => $value]);
    }

    private function value(string $key): ?string
    {
        $row = Database::connect()->table('app_config')->where('key', $key)->get()->getRowArray();

        return $row === null ? null : (string) $row['value'];
    }
}
