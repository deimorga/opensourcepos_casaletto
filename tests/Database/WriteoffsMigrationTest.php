<?php

declare(strict_types=1);

namespace Tests\Database;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * What the two write-off migrations are allowed to do to a database that is already in production.
 *
 * The dominant constraint on this whole piece of work is that the shop selling every day with this
 * code must not notice anything until somebody grants the permission. These tests are that
 * constraint written down: additive column, no backfill, no grant.
 */
class WriteoffsMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $migrateOnce = true;
    protected $refresh = false;
    protected $namespace = 'App';

    public function testTheReasonIsAnAdditiveNullableColumnOnTheExistingAuditTrail(): void
    {
        $db = Database::connect();
        $db->resetDataCache();

        $this->assertTrue($db->fieldExists('reason_code', 'inventory'));

        $field = null;

        foreach ($db->getFieldData('inventory') as $candidate) {
            if ($candidate->name === 'reason_code') {
                $field = $candidate;
            }
        }

        $this->assertNotNull($field);
        $this->assertTrue($field->nullable, 'A movement that is not a write-off has no reason, so the column has to accept NULL.');
        $this->assertNull($field->default, 'Defaulting the reason would invent a classification for every movement ever recorded.');
    }

    /**
     * Every read of this column is "the write-offs between these two dates", and inventory is the
     * one table that grows with every single sale line.
     */
    public function testTheReasonIsIndexedTogetherWithTheDate(): void
    {
        $names = array_column(Database::connect()->getIndexData('inventory'), 'fields', 'name');

        $this->assertArrayHasKey('idx_inventory_reason_code', $names);
        $this->assertSame(['reason_code', 'trans_date'], $names['idx_inventory_reason_code']);
    }

    public function testNoHistoricalMovementWasGivenAReason(): void
    {
        $classified = Database::connect()->table('inventory')
            ->where('reason_code IS NOT NULL')
            ->countAllResults();

        $this->assertSame(0, $classified, 'The migration must not backfill: movements recorded before the classification existed are not incomplete data.');
    }

    public function testTheModuleAndItsPermissionExist(): void
    {
        $db = Database::connect();

        $this->assertSame(1, $db->table('modules')->where('module_id', 'writeoffs')->countAllResults());
        $this->assertSame(1, $db->table('permissions')->where('permission_id', 'writeoffs')->countAllResults());
    }

    /**
     * The one that matters. 20260823030000_AddAnalyticsReportPermission auto-granted itself to
     * everyone holding reports_sales; doing that here would put a module the business never asked
     * for into its menu the next time it deploys.
     */
    public function testNobodyWasGrantedTheModuleByTheMigration(): void
    {
        $granted = Database::connect()->table('grants')
            ->where('permission_id', 'writeoffs')
            ->countAllResults();

        $this->assertSame(0, $granted, 'The write-off module must be granted by hand, never by a migration.');
    }

    /**
     * The module id is not a prefix of any other, and no other is a prefix of it. Employee::
     * has_module_grant() matches permissions with LIKE '<id>%', so a colliding prefix would let a
     * grant on one module open another.
     */
    public function testTheModuleIdCannotBeConfusedWithAnyOther(): void
    {
        $ids = array_column(
            Database::connect()->table('modules')->select('module_id')->get()->getResultArray(),
            'module_id'
        );

        foreach ($ids as $id) {
            if ($id === 'writeoffs') {
                continue;
            }

            $this->assertFalse(str_starts_with('writeoffs', $id), "A grant on \"$id\" would also open writeoffs.");
            $this->assertFalse(str_starts_with($id, 'writeoffs'), "A grant on writeoffs would also open \"$id\".");
        }
    }
}
