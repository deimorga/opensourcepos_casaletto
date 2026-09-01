<?php

declare(strict_types=1);

namespace Tests\Database;

use CodeIgniter\Test\CIUnitTestCase;
use Platform\Database\Migrations\AddAccountLifecycleToPlatformAccounts;
use Platform\Database\Migrations\AddTotpToPlatformAccounts;
use Platform\Database\Migrations\CreatePlatformAccounts;

/**
 * The two additive migrations over `platform_accounts`: what the console needs to name the orphan
 * account (section 6.1 of the functional scope), the brake of D8, and the second factor of D11.
 *
 * They are two migrations and not one so the brake can ship even if the second factor slips, and
 * so each down() reverses one concern. This file runs them in order, which is also the only way to
 * find out that the second one's `after` clause names a column the first one adds.
 *
 * ONE ASSERTION HERE IS WORTH THE WHOLE FILE: totp_secret is VARCHAR(512).
 *
 * The secret goes in encrypted with service('encrypter')->encrypt() and no base64_encode() around
 * it. That exact combination already broke this project once at 255 characters: the encrypter
 * already returns printable text, the extra encode doubled it, MySQL truncated it in silence, and
 * decryption failed much later with "authentication failed". See app/Libraries/TenantProvisioner.php.
 *
 * @internal
 */
final class PlatformAccountColumnsMigrationTest extends CIUnitTestCase
{
    /**
     * Every column the two migrations add, in the order they are added.
     */
    private const ADDED = [
        'last_login_at',
        'created_by_account_id',
        'failed_login_count',
        'failed_login_first_at',
        'totp_secret',
        'totp_enabled_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Migration files carry their version prefix, so they satisfy no PSR-4 rule and no
        // autoloader finds them: the runner requires them by path and so does this. The runner
        // itself is deliberately not used -- it would apply every other Platform migration to the
        // shared test schema and collide with the `tenants` table other tests build by hand (see
        // phpunit.xml.dist for why the platform group points at the test schema).
        require_once APPPATH . 'Platform/Database/Migrations/20260730000001_CreatePlatformAccounts.php';
        require_once APPPATH . 'Platform/Database/Migrations/20260902000000_AddAccountLifecycleToPlatformAccounts.php';
        require_once APPPATH . 'Platform/Database/Migrations/20260902000001_AddTotpToPlatformAccounts.php';

        $this->dropTable();

        (new CreatePlatformAccounts())->up();
        (new AddAccountLifecycleToPlatformAccounts())->up();
        (new AddTotpToPlatformAccounts())->up();

        db_connect('platform')->resetDataCache();
    }

    protected function tearDown(): void
    {
        $this->dropTable();

        parent::tearDown();
    }

    private function dropTable(): void
    {
        db_connect('platform')->query('DROP TABLE IF EXISTS `platform_accounts`');
        db_connect('platform')->resetDataCache();
    }

    /**
     * @return array<string, object>
     */
    private function fields(): array
    {
        $fields = [];

        foreach (db_connect('platform')->getFieldData('platform_accounts') as $field) {
            $fields[$field->name] = $field;
        }

        return $fields;
    }

    public function testEveryNewColumnExists(): void
    {
        $names = array_keys($this->fields());

        foreach (self::ADDED as $column) {
            $this->assertContains($column, $names);
        }
    }

    /**
     * NULL is not "we do not know who created this account". It is the signal that nobody did --
     * that it was created from a terminal with `php spark platform:create-account`, which is
     * exactly what betrays admin@ospos-saas.micronuba.net in the listing.
     */
    public function testTheCreatorIsNullableBecauseNullIsTheSignal(): void
    {
        $this->assertTrue($this->fields()['created_by_account_id']->nullable);
    }

    /**
     * "Never failed" and "failed zero times since the window opened" are the same state, so the
     * counter is never null and a fresh account starts at zero rather than at nothing.
     */
    public function testTheFailureCounterIsNotNullableAndStartsAtZero(): void
    {
        $counter = $this->fields()['failed_login_count'];

        $this->assertFalse($counter->nullable);
        $this->assertSame(0, (int) $counter->default);
        $this->assertSame('smallint', $counter->type);
    }

    /**
     * The assertion this file exists for. 255 would fit a base32 secret and would be the
     * truncation trap armed again: what is stored is the ciphertext, whose HMAC and IV alone run
     * to about 150 characters before the payload starts.
     */
    public function testTheTotpSecretHasRoomForTheCiphertextAndNotJustTheSecret(): void
    {
        $secret = $this->fields()['totp_secret'];

        $this->assertSame('varchar', $secret->type);
        $this->assertSame(512, (int) $secret->max_length, 'At 255 the encrypted secret is truncated in silence.');
        $this->assertTrue($secret->nullable, 'An account without a second factor holds no secret.');
    }

    /**
     * The factor is only demanded once a valid code has been typed back, so "enrolled" is a
     * timestamp and not a flag: the secret can exist while the factor is not yet active.
     */
    public function testTheSecondFactorIsSwitchedOnByADateAndNotByAFlag(): void
    {
        $enabled = $this->fields()['totp_enabled_at'];

        $this->assertSame('datetime', $enabled->type);
        $this->assertTrue($enabled->nullable);
    }

    /**
     * Additive means additive: an account row that existed before these migrations must still read
     * and write exactly as it did, or every environment breaks on deploy rather than on migrate.
     */
    public function testAnAccountCanStillBeInsertedWithOnlyTheOriginalColumns(): void
    {
        db_connect('platform')->table('platform_accounts')->insert([
            'email'             => 'previa@micronuba.net',
            'password_hash'     => password_hash('irrelevante', PASSWORD_DEFAULT),
            'is_platform_admin' => 1,
        ]);

        $row = db_connect('platform')->table('platform_accounts')->where('email', 'previa@micronuba.net')->get()->getRow();

        $this->assertNotNull($row);
        $this->assertNull($row->last_login_at);
        $this->assertNull($row->created_by_account_id);
        $this->assertSame(0, (int) $row->failed_login_count);
        $this->assertNull($row->totp_secret);
    }

    /**
     * A migration nobody dares undo is a migration nobody dares run. Reversed in the opposite
     * order to the one they were applied in, which is the order the runner uses.
     */
    public function testBothAreReversible(): void
    {
        (new AddTotpToPlatformAccounts())->down();
        (new AddAccountLifecycleToPlatformAccounts())->down();
        db_connect('platform')->resetDataCache();

        $names = array_column(db_connect('platform')->getFieldData('platform_accounts'), 'name');

        foreach (self::ADDED as $column) {
            $this->assertNotContains($column, $names);
        }

        $this->assertContains('email', $names, 'down() must give back the original table, not drop it.');
    }
}
