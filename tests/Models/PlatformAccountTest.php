<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\PlatformAccount;
use App\Models\PlatformLoginOutcome;
use CodeIgniter\Session\Handlers\ArrayHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockSession;
use Config\Services;
use RuntimeException;

/**
 * The superadministrator identity itself: who can get in, who is shut out, and who may be removed.
 *
 * Every safeguard here is on the server side and inside a transaction, because the alternative --
 * asking the question and then acting on the answer in a second statement -- is a race even with a
 * single operator. The window is small; the consequence of losing it is a platform with no
 * administrator at all and no screen left that can create one.
 *
 * The platform tables are built by hand in setUp() rather than through the migration runner. In
 * this environment the platform group points at the same schema as the tests group with an EMPTY
 * prefix (see phpunit.xml.dist), so running the whole Platform namespace here would collide with
 * the `tenants` table other files build for themselves. Same approach as
 * tests/Filters/TenantResolverTest.php.
 *
 * @internal
 */
final class PlatformAccountTest extends CIUnitTestCase
{
    private PlatformAccount $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        // login() regenerates the session id on success, so there has to be a session. The mock
        // keeps it in memory: no cookies, no handler, nothing that outlives the test.
        $sessionConfig = config('Session');
        $session       = new MockSession(new ArrayHandler($sessionConfig, '0.0.0.0'), $sessionConfig);
        $session->setLogger(service('logger'));
        $session->start();
        Services::injectMock('session', $session);

        $this->createTables();

        $this->accounts = new PlatformAccount();
    }

    protected function tearDown(): void
    {
        $platform = db_connect('platform');
        $platform->query('DROP TABLE IF EXISTS `platform_account_recovery_codes`');
        $platform->query('DROP TABLE IF EXISTS `platform_accounts`');
        $platform->resetDataCache();

        parent::tearDown();
    }

    private function createTables(): void
    {
        $platform = db_connect('platform');

        $platform->query('DROP TABLE IF EXISTS `platform_accounts`');
        $platform->query(
            'CREATE TABLE `platform_accounts` (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                is_platform_admin TINYINT(1) NOT NULL DEFAULT 0,
                last_login_at DATETIME NULL,
                created_by_account_id INT UNSIGNED NULL,
                failed_login_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                failed_login_first_at DATETIME NULL,
                totp_secret VARCHAR(512) NULL,
                totp_enabled_at DATETIME NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )',
        );

        $platform->query('DROP TABLE IF EXISTS `platform_account_recovery_codes`');
        $platform->query(
            'CREATE TABLE `platform_account_recovery_codes` (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                account_id INT UNSIGNED NOT NULL,
                code_hash CHAR(64) NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NULL,
                UNIQUE KEY platform_recovery_codes_hash (code_hash),
                KEY platform_recovery_codes_account (account_id)
            )',
        );

        $platform->resetDataCache();
    }

    private function seed(string $email, string $password, bool $isAdmin = true): int
    {
        $platform = db_connect('platform');

        $platform->table('platform_accounts')->insert([
            'email'             => $email,
            'password_hash'     => password_hash($password, PASSWORD_DEFAULT),
            'is_platform_admin' => $isAdmin ? 1 : 0,
        ]);

        return (int) $platform->insertID();
    }

    private function row(int $id): ?object
    {
        return db_connect('platform')->table('platform_accounts')->where('id', $id)->get()->getRow();
    }

    // ================= Entrar =================

    public function testTheRightPasswordGetsIn(): void
    {
        $this->seed('dueno@micronuba.net', 'la-buena');

        $result = $this->accounts->login('dueno@micronuba.net', 'la-buena');

        $this->assertSame(PlatformLoginOutcome::Success, $result->outcome);
        $this->assertNotNull($result->account);
        $this->assertTrue($this->accounts->isLoggedIn());
    }

    public function testTheWrongPasswordDoesNot(): void
    {
        $this->seed('dueno@micronuba.net', 'la-buena');

        $result = $this->accounts->login('dueno@micronuba.net', 'la-mala');

        $this->assertSame(PlatformLoginOutcome::InvalidCredentials, $result->outcome);
        $this->assertFalse($this->accounts->isLoggedIn());
    }

    /**
     * D8: the error must not reveal whether the email exists. The outcomes are the same value, so
     * a screen that renders one message per outcome cannot leak the difference by accident.
     */
    public function testAnUnknownEmailIsIndistinguishableFromAWrongPassword(): void
    {
        $this->seed('dueno@micronuba.net', 'la-buena');

        $this->assertSame(
            PlatformLoginOutcome::InvalidCredentials,
            $this->accounts->login('nadie@micronuba.net', 'lo-que-sea')->outcome,
        );
    }

    /**
     * Three failures per two hours, counted on the ACCOUNT (D8). The fourth attempt is refused even
     * when the password is right -- otherwise it is not a brake, it is a warning.
     */
    public function testThreeFailuresShutTheAccountEvenForTheRightPassword(): void
    {
        $id = $this->seed('dueno@micronuba.net', 'la-buena');

        for ($i = 0; $i < 3; $i++) {
            $this->accounts->login('dueno@micronuba.net', 'la-mala');
        }

        $result = $this->accounts->login('dueno@micronuba.net', 'la-buena');

        $this->assertSame(PlatformLoginOutcome::Locked, $result->outcome);
        $this->assertFalse($this->accounts->isLoggedIn());
        $this->assertSame(3, (int) $this->row($id)->failed_login_count);
    }

    /**
     * "La ventana se cura sola": two hours after the FIRST failure the count is forgotten. Measured
     * from the first and not the last, so a stream of attempts cannot keep pushing the end away.
     */
    public function testTheWindowHealsItselfAfterTwoHours(): void
    {
        $id = $this->seed('dueno@micronuba.net', 'la-buena');

        for ($i = 0; $i < 3; $i++) {
            $this->accounts->login('dueno@micronuba.net', 'la-mala');
        }

        // Reaching into the row rather than sleeping for two hours: the state under test is "the
        // window opened long enough ago", and that is exactly what this writes.
        db_connect('platform')->table('platform_accounts')->where('id', $id)->update([
            'failed_login_first_at' => date('Y-m-d H:i:s', time() - 3 * 3600),
        ]);

        $result = $this->accounts->login('dueno@micronuba.net', 'la-buena');

        $this->assertSame(PlatformLoginOutcome::Success, $result->outcome);
        $this->assertSame(0, (int) $this->row($id)->failed_login_count, 'A clean login clears the count.');
    }

    /**
     * The other half of D8, and the reason the platform must never be down to one account: somebody
     * else can lift the brake without waiting two hours.
     */
    public function testAnotherAdministratorCanUnlockBeforeTheWindowExpires(): void
    {
        $id = $this->seed('dueno@micronuba.net', 'la-buena');

        for ($i = 0; $i < 3; $i++) {
            $this->accounts->login('dueno@micronuba.net', 'la-mala');
        }

        $this->accounts->unlock($id);

        $this->assertSame(PlatformLoginOutcome::Success, $this->accounts->login('dueno@micronuba.net', 'la-buena')->outcome);
        $this->assertNull($this->row($id)->failed_login_first_at);
    }

    /**
     * With the second factor on, the password alone is not a session. Nothing is logged in yet --
     * the account is only PENDING, which is what the console's guard sends to the challenge screen.
     */
    public function testWithTheSecondFactorOnThePasswordAloneIsNotASession(): void
    {
        $id = $this->seed('dueno@micronuba.net', 'la-buena');

        db_connect('platform')->table('platform_accounts')->where('id', $id)->update([
            'totp_secret'     => 'da-igual-aqui',
            'totp_enabled_at' => date('Y-m-d H:i:s'),
        ]);

        $result = $this->accounts->login('dueno@micronuba.net', 'la-buena');

        $this->assertSame(PlatformLoginOutcome::SecondFactorRequired, $result->outcome);
        $this->assertFalse($this->accounts->isLoggedIn(), 'Half an authentication is not an authentication.');
        $this->assertSame($id, $this->accounts->pendingSecondFactorAccountId());
    }

    public function testCompletingTheSecondFactorOpensTheSessionAndClearsThePendingMark(): void
    {
        $id = $this->seed('dueno@micronuba.net', 'la-buena');

        db_connect('platform')->table('platform_accounts')->where('id', $id)->update([
            'totp_enabled_at' => date('Y-m-d H:i:s'),
        ]);

        $this->accounts->login('dueno@micronuba.net', 'la-buena');
        $this->accounts->completeSecondFactor($id);

        $this->assertTrue($this->accounts->isLoggedIn());
        $this->assertNull($this->accounts->pendingSecondFactorAccountId());
        $this->assertNotNull($this->row($id)->last_login_at, 'Last login is stamped once the login is whole.');
    }

    /**
     * D6 buys "who changed what" at the price of "who entered and when". This column is the whole
     * of what is left of the second question, and it is the column that names the orphan account.
     */
    public function testLoggingInStampsTheLastLogin(): void
    {
        $id = $this->seed('dueno@micronuba.net', 'la-buena');

        $this->assertNull($this->row($id)->last_login_at);

        $this->accounts->login('dueno@micronuba.net', 'la-buena');

        $this->assertNotNull($this->row($id)->last_login_at);
    }

    public function testTouchLastLoginCanBeCalledOnItsOwn(): void
    {
        $id = $this->seed('dueno@micronuba.net', 'la-buena');

        $this->accounts->touchLastLogin($id);

        $this->assertNotNull($this->row($id)->last_login_at);
    }

    // ================= Contar y borrar =================

    /**
     * Counting ADMINISTRATORS, not accounts. The day a business owner has a platform account with
     * is_platform_admin = 0 (Entrega 5), counting rows would report a healthy platform while the
     * last administrator walks out of it.
     */
    public function testItCountsAdministratorsAndNotAccounts(): void
    {
        $this->seed('dueno@micronuba.net', 'x', true);
        $this->seed('cliente1@negocio.com', 'x', false);
        $this->seed('cliente2@negocio.com', 'x', false);

        $this->assertSame(1, $this->accounts->countAdmins());
    }

    public function testTheLastAdministratorCannotBeDeletedEvenWithOtherAccountsAround(): void
    {
        $admin = $this->seed('dueno@micronuba.net', 'x', true);
        $other = $this->seed('cliente@negocio.com', 'x', false);

        $this->expectException(RuntimeException::class);

        try {
            $this->accounts->deleteAccount($admin, $other);
        } finally {
            $this->assertNotNull($this->row($admin), 'The platform must never be left without an administrator.');
        }
    }

    public function testNobodyDeletesThemselves(): void
    {
        $first = $this->seed('dueno@micronuba.net', 'x', true);
        $this->seed('segunda@micronuba.net', 'x', true);

        $this->expectException(RuntimeException::class);

        try {
            $this->accounts->deleteAccount($first, $first);
        } finally {
            $this->assertNotNull($this->row($first));
        }
    }

    /**
     * The case the whole Entrega exists for: two administrators, and the orphan goes.
     */
    public function testOneAdministratorCanDeleteAnotherWhenSomebodyIsLeft(): void
    {
        $real   = $this->seed('dueno@micronuba.net', 'x', true);
        $orphan = $this->seed('admin@ospos-saas.micronuba.net', 'x', true);

        $this->accounts->deleteAccount($orphan, $real);

        $this->assertNull($this->row($orphan));
        $this->assertNotNull($this->row($real));
    }

    /**
     * Recovery codes belong to the account and cannot outlive it: left behind, they would be
     * orphaned secrets that still hash-match and whose only owner no longer exists.
     */
    public function testDeletingAnAccountTakesItsRecoveryCodesWithIt(): void
    {
        $real   = $this->seed('dueno@micronuba.net', 'x', true);
        $orphan = $this->seed('admin@ospos-saas.micronuba.net', 'x', true);

        $this->accounts->issueRecoveryCodes($orphan);
        $this->accounts->deleteAccount($orphan, $real);

        $this->assertSame(
            0,
            db_connect('platform')->table('platform_account_recovery_codes')->where('account_id', $orphan)->countAllResults(),
        );
    }

    public function testDeletingAnAccountThatIsNotThereIsRefusedRatherThanIgnored(): void
    {
        $real = $this->seed('dueno@micronuba.net', 'x', true);
        $this->seed('segunda@micronuba.net', 'x', true);

        $this->expectException(RuntimeException::class);

        $this->accounts->deleteAccount(99999, $real);
    }

    // ================= La contraseña propia =================

    public function testChangingThePasswordInvalidatesTheOldOne(): void
    {
        $id = $this->seed('dueno@micronuba.net', 'la-vieja');

        $this->accounts->changePassword($id, 'la-nueva');

        $this->assertSame(PlatformLoginOutcome::InvalidCredentials, $this->accounts->login('dueno@micronuba.net', 'la-vieja')->outcome);
        $this->assertSame(PlatformLoginOutcome::Success, $this->accounts->login('dueno@micronuba.net', 'la-nueva')->outcome);
    }

    // ================= Códigos de rescate =================

    /**
     * The assertion the separate table exists for. The second call has to fail on the DATABASE, not
     * on a flag this code remembered, which is why the model reports what affectedRows() said.
     */
    public function testARecoveryCodeWorksExactlyOnce(): void
    {
        $id    = $this->seed('dueno@micronuba.net', 'x');
        $codes = $this->accounts->issueRecoveryCodes($id);

        $this->assertTrue($this->accounts->consumeRecoveryCode($id, $codes[0]));
        $this->assertFalse($this->accounts->consumeRecoveryCode($id, $codes[0]), 'A spent code is spent.');
        $this->assertSame(0, db_connect('platform')->affectedRows());
    }

    public function testSpendingOneCodeLeavesTheOthersAlone(): void
    {
        $id    = $this->seed('dueno@micronuba.net', 'x');
        $codes = $this->accounts->issueRecoveryCodes($id);

        $this->accounts->consumeRecoveryCode($id, $codes[0]);

        $this->assertTrue($this->accounts->consumeRecoveryCode($id, $codes[1]));
    }

    public function testACodeBelongingToAnotherAccountIsNotAccepted(): void
    {
        $mine     = $this->seed('dueno@micronuba.net', 'x');
        $somebody = $this->seed('otra@micronuba.net', 'x');

        $codes = $this->accounts->issueRecoveryCodes($somebody);

        $this->assertFalse($this->accounts->consumeRecoveryCode($mine, $codes[0]));
    }

    /**
     * They are stored hashed and shown once. A code readable in the database would be a password
     * written on the wall next to the safe.
     */
    public function testTheCodesAreNeverStoredInTheClear(): void
    {
        $id    = $this->seed('dueno@micronuba.net', 'x');
        $codes = $this->accounts->issueRecoveryCodes($id);

        $stored = db_connect('platform')->table('platform_account_recovery_codes')
            ->where('account_id', $id)->get()->getResult();

        $hashes = array_column($stored, 'code_hash');

        foreach ($codes as $code) {
            $this->assertNotContains($code, $hashes);
            $this->assertContains(hash('sha256', preg_replace('/[^A-Z0-9]/', '', strtoupper($code))), $hashes);
        }
    }

    /**
     * Re-issuing replaces the set. Leaving the old ones valid would mean a superadministrator who
     * generated a new sheet because the old one leaked has not actually revoked anything.
     */
    public function testIssuingANewSetRevokesThePreviousOne(): void
    {
        $id  = $this->seed('dueno@micronuba.net', 'x');
        $old = $this->accounts->issueRecoveryCodes($id);

        $this->accounts->issueRecoveryCodes($id);

        $this->assertFalse($this->accounts->consumeRecoveryCode($id, $old[0]));
    }
}
