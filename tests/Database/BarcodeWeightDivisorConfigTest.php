<?php

namespace Tests\Database;

use App\Database\Migrations\Migration_AddBarcodeWeightDivisorConfigKey;
use App\Libraries\Token_lib;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Covers 20260905001000_AddBarcodeWeightDivisorConfigKey.
 *
 * The point of the key is that the number stops being a literal in Token_lib. The point of the
 * seeded value is that nothing moves: 1000 is exactly what the literal was.
 */
class BarcodeWeightDivisorConfigTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const KEY = 'barcode_weight_divisor';

    protected function setUp(): void
    {
        parent::setUp();

        // Composer excludes app/Database/Migrations from the classmap -- the file name carries the
        // timestamp and the class does not -- so the migration is required by hand.
        require_once APPPATH . 'Database/Migrations/20260905001000_AddBarcodeWeightDivisorConfigKey.php';

        db_connect()->resetDataCache();
        $this->forgetKey();
    }

    protected function tearDown(): void
    {
        $this->forgetKey();

        parent::tearDown();
    }

    private function forgetKey(): void
    {
        db_connect()->table('app_config')->where('key', self::KEY)->delete();
    }

    private function storedValue(): ?string
    {
        $row = db_connect()->table('app_config')->select('value')->where('key', self::KEY)->get()->getRow();

        return $row === null ? null : (string) $row->value;
    }

    private function setValue(string $value): void
    {
        db_connect()->table('app_config')->insert(['key' => self::KEY, 'value' => $value]);
    }

    /**
     * CodeIgniter's Migration takes its connection from a Forge handed to the constructor; there is
     * no setter. Passing the tests-group forge is what keeps this off the development database.
     */
    private function migration(): Migration_AddBarcodeWeightDivisorConfigKey
    {
        return new Migration_AddBarcodeWeightDivisorConfigKey(Database::forge('tests'));
    }

    public function testItSeedsTheDivisorAtTheNumberTheCodeUsedToHardcode(): void
    {
        $this->migration()->up();

        $this->assertSame(
            (string) Token_lib::BARCODE_WEIGHT_DIVISOR_DEFAULT,
            $this->storedValue(),
            'Seeding anything other than 1000 would move a number under a trading tenant.'
        );
    }

    public function testAValueSetByHandIsNotOverwritten(): void
    {
        $this->setValue('1');

        $this->migration()->up();

        $this->assertSame('1', $this->storedValue(), 'A divisor worked out from a real label outranks a default.');
    }

    public function testRunningItTwiceLeavesOneRow(): void
    {
        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame(
            1,
            db_connect()->table('app_config')->where('key', self::KEY)->countAllResults(),
            'The migration is idempotent.'
        );
    }

    public function testDownRemovesTheSeededDefault(): void
    {
        $this->migration()->up();

        $this->migration()->down();

        $this->assertNull($this->storedValue());
    }

    public function testDownKeepsAValueSomebodyConfigured(): void
    {
        $this->setValue('100');

        $this->migration()->down();

        $this->assertSame('100', $this->storedValue(), 'Rolling the code back is no reason to throw away a setting.');
    }
}
