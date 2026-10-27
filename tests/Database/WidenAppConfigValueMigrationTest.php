<?php

declare(strict_types=1);

namespace Tests\Database;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\OSPOS;

/**
 * The presale conditions were cut at character 500 on every receipt (2026-10-27): app_config.value was
 * VARCHAR(500) and the connection is not strict, so MariaDB truncated silently. See
 * 20261027000000_WidenAppConfigValue.
 *
 * SHARED DATABASE: presales_terms is put back as it was.
 *
 * @internal
 */
final class WidenAppConfigValueMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate      = true;
    protected $migrateOnce  = true;
    protected $refresh      = false;
    protected $namespace    = 'App';
    private ?string $before = null;

    protected function setUp(): void
    {
        parent::setUp();

        $row          = $this->db->table('app_config')->where('key', 'presales_terms')->get()->getRow();
        $this->before = $row === null ? null : (string) $row->value;
    }

    protected function tearDown(): void
    {
        if ($this->before === null) {
            $this->db->table('app_config')->where('key', 'presales_terms')->delete();
        } else {
            $this->db->table('app_config')->replace(['key' => 'presales_terms', 'value' => $this->before]);
        }

        config(OSPOS::class)->update_settings();

        parent::tearDown();
    }

    public function testTheSuggestedConditionsAreStoredWhole(): void
    {
        $terms = lang('Presales.terms_template');

        $this->assertGreaterThan(500, mb_strlen($terms), 'The suggested wording is longer than the old column.');

        $this->db->table('app_config')->replace(['key' => 'presales_terms', 'value' => $terms]);

        $this->assertSame($terms, (string) $this->db->table('app_config')->where('key', 'presales_terms')->get()->getRow()->value);
    }

    public function testFourThousandCharactersFit(): void
    {
        $terms = str_repeat('Condición ñ ', 333) . 'fin.';

        $this->db->table('app_config')->replace(['key' => 'presales_terms', 'value' => $terms]);

        $this->assertSame($terms, (string) $this->db->table('app_config')->where('key', 'presales_terms')->get()->getRow()->value);
    }
}
