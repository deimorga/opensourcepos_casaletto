<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\Migration_AddPresalesWeightRefundLimit;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\OSPOS;

/**
 * presales_weight_refund_limit (owner's decision of 2026-10-07): seeded at 15 %, never overwritten.
 *
 * SHARED DATABASE: the one key this file writes is put back as it was.
 *
 * @internal
 */
final class PresalesWeightRefundLimitMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    private const KEY = 'presales_weight_refund_limit';

    protected $migrate      = true;
    protected $migrateOnce  = true;
    protected $refresh      = false;
    protected $namespace    = 'App';
    private ?string $before = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Composer excludes app/Database/Migrations from the classmap: required by hand, as in
        // BarcodeWeightDivisorConfigTest.
        require_once APPPATH . 'Database/Migrations/20261008030000_AddPresalesWeightRefundLimit.php';

        $this->before = $this->setting();
    }

    protected function tearDown(): void
    {
        $builder = Database::connect()->table('app_config');

        if ($this->before === null) {
            $builder->where('key', self::KEY)->delete();
        } else {
            $builder->replace(['key' => self::KEY, 'value' => $this->before]);
        }

        config(OSPOS::class)->update_settings();

        parent::tearDown();
    }

    public function testTheLimitShipsAtFifteenPercent(): void
    {
        Database::connect()->table('app_config')->where('key', self::KEY)->delete();

        $this->runQuietly(new Migration_AddPresalesWeightRefundLimit());

        $this->assertSame('15', $this->setting());
    }

    public function testRunningTheMigrationAgainDoesNotOverwriteAConfiguredLimit(): void
    {
        Database::connect()->table('app_config')->replace(['key' => self::KEY, 'value' => '0']);

        $this->runQuietly(new Migration_AddPresalesWeightRefundLimit());

        $this->assertSame('0', $this->setting());
    }

    private function runQuietly(object $migration): void
    {
        ob_start();

        try {
            $migration->up();
        } finally {
            ob_end_clean();
        }
    }

    private function setting(): ?string
    {
        $row = Database::connect()->table('app_config')->where('key', self::KEY)->get()->getRowArray();

        return $row === null ? null : (string) $row['value'];
    }
}
