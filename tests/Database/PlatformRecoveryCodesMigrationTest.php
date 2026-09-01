<?php

declare(strict_types=1);

namespace Tests\Database;

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use Platform\Database\Migrations\CreatePlatformAccountRecoveryCodes;

/**
 * The recovery codes table: the only way back into the platform when the phone holding the second
 * factor is gone, since the platform owns no channel to send anything through (section 9.12 of the
 * technical design).
 *
 * It is a TABLE and not a JSON column on the account for one reason, and it is the reason these
 * tests check the shape so closely: single use has to be atomic. A row can be spent with
 * `UPDATE ... SET used_at = NOW() WHERE id = ? AND used_at IS NULL` and affectedRows() === 1 is
 * the proof that this request is the one that spent it. Read-modify-write over a JSON array cannot
 * say that -- two requests both read a code as unused and both let it through. That is a rare
 * authentication bypass, which is worse than a common one because nothing will reproduce it.
 *
 * The behaviour that atomicity buys is tested in tests/Models/PlatformAccountTest.php. This file
 * only proves the storage can support it.
 *
 * @internal
 */
final class PlatformRecoveryCodesMigrationTest extends CIUnitTestCase
{
    private const TABLE = 'platform_account_recovery_codes';

    protected function setUp(): void
    {
        parent::setUp();

        // Version-prefixed filenames satisfy no PSR-4 rule; the runner requires them by path and
        // so does this. The runner itself is avoided on purpose -- see
        // PlatformAccountColumnsMigrationTest.
        require_once APPPATH . 'Platform/Database/Migrations/20260902000002_CreatePlatformAccountRecoveryCodes.php';

        $this->dropTable();
        (new CreatePlatformAccountRecoveryCodes())->up();
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

    public function testTheTableIsCreatedOnThePlatformGroup(): void
    {
        $this->assertTrue(db_connect('platform')->tableExists(self::TABLE));
    }

    public function testItHoldsExactlyTheColumnsTheSingleUseUpdateNeeds(): void
    {
        $fields = array_column(db_connect('platform')->getFieldData(self::TABLE), 'name');

        sort($fields);

        $this->assertSame(['account_id', 'code_hash', 'created_at', 'id', 'used_at'], $fields);
    }

    /**
     * CHAR(64) is exactly the hex form of SHA-256, so the column cannot quietly hold something
     * else -- a bcrypt hash, or a code stored in the clear.
     *
     * SHA-256 and not bcrypt because these are not passwords: they are generated here from
     * random_bytes() with full entropy, so there is nothing to guess and the only property needed
     * is that the hash be one-way. A slow hash would also turn the single-use UPDATE below into a
     * scan over every code the account owns, each costing a deliberate 100 ms.
     */
    public function testTheHashColumnIsExactlyTheWidthOfASha256Hex(): void
    {
        $hash = null;

        foreach (db_connect('platform')->getFieldData(self::TABLE) as $field) {
            if ($field->name === 'code_hash') {
                $hash = $field;
            }
        }

        $this->assertNotNull($hash);
        $this->assertSame('char', $hash->type);
        $this->assertSame(64, (int) $hash->max_length);
        $this->assertFalse($hash->nullable);
    }

    /**
     * `used_at` must be nullable, because NULL IS the "unspent" state the atomic UPDATE filters on.
     */
    public function testUsedAtIsNullableBecauseNullIsWhatUnspentMeans(): void
    {
        foreach (db_connect('platform')->getFieldData(self::TABLE) as $field) {
            if ($field->name === 'used_at') {
                $this->assertTrue($field->nullable);

                return;
            }
        }

        $this->fail('used_at is missing.');
    }

    public function testTheHashIsUniqueAndTheAccountIsIndexed(): void
    {
        $indexes = db_connect('platform')->getIndexData(self::TABLE);
        $byName  = [];

        foreach ($indexes as $index) {
            $byName[$index->name] = $index;
        }

        $this->assertArrayHasKey('platform_recovery_codes_hash', $byName);
        $this->assertSame('UNIQUE', $byName['platform_recovery_codes_hash']->type);
        $this->assertSame(['code_hash'], $byName['platform_recovery_codes_hash']->fields);

        $this->assertArrayHasKey('platform_recovery_codes_account', $byName);
        $this->assertSame(['account_id'], $byName['platform_recovery_codes_account']->fields);
    }

    /**
     * The unique index is not decoration: issuing the same code twice would mean spending one
     * spends the other, or does not, depending on which row the UPDATE happened to reach.
     */
    public function testTheSameHashCannotBeStoredTwice(): void
    {
        $hash = hash('sha256', 'un-codigo-cualquiera');

        db_connect('platform')->table(self::TABLE)->insert(['account_id' => 1, 'code_hash' => $hash]);

        $this->expectException(DatabaseException::class);

        db_connect('platform')->table(self::TABLE)->insert(['account_id' => 2, 'code_hash' => $hash]);
    }

    public function testItIsReversible(): void
    {
        (new CreatePlatformAccountRecoveryCodes())->down();
        db_connect('platform')->resetDataCache();

        $this->assertFalse(db_connect('platform')->tableExists(self::TABLE));
    }
}
