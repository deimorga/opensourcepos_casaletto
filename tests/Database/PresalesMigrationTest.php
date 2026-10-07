<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\Migration_AddPresales;
use App\Database\Migrations\Migration_AddPresalesConfigKeys;
use App\Database\Migrations\Migration_AddPresalesModule;
use App\Models\Presale;
use App\Models\Presale_campaign;
use App\Models\Presale_event;
use App\Models\Presale_payment;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use ReflectionProperty;

/**
 * What the presales migrations are allowed to do to a database that is already selling.
 *
 * The module belongs to the platform (D2): every business gets it, and none notices anything until
 * its own administrator turns it on AND grants the permission. These tests are that promise written
 * down: switch off, no grant, nothing touched that the register already depends on.
 *
 * @internal
 */
final class PresalesMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    private const TABLES = [
        'presale_campaigns',
        'presale_campaign_items',
        'presale_campaign_dates',
        'presales',
        'presale_items',
        'presale_installments',
        'presale_payments',
        'presale_events',
    ];

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    /**
     * The test database is shared between test files. A test that leaves app_config changed breaks
     * another file, far from the cause, so every key this file can write is put back.
     *
     * @var array<string, string|null>
     */
    private array $settingsBefore = [];

    protected function setUp(): void
    {
        parent::setUp();

        Database::connect()->resetDataCache();

        foreach (array_keys(Migration_AddPresalesConfigKeys::DEFAULTS) as $key) {
            $row = Database::connect()->table('app_config')->where('key', $key)->get()->getRowArray();

            $this->settingsBefore[$key] = $row === null ? null : (string) $row['value'];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->settingsBefore as $key => $value) {
            $builder = Database::connect()->table('app_config');

            if ($value === null) {
                $builder->where('key', $key)->delete();
            } else {
                $builder->replace(['key' => $key, 'value' => $value]);
            }
        }

        parent::tearDown();
    }

    public function testTheEightTablesExist(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue(Database::connect()->tableExists($table), "{$table} is missing.");
        }
    }

    /**
     * CodeIgniter drops a field missing from $allowedFields without raising anything, and this project
     * has already lost data to that twice. Each model's list is checked against the table it writes.
     *
     * Not a @dataProvider: providers run before setUp, and the migration classes are only loadable
     * once MigrationRunner has required them by path.
     */
    public function testTheWritableColumnsMatchEveryTableAndModel(): void
    {
        $this->assertWritable('presale_campaigns', 'campaign_id', Migration_AddPresales::WRITABLE_COLUMNS_CAMPAIGNS);
        $this->assertWritable('presale_campaign_items', null, Migration_AddPresales::WRITABLE_COLUMNS_CAMPAIGN_ITEMS);
        $this->assertWritable('presale_campaign_dates', 'date_id', Migration_AddPresales::WRITABLE_COLUMNS_CAMPAIGN_DATES);
        $this->assertWritable('presales', 'presale_id', Migration_AddPresales::WRITABLE_COLUMNS_PRESALES);
        $this->assertWritable('presale_items', null, Migration_AddPresales::WRITABLE_COLUMNS_ITEMS);
        $this->assertWritable('presale_installments', 'installment_id', Migration_AddPresales::WRITABLE_COLUMNS_INSTALLMENTS);
        $this->assertWritable('presale_payments', 'payment_id', Migration_AddPresales::WRITABLE_COLUMNS_PAYMENTS);
        $this->assertWritable('presale_events', 'event_id', Migration_AddPresales::WRITABLE_COLUMNS_EVENTS);

        $this->assertSameColumns(Migration_AddPresales::WRITABLE_COLUMNS_CAMPAIGNS, $this->allowedFields(Presale_campaign::class));
        $this->assertSameColumns(Migration_AddPresales::WRITABLE_COLUMNS_PRESALES, $this->allowedFields(Presale::class));
        $this->assertSameColumns(Migration_AddPresales::WRITABLE_COLUMNS_PAYMENTS, $this->allowedFields(Presale_payment::class));
        $this->assertSameColumns(Migration_AddPresales::WRITABLE_COLUMNS_EVENTS, $this->allowedFields(Presale_event::class));
    }

    /**
     * NOT NULL is the whole point: an instalment with no shift is money nobody reconciles (T3).
     */
    public function testAPaymentCannotBeStoredWithoutAShift(): void
    {
        $this->assertFalse($this->fieldOf('presale_payments', 'cashup_id')->nullable);
    }

    /**
     * One presale is delivered once. Two tills completing the same presale must not produce two sales.
     */
    public function testTheDeliverySaleIsUnique(): void
    {
        $this->assertSame('UNIQUE', $this->indexOf('presales', 'idx_sale_id')->type);
        $this->assertTrue($this->fieldOf('presales', 'sale_id')->nullable, 'An open presale has no sale yet.');
    }

    /**
     * A delivery day appears once in a campaign's list.
     */
    public function testACampaignDateIsUniqueWithinItsCampaign(): void
    {
        $index = $this->indexOf('presale_campaign_dates', 'idx_campaign_date');

        $this->assertSame('UNIQUE', $index->type);
        $this->assertSame(['campaign_id', 'delivery_date'], $index->fields);
    }

    /**
     * NULL is what "inherit the campaign's discount" means (D19, T14). A default of 0 would silently
     * override every campaign discount with "no discount".
     */
    public function testAProductDiscountDefaultsToInheritingTheCampaigns(): void
    {
        $field = $this->fieldOf('presale_campaign_items', 'discount_percent');

        $this->assertTrue($field->nullable);
        $this->assertNull($field->default);
    }

    /**
     * Three decimals: a product sold by weight is agreed at an initial weight (D22).
     */
    public function testALineQuantityHoldsAWeight(): void
    {
        $column = Database::connect()
            ->query('SHOW COLUMNS FROM ' . Database::connect()->prefixTable('presale_items') . " LIKE 'quantity'")
            ->getRowArray();

        $this->assertSame('decimal(15,3)', strtolower((string) $column['Type']));
    }

    // ---------------------------------------------------------------------------------------------
    // The settings
    // ---------------------------------------------------------------------------------------------

    public function testTheModuleShipsOffWithAnEmptyTextAndTheDefaultPrefix(): void
    {
        $this->assertSame('0', $this->setting('presales_enable'));
        $this->assertSame('PV-', $this->setting('presales_prefix'));
        $this->assertSame('', $this->setting('presales_terms'), 'The suggested text lives in the language files, not in every business.');
    }

    public function testRunningTheConfigMigrationAgainDoesNotOverwriteAConfiguredValue(): void
    {
        Database::connect()->table('app_config')->replace(['key' => 'presales_enable', 'value' => '1']);
        Database::connect()->table('app_config')->replace(['key' => 'presales_terms', 'value' => 'Condiciones de José']);

        $this->runQuietly(new Migration_AddPresalesConfigKeys());

        $this->assertSame('1', $this->setting('presales_enable'));
        $this->assertSame('Condiciones de José', $this->setting('presales_terms'));
    }

    // ---------------------------------------------------------------------------------------------
    // The module and its permissions
    // ---------------------------------------------------------------------------------------------

    public function testTheModuleAndItsTwoPermissionsExist(): void
    {
        $db = Database::connect();

        $this->assertSame(1, $db->table('modules')->where('module_id', 'presales')->countAllResults());
        $this->assertSame(1, $db->table('permissions')->where('permission_id', 'presales')->countAllResults());
        $this->assertSame(1, $db->table('permissions')->where('permission_id', 'presales_manage')->countAllResults());
    }

    public function testNobodyWasGrantedAnythingByTheMigration(): void
    {
        $granted = Database::connect()->table('grants')
            ->like('permission_id', 'presales', 'after')
            ->countAllResults();

        $this->assertSame(0, $granted, 'Presales must be granted by hand, never by a migration.');
    }

    /**
     * THE SECURITY TEST. Employee::has_module_grant() resolves a module with a prefix match and, when
     * the match count is not exactly 1, returns `count != 0`: with two subpermissions under one
     * prefix, an employee without the base permission passes. See AddOrderTicketsModule.
     */
    public function testThereIsExactlyOneSubpermissionUnderTheModulePrefix(): void
    {
        $permissions = array_column(
            Database::connect()->table('permissions')->select('permission_id')
                ->like('permission_id', 'presales', 'after')
                ->get()->getResultArray(),
            'permission_id',
        );

        $this->assertSame(['presales_manage'], array_values(array_diff($permissions, ['presales'])));
    }

    public function testTheModuleIdCannotBeConfusedWithAnyOther(): void
    {
        $ids = array_column(
            Database::connect()->table('modules')->select('module_id')->get()->getResultArray(),
            'module_id',
        );

        foreach ($ids as $id) {
            if ($id === 'presales') {
                continue;
            }

            $this->assertStringStartsNotWith($id, 'presales', "A grant on \"{$id}\" would also open presales.");
            $this->assertStringStartsNotWith('presales', $id, "A grant on presales would also open \"{$id}\".");
        }
    }

    /**
     * Sort 0 hides a module from every menu.
     */
    public function testTheModuleSitsBetweenSalesAndOrderTickets(): void
    {
        $row = Database::connect()->table('modules')->where('module_id', 'presales')->get()->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame(72, (int) $row['sort']);
    }

    /**
     * The menu label and the subpermission label exist in the language the application runs in.
     */
    public function testTheLabelsResolveInSpanish(): void
    {
        $previous = service('language')->getLocale();
        service('language')->setLocale('es-MX');

        try {
            $this->assertSame('Preventas', lang('Module.presales'));
            $this->assertNotSame('Presales.manage', lang('Presales.manage'));
        } finally {
            service('language')->setLocale($previous);
        }
    }

    public function testTheThreeMaintainedLocalesCarryTheSameKeys(): void
    {
        $en = array_keys(require APPPATH . 'Language/en/Presales.php');
        $mx = array_keys(require APPPATH . 'Language/es-MX/Presales.php');
        $es = array_keys(require APPPATH . 'Language/es-ES/Presales.php');

        sort($en);
        sort($mx);
        sort($es);

        $this->assertSame($en, $mx);
        $this->assertSame($en, $es);
    }

    // ---------------------------------------------------------------------------------------------
    // Idempotence
    // ---------------------------------------------------------------------------------------------

    public function testRunningEveryMigrationAgainChangesNothing(): void
    {
        $db = Database::connect();

        $before = [
            'modules'     => $db->table('modules')->countAllResults(),
            'permissions' => $db->table('permissions')->countAllResults(),
            'grants'      => $db->table('grants')->countAllResults(),
            'app_config'  => $db->table('app_config')->countAllResults(),
        ];

        $this->runQuietly(new Migration_AddPresales());
        $this->runQuietly(new Migration_AddPresalesConfigKeys());
        $this->runQuietly(new Migration_AddPresalesModule());

        foreach ($before as $table => $count) {
            $this->assertSame($count, $db->table($table)->countAllResults(), "Re-running the migrations changed {$table}.");
        }
    }

    /**
     * @param list<string> $expected
     */
    private function assertWritable(string $table, ?string $primaryKey, array $expected): void
    {
        $columns = Database::connect()->getFieldNames($table);

        $this->assertSameColumns($expected, array_values(array_diff($columns, array_filter([$primaryKey]))), "WRITABLE_COLUMNS and {$table} disagree.");
    }

    /**
     * @param list<string> $expected
     * @param list<string> $actual
     */
    private function assertSameColumns(array $expected, array $actual, string $message = ''): void
    {
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual, $message);
    }

    /**
     * @return list<string>
     */
    private function allowedFields(string $model): array
    {
        $property = new ReflectionProperty($model, 'allowedFields');

        return $property->getValue(model($model));
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

    private function setting(string $key): ?string
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

        $this->fail("{$table}.{$column} does not exist.");
    }

    private function indexOf(string $table, string $name): object
    {
        foreach (Database::connect()->getIndexData($table) as $candidate) {
            if ($candidate->name === $name) {
                return $candidate;
            }
        }

        $this->fail("{$table} has no index {$name}.");
    }
}
