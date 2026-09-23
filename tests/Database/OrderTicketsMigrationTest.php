<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\Migration_AddOrderTickets;
use App\Database\Migrations\Migration_AddOrderTicketsConfigKeys;
use App\Database\Migrations\Migration_AddOrderTicketsModule;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * What the three order-ticket migrations are allowed to do to a database that is already selling.
 *
 * The dominant constraint on this whole piece of work is that a shop using this code every day must
 * not notice anything until somebody turns the switch on AND grants the permission. These tests are
 * that constraint written down: both switches off, no grant, and nothing touched that the register
 * already depends on.
 */
class OrderTicketsMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const SWITCHES = ['order_tickets_enable', 'order_tickets_kitchen_enable'];

    /**
     * The test database is shared between test FILES, not just between methods in this one. A test
     * that leaves app_config changed breaks ConfigTest, ConfigScaleTest and WiredSettingsViewTest
     * from another file, and the failure surfaces where the cause is not.
     *
     * @var array<string, string|null>
     */
    private array $switchesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The driver answers tableExists() from a schema list built when the process started.
        Database::connect()->resetDataCache();

        foreach (self::SWITCHES as $key) {
            $row = Database::connect()->table('app_config')->where('key', $key)->get()->getRowArray();

            $this->switchesBefore[$key] = $row === null ? null : (string) $row['value'];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->switchesBefore as $key => $value) {
            $builder = Database::connect()->table('app_config');

            if ($value === null) {
                $builder->where('key', $key)->delete();
            } else {
                $builder->replace(['key' => $key, 'value' => $value]);
            }
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    // The schema
    // ---------------------------------------------------------------------------------------------

    public function testTheThreeTablesExist(): void
    {
        $db = Database::connect();

        $this->assertTrue($db->tableExists('order_tickets'));
        $this->assertTrue($db->tableExists('order_ticket_lines'));
        $this->assertTrue($db->tableExists('order_ticket_rounds'));
    }

    /**
     * The migration's own list is what the next reader will compare against when adding a column,
     * and each model's $allowedFields is checked against it -- CodeIgniter drops a field missing
     * from $allowedFields without raising anything, and this project has already lost data to that
     * twice.
     *
     * Deliberately NOT a @dataProvider: PHPUnit resolves providers before setUp, and the migration
     * classes are only loaded once MigrationRunner has required them by path -- their filenames
     * carry a version prefix and satisfy no PSR-4 rule. A provider referencing these constants
     * would fatal before the first test ran.
     */
    public function testTheWritableColumnsMatchEveryTable(): void
    {
        $this->assertWritableColumns(
            'order_tickets',
            'order_ticket_id',
            Migration_AddOrderTickets::WRITABLE_COLUMNS_TICKETS
        );
        $this->assertWritableColumns(
            'order_ticket_lines',
            'order_ticket_line_id',
            Migration_AddOrderTickets::WRITABLE_COLUMNS_LINES
        );
        $this->assertWritableColumns(
            'order_ticket_rounds',
            'round_id',
            Migration_AddOrderTickets::WRITABLE_COLUMNS_ROUNDS
        );
    }

    /**
     * @param list<string> $expected
     */
    private function assertWritableColumns(string $table, string $primaryKey, array $expected): void
    {
        $columns  = Database::connect()->getFieldNames($table);
        $writable = array_values(array_diff($columns, [$primaryKey]));

        sort($writable);
        sort($expected);

        $this->assertSame($expected, $writable, "WRITABLE_COLUMNS and $table disagree.");
    }

    /**
     * Two rounds numbered the same inside one ticket would print "RONDA 2" twice with different
     * dishes on each sheet. The database is the right place to refuse that.
     */
    public function testTheRoundNumberIsUniqueWithinItsTicket(): void
    {
        $indexes = Database::connect()->getIndexData('order_ticket_rounds');
        $index   = null;

        foreach ($indexes as $candidate) {
            if ($candidate->name === 'idx_ticket_number') {
                $index = $candidate;
            }
        }

        $this->assertNotNull($index, 'idx_ticket_number is missing.');
        $this->assertSame(['order_ticket_id', 'number'], $index->fields);
        $this->assertSame('UNIQUE', $index->type, 'A duplicate round number has to fail at the database, not at review time.');
    }

    /**
     * NULL is the whole mechanism behind D8 -- "the second ticket prints only what was added" is the
     * query `round_id IS NULL`. A default would make every line look already sent.
     */
    public function testAnUnsentLineIsRecognisableBecauseRoundIdAcceptsNull(): void
    {
        $field = $this->fieldOf('order_ticket_lines', 'round_id');

        $this->assertTrue($field->nullable);
        $this->assertNull($field->default);
    }

    /**
     * A kitchen instruction does not fit in sales_items.description, which is varchar(30) and
     * already carries "Unidad: kilogramo" on most lines. That is why this column exists at all, so
     * its width is the point of it.
     */
    public function testTheKitchenNoteHasRoomForARealInstruction(): void
    {
        $field = $this->fieldOf('order_ticket_lines', 'kitchen_note');

        $this->assertSame(255, (int) $field->max_length);
    }

    /**
     * The free name is the truth; dinner_tables.name is varchar(30) and only carries the tab label.
     */
    public function testTheTicketNameIsWiderThanTheTabLabelItIsTruncatedInto(): void
    {
        $ticketName = $this->fieldOf('order_tickets', 'name');
        $tableName  = $this->fieldOf('dinner_tables', 'name');

        $this->assertSame(64, (int) $ticketName->max_length);
        $this->assertGreaterThan(
            (int) $tableName->max_length,
            (int) $ticketName->max_length,
            'If the ticket name were no wider than the tab label there would be no reason to store it twice.'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // The switches
    // ---------------------------------------------------------------------------------------------

    public function testBothSwitchesShipOff(): void
    {
        foreach (self::SWITCHES as $key) {
            $this->assertSame('0', $this->switchValue($key), "$key must ship off: D3 makes the feature never mandatory.");
        }
    }

    /**
     * A business may have turned the switch on by hand. Re-running a migration -- which happens on
     * every deploy that someone migrates twice -- must not undo that.
     */
    public function testRunningTheConfigMigrationAgainDoesNotOverwriteAConfiguredValue(): void
    {
        Database::connect()->table('app_config')
            ->replace(['key' => 'order_tickets_enable', 'value' => '1']);

        $this->runMigrationQuietly(new Migration_AddOrderTicketsConfigKeys());

        $this->assertSame('1', $this->switchValue('order_tickets_enable'));
    }

    // ---------------------------------------------------------------------------------------------
    // The module and its permissions
    // ---------------------------------------------------------------------------------------------

    public function testTheModuleAndItsTwoPermissionsExist(): void
    {
        $db = Database::connect();

        $this->assertSame(1, $db->table('modules')->where('module_id', 'order_tickets')->countAllResults());
        $this->assertSame(1, $db->table('permissions')->where('permission_id', 'order_tickets')->countAllResults());
        $this->assertSame(1, $db->table('permissions')->where('permission_id', 'order_tickets_void')->countAllResults());
    }

    /**
     * The one that matters. 20260823030000_AddAnalyticsReportPermission auto-granted itself to
     * everyone holding reports_sales; doing that here would put a module the business never asked
     * for into its menu the next time it deploys.
     */
    public function testNobodyWasGrantedAnythingByTheMigration(): void
    {
        $granted = Database::connect()->table('grants')
            ->like('permission_id', 'order_tickets', 'after')
            ->countAllResults();

        $this->assertSame(0, $granted, 'Order tickets must be granted by hand, never by a migration.');
    }

    /**
     * THE SECURITY TEST OF THIS MIGRATION.
     *
     * Employee::has_module_grant() resolves a module with LIKE '<id>%' and, when the match count is
     * not exactly 1, returns `count != 0`. With two or more subpermissions under one prefix, an
     * employee holding only those and NOT the base permission passes the module check anyway.
     *
     * So the number of subpermissions under `order_tickets` is not a style choice -- it is the thing
     * keeping that hole shut. The kitchen screen of delivery 3 must get its own module id, and this
     * test is what fails if somebody adds it here instead.
     */
    public function testThereIsExactlyOneSubpermissionUnderTheModulePrefix(): void
    {
        $permissions = array_column(
            Database::connect()->table('permissions')
                ->select('permission_id')
                ->like('permission_id', 'order_tickets', 'after')
                ->get()->getResultArray(),
            'permission_id'
        );

        $subpermissions = array_values(array_diff($permissions, ['order_tickets']));

        $this->assertSame(
            ['order_tickets_void'],
            $subpermissions,
            'A second subpermission under this prefix reopens the has_module_grant() hole. Give the new screen its own module id.'
        );
    }

    /**
     * The module id is not a prefix of any other, and no other is a prefix of it.
     */
    public function testTheModuleIdCannotBeConfusedWithAnyOther(): void
    {
        $ids = array_column(
            Database::connect()->table('modules')->select('module_id')->get()->getResultArray(),
            'module_id'
        );

        foreach ($ids as $id) {
            if ($id === 'order_tickets') {
                continue;
            }

            $this->assertFalse(str_starts_with('order_tickets', $id), "A grant on \"$id\" would also open order_tickets.");
            $this->assertFalse(str_starts_with($id, 'order_tickets'), "A grant on order_tickets would also open \"$id\".");
        }
    }

    /**
     * Sort 0 hides a module from every menu (Module::get_allowed_home_modules filters `sort != 0`),
     * so a module that ships at 0 is invisible no matter who holds the grant.
     */
    public function testTheModuleIsPlacedNextToSalesAndIsNotHidden(): void
    {
        $row = Database::connect()->table('modules')
            ->where('module_id', 'order_tickets')
            ->get()->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame(75, (int) $row['sort']);
    }

    // ---------------------------------------------------------------------------------------------
    // Idempotence
    // ---------------------------------------------------------------------------------------------

    /**
     * Deploys in this project do not run migrations; somebody runs them by hand over SSH, and
     * running them twice is the most ordinary mistake available.
     */
    public function testRunningEveryMigrationAgainChangesNothing(): void
    {
        $db = Database::connect();

        $before = [
            'modules'     => $db->table('modules')->countAllResults(),
            'permissions' => $db->table('permissions')->countAllResults(),
            'grants'      => $db->table('grants')->countAllResults(),
            'app_config'  => $db->table('app_config')->countAllResults(),
        ];

        $this->runMigrationQuietly(new Migration_AddOrderTickets());
        $this->runMigrationQuietly(new Migration_AddOrderTicketsConfigKeys());
        $this->runMigrationQuietly(new Migration_AddOrderTicketsModule());

        foreach ($before as $table => $count) {
            $this->assertSame($count, $db->table($table)->countAllResults(), "Re-running the migrations changed $table.");
        }

        $this->assertTrue($db->tableExists('order_tickets'));
        $this->assertTrue($db->tableExists('order_ticket_lines'));
        $this->assertTrue($db->tableExists('order_ticket_rounds'));
    }

    // ---------------------------------------------------------------------------------------------

    /**
     * The migrations report progress with CLI::write, which is right for an operator watching a
     * deploy and wrong inside PHPUnit: beStrictAboutOutputDuringTests and failOnRisky are both on,
     * so an unbuffered run here turns a passing test red for the wrong reason.
     */
    private function runMigrationQuietly(object $migration): void
    {
        ob_start();

        try {
            $migration->up();
        } finally {
            ob_end_clean();
        }
    }

    private function switchValue(string $key): ?string
    {
        $row = Database::connect()->table('app_config')->where('key', $key)->get()->getRowArray();

        return $row === null ? null : (string) $row['value'];
    }

    private function fieldOf(string $table, string $column): object
    {
        foreach (Database::connect()->getFieldData($table) as $candidate) {
            if ($candidate->name === $column) {
                return $candidate;
            }
        }

        $this->fail("$table.$column does not exist.");
    }
}
