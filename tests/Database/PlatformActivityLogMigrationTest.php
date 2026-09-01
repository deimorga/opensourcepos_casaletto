<?php

declare(strict_types=1);

namespace Tests\Database;

use CodeIgniter\Test\CIUnitTestCase;
use Platform\Database\Migrations\CreatePlatformActivityLog;

/**
 * The console's record of what it changed -- level 1 of section 7 of the technical design, and the
 * table D6 defines the scope of: modifications are recorded, accesses are not.
 *
 * The column that justifies this file is `account_email`. It is denormalised on purpose: the very
 * first thing this log will be used for is deleting the orphan account, and if the actor were only
 * an id, the row saying who deleted it would read "account #2 deleted account #3" and would stop
 * being legible the day account #2 is itself retired. A foreign key would be worse than useless
 * here -- a log that a DELETE elsewhere can break is not a log.
 *
 * @internal
 */
final class PlatformActivityLogMigrationTest extends CIUnitTestCase
{
    private const TABLE = 'platform_activity_log';

    protected function setUp(): void
    {
        parent::setUp();

        // Version-prefixed filenames satisfy no PSR-4 rule; required by path, like the runner does.
        require_once APPPATH . 'Platform/Database/Migrations/20260902000003_CreatePlatformActivityLog.php';

        $this->dropTable();
        (new CreatePlatformActivityLog())->up();
        db_connect('platform')->resetDataCache();
    }

    protected function tearDown(): void
    {
        $this->dropTable();

        parent::tearDown();
    }

    private function dropTable(): void
    {
        db_connect('platform')->query('DROP TABLE IF EXISTS `' . self::TABLE . '`');
        db_connect('platform')->resetDataCache();
    }

    /**
     * @return array<string, object>
     */
    private function fields(): array
    {
        $fields = [];

        foreach (db_connect('platform')->getFieldData(self::TABLE) as $field) {
            $fields[$field->name] = $field;
        }

        return $fields;
    }

    public function testItHoldsTheColumnsSection71Describes(): void
    {
        $names = array_keys($this->fields());

        sort($names);

        $this->assertSame(
            ['account_email', 'account_id', 'action', 'created_at', 'detail', 'id', 'ip_address', 'target_id', 'target_type'],
            $names,
        );
    }

    /**
     * The assertion this file exists for: the actor's email is a column of its own, so the record
     * of who deleted the orphan account survives the deletion of everything it refers to.
     */
    public function testTheActorEmailIsStoredOnTheRowAndSurvivesTheAccount(): void
    {
        $email = $this->fields()['account_email'];

        $this->assertSame('varchar', $email->type);
        $this->assertSame(255, (int) $email->max_length);

        db_connect('platform')->table(self::TABLE)->insert([
            'account_id'    => 4242,
            'account_email' => 'huerfana@ospos-saas.micronuba.net',
            'action'        => 'account.deleted',
            'target_type'   => 'account',
            'target_id'     => '4242',
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        // No foreign key exists, so a row can name an account id that is already gone. If a
        // constraint were ever added, this insert would fail and this test would say so.
        $row = db_connect('platform')->table(self::TABLE)->where('action', 'account.deleted')->get()->getRow();

        $this->assertSame('huerfana@ospos-saas.micronuba.net', $row->account_email);
    }

    /**
     * Everything but `action` is nullable: something may be recorded from the command line, where
     * there is no session and no IP address at all.
     */
    public function testOnlyTheActionIsRequired(): void
    {
        $fields = $this->fields();

        $this->assertFalse($fields['action']->nullable);

        foreach (['account_id', 'account_email', 'target_type', 'target_id', 'detail', 'ip_address'] as $optional) {
            $this->assertTrue($fields[$optional]->nullable, "{$optional} must be optional.");
        }
    }

    /**
     * `target_id` is text and not an integer because the targets are of different kinds: a business
     * is named by its slug, an account by its numeric id.
     */
    public function testTheTargetIsIdentifiedByTextSoASlugFitsAsWellAsAnId(): void
    {
        $this->assertSame('varchar', $this->fields()['target_id']->type);

        db_connect('platform')->table(self::TABLE)->insert([
            'action'      => 'tenant.suspended',
            'target_type' => 'tenant',
            'target_id'   => 'paraisodelacanasta',
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame(
            1,
            db_connect('platform')->table(self::TABLE)->where('target_id', 'paraisodelacanasta')->countAllResults(),
        );
    }

    /**
     * `detail` is JSON in a TEXT column: the shape of what is worth keeping differs per action and
     * keeps changing, and a column per field would be a migration per action.
     */
    public function testTheDetailHoldsJsonAsText(): void
    {
        $this->assertSame('text', $this->fields()['detail']->type);

        $detail = json_encode(['drop_schema' => true, 'db_name' => 'tenant_paraiso'], JSON_UNESCAPED_UNICODE);

        db_connect('platform')->table(self::TABLE)->insert([
            'action'     => 'tenant.schema_dropped',
            'detail'     => $detail,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $row = db_connect('platform')->table(self::TABLE)->where('action', 'tenant.schema_dropped')->get()->getRow();

        $this->assertSame(['drop_schema' => true, 'db_name' => 'tenant_paraiso'], json_decode($row->detail, true));
    }

    /**
     * `created_at` because the screen is a reverse-chronological list and that is its ORDER BY.
     * `(target_type, target_id)` in that order because the second question this log gets asked is
     * "everything that ever happened to this business".
     */
    public function testBothIndexesExistAndCoverTheColumnsInTheRightOrder(): void
    {
        $byName = [];

        foreach (db_connect('platform')->getIndexData(self::TABLE) as $index) {
            $byName[$index->name] = $index;
        }

        $this->assertArrayHasKey('platform_activity_created_at', $byName);
        $this->assertSame(['created_at'], $byName['platform_activity_created_at']->fields);

        $this->assertArrayHasKey('platform_activity_target', $byName);
        $this->assertSame(
            ['target_type', 'target_id'],
            $byName['platform_activity_target']->fields,
            'The order matters: this index also has to serve a query on target_type alone.',
        );
    }

    public function testItIsReversible(): void
    {
        (new CreatePlatformActivityLog())->down();
        db_connect('platform')->resetDataCache();

        $this->assertFalse(db_connect('platform')->tableExists(self::TABLE));
    }
}
