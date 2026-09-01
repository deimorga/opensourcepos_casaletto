<?php

namespace App\Models;

use CodeIgniter\Model;
use RuntimeException;
use Throwable;

/**
 * Platform-level identity: business owners (who may be linked to more
 * than one tenant via platform_account_tenants) and platform
 * administrators (is_platform_admin=1). Lives in the `platform`
 * control schema -- completely separate from each tenant's own
 * `employees`/`people`, and from CI4's session-backed Employee login.
 *
 * Fase 8. See docs/Tecnico/multi-tenant-arquitectura.md section 10, and
 * docs/Tecnico/gestion-de-plataforma-y-negocios.md for Entrega 2.
 *
 * THE TWO SESSION KEYS, AND WHY THERE ARE TWO
 *
 * `platform_account_id` means authenticated, full stop: everything the console does is gated on it.
 * `platform_pending_account_id` means the password was right and the second factor of D11 has not
 * been answered yet. They are separate keys rather than one key plus a flag because the failure
 * mode of getting that wrong is silent and total -- a half-authenticated visitor holding the same
 * key as a whole one is simply logged in, and no test that checks "is there a session" would ever
 * notice. Nothing but completeSecondFactor() promotes one into the other.
 *
 * ON STORING THE TOTP SECRET (for whoever builds the enrolment screen)
 *
 * `totp_secret` is VARCHAR(512) and takes `service('encrypter')->encrypt($secret)` with NO
 * base64_encode() wrapped around it. The encrypter already returns printable text; the extra encode
 * doubles the length and MySQL truncates the overflow without a word, which is how
 * `tenants.db_password` broke once already. See app/Libraries/TenantProvisioner.php.
 */
class PlatformAccount extends Model
{
    private const SESSION_KEY    = 'platform_account_id';
    private const PENDING_KEY    = 'platform_pending_account_id';
    private const RECOVERY_TABLE = 'platform_account_recovery_codes';

    /**
     * D8: three failures per two hours, counted on the account, with a window that heals itself.
     */
    public const MAX_FAILED_ATTEMPTS = 3;

    public const LOCKOUT_WINDOW_SECONDS = 2 * 3600;

    /**
     * Section 6 of the technical design: "eight or ten, single use, shown once, stored hashed".
     */
    public const RECOVERY_CODE_COUNT = 10;

    /**
     * A real bcrypt hash of 32 random bytes nobody kept, verified against when the email does not
     * exist so that a missing account costs about the same time as a wrong password. Without it,
     * "unknown email" answers in microseconds and "known email, wrong password" takes the ~100 ms
     * bcrypt costs -- and D8's requirement that the error not reveal whether the email exists is
     * then defeated by a stopwatch rather than by the message.
     *
     * It has to be a well-formed hash: password_verify() rejects a malformed one immediately, which
     * would cost nothing and prove nothing.
     */
    private const ABSENT_ACCOUNT_HASH = '$2y$12$cLkE3OXnxD1TP0IwNDfGd.Qbm0H7V4uXyi/qhhieiXPWAygAf25g.';

    protected $DBGroup       = 'platform';
    protected $table         = 'platform_accounts';
    protected $primaryKey    = 'id';
    protected $returnType    = 'object';
    protected $allowedFields = [
        'email',
        'password_hash',
        'is_platform_admin',
        'last_login_at',
        'created_by_account_id',
        'failed_login_count',
        'failed_login_first_at',
        'totp_secret',
        'totp_enabled_at',
    ];
    protected $useTimestamps = true;

    /**
     * Verifies credentials and reports which of the four things happened. On Success the session is
     * open; on SecondFactorRequired the account is only PENDING and nothing is authenticated yet.
     *
     * The order of the checks is the whole design:
     *   1. No such account -> InvalidCredentials, after burning a comparable amount of time.
     *   2. Inside the lockout window -> Locked, WITHOUT checking the password. A brake that lets
     *      the right password through is not a brake, and checking anyway would turn the lockout
     *      into an oracle for whether a guess was correct.
     *   3. Window expired -> forget the count first, so the attempt is judged on its own merits.
     *   4. Wrong password -> count it. If that count trips the limit, say so: it is the moment the
     *      controller records `account.locked` (D6), and it happens exactly once.
     *   5. Right password -> clear the count, then either open the session or ask for the factor.
     */
    public function login(string $email, string $password): PlatformLoginResult
    {
        $account = $this->where('email', $email)->first();

        if ($account === null) {
            password_verify($password, self::ABSENT_ACCOUNT_HASH);

            return PlatformLoginResult::invalidCredentials();
        }

        if ($this->isLocked($account)) {
            return PlatformLoginResult::locked($account);
        }

        if ($this->windowHasExpired($account)) {
            $this->clearFailures((int) $account->id);
            $account->failed_login_count    = 0;
            $account->failed_login_first_at = null;
        }

        if (! password_verify($password, $account->password_hash)) {
            return $this->registerFailure($account)
                ? PlatformLoginResult::locked($account, true)
                : PlatformLoginResult::invalidCredentials();
        }

        $this->clearFailures((int) $account->id);

        if ($account->totp_enabled_at !== null) {
            // Regenerated here as well as on completion: the privilege level changes at both steps,
            // and a session id fixed on the victim before the password must not survive either one.
            session()->regenerate(true);
            session()->set(self::PENDING_KEY, (int) $account->id);

            return PlatformLoginResult::secondFactorRequired($account);
        }

        $this->openSession((int) $account->id);

        return PlatformLoginResult::success($account);
    }

    /**
     * The account that gave a correct password and still owes a second factor, or null.
     *
     * Public because the console's base controller has to be able to send that visitor to the
     * challenge screen instead of back to the login form, and because the challenge screen itself
     * has no other way to know whose factor it is verifying.
     */
    public function pendingSecondFactorAccountId(): ?int
    {
        $id = session()->get(self::PENDING_KEY);

        return $id === null ? null : (int) $id;
    }

    /**
     * Promotes a pending login into a real one. Called only once the TOTP code -- or a recovery
     * code -- has been verified.
     *
     * Refuses to promote an account that is not the pending one, so a request that names somebody
     * else's id cannot open their session.
     */
    public function completeSecondFactor(int $accountId): bool
    {
        if ($this->pendingSecondFactorAccountId() !== $accountId) {
            return false;
        }

        $this->openSession($accountId);

        return true;
    }

    public function logout(): void
    {
        session()->destroy();
    }

    public function isLoggedIn(): bool
    {
        return session()->get(self::SESSION_KEY) !== null;
    }

    public function getLoggedInAccount(): ?object
    {
        $id = session()->get(self::SESSION_KEY);

        return $id === null ? null : $this->find($id);
    }

    public function isPlatformAdmin(): bool
    {
        $account = $this->getLoggedInAccount();

        return $account !== null && (bool) $account->is_platform_admin;
    }

    /**
     * Tenants this account can switch into: active tenants only, joined
     * through platform_account_tenants. Drives the business selector
     * shown when an owner has more than one negocio.
     */
    public function getTenantsForAccount(int $accountId): array
    {
        return $this->db->table('tenants')
            ->select('tenants.id, tenants.slug, tenants.status')
            ->join('platform_account_tenants', 'platform_account_tenants.tenant_id = tenants.id')
            ->where('platform_account_tenants.account_id', $accountId)
            ->where('tenants.status', 'active')
            ->orderBy('tenants.slug')
            ->get()
            ->getResult();
    }

    /**
     * @param int|null $createdByAccountId the account doing the creating, or NULL for the command
     *                                     line. NULL is not "unknown": it is the mark that says
     *                                     this account was born in a terminal, which is the one
     *                                     thing that betrays an orphan in the listing.
     */
    public function createAccount(
        string $email,
        string $password,
        bool $isPlatformAdmin = false,
        ?int $createdByAccountId = null,
    ): int {
        $this->insert([
            'email'                 => $email,
            'password_hash'         => password_hash($password, PASSWORD_DEFAULT),
            'is_platform_admin'     => $isPlatformAdmin ? 1 : 0,
            'created_by_account_id' => $createdByAccountId,
        ]);

        return (int) $this->getInsertID();
    }

    /**
     * How many ADMINISTRATORS there are -- not how many accounts.
     *
     * From Entrega 5 a business owner will hold a platform account with is_platform_admin = 0.
     * Counting rows would then report a healthy platform at the exact moment the last person who
     * can administer it is being deleted, and there would be no screen left that could create
     * another one.
     */
    public function countAdmins(): int
    {
        return $this->builder()->where('is_platform_admin', 1)->countAllResults();
    }

    public function changePassword(int $accountId, string $newPassword): bool
    {
        return (bool) $this->update($accountId, [
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        ]);
    }

    /**
     * Lifts the brake of D8 so somebody who locked themselves out does not have to wait two hours.
     * Only another superadministrator can reach this, which is the reason the platform must never
     * be down to a single account (section 9.12 of the technical design).
     */
    public function unlock(int $accountId): bool
    {
        return $this->clearFailures($accountId);
    }

    public function touchLastLogin(int $accountId): bool
    {
        return $this->builder()
            ->where('id', $accountId)
            ->update(['last_login_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Deletes a superadministrator, with both safeguards enforced HERE rather than on the screen.
     *
     * WHY A TRANSACTION AND WHY `FOR UPDATE`
     *
     * "Count the administrators, then delete one" is two statements, and between them another
     * request can delete the other administrator. Both requests see two, both delete one, and the
     * platform is left with none and with no screen able to create another. There is one operator
     * today, so this will not happen often -- which is precisely the problem: a rare failure that
     * ends with nobody able to log in is worse than a frequent one, because nothing will ever
     * reproduce it. `FOR UPDATE` makes the two requests take turns; the second one then counts one
     * administrator and is refused.
     *
     * The self-check comes first and outside, because it cannot race -- the actor's id comes from
     * their own session -- and because it deserves its own message.
     *
     * @throws RuntimeException with a translated message. The caller shows it and changes nothing.
     */
    public function deleteAccount(int $accountId, int $actorAccountId): void
    {
        if ($accountId === $actorAccountId) {
            throw new RuntimeException(lang('Platform.account_delete_refused_self'));
        }

        $table = $this->db->protectIdentifiers($this->table, true);

        $this->db->transBegin();

        try {
            $target = $this->db->query("SELECT id, is_platform_admin FROM {$table} WHERE id = ? FOR UPDATE", [$accountId])->getRow();

            if ($target === null) {
                throw new RuntimeException(lang('Platform.account_delete_refused_missing'));
            }

            if ((bool) $target->is_platform_admin) {
                $admins = (int) $this->db
                    ->query("SELECT COUNT(*) AS admins FROM {$table} WHERE is_platform_admin = 1 FOR UPDATE")
                    ->getRow()
                    ->admins;

                if ($admins <= 1) {
                    throw new RuntimeException(lang('Platform.account_delete_refused_last_admin'));
                }
            }

            // The recovery codes go with the account: left behind they would be orphaned secrets
            // that still hash-match. The activity log deliberately does NOT go -- it keeps the
            // actor's email on the row so it stays readable once its subject is gone.
            $this->db->table(self::RECOVERY_TABLE)->where('account_id', $accountId)->delete();
            $this->db->table($this->table)->where('id', $accountId)->delete();

            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    // ===================== Códigos de rescate =====================

    /**
     * Issues a fresh set and returns them IN THE CLEAR -- the only moment they are ever readable.
     * Any previous set is revoked in the same breath, because a sheet regenerated after a leak that
     * left the old codes working would have revoked nothing.
     *
     * 64 bits from random_bytes(), formatted in four groups for a human to copy off a screen. There
     * is nothing here for anybody to guess, which is why the stored form is a plain SHA-256 and not
     * a password hash; see the migration for the full reasoning.
     *
     * @return list<string> the codes, in the clear, to be shown once and never stored
     */
    public function issueRecoveryCodes(int $accountId, int $howMany = self::RECOVERY_CODE_COUNT): array
    {
        $this->db->table(self::RECOVERY_TABLE)->where('account_id', $accountId)->delete();

        $now   = date('Y-m-d H:i:s');
        $codes = [];
        $rows  = [];

        for ($i = 0; $i < $howMany; $i++) {
            $code    = implode('-', str_split(strtoupper(bin2hex(random_bytes(8))), 4));
            $codes[] = $code;
            $rows[]  = [
                'account_id' => $accountId,
                'code_hash'  => $this->hashRecoveryCode($code),
                'created_at' => $now,
            ];
        }

        $this->db->table(self::RECOVERY_TABLE)->insertBatch($rows);

        return $codes;
    }

    /**
     * Spends one code, and answers whether THIS call is the one that spent it.
     *
     * The single statement is the point. `used_at IS NULL` inside the UPDATE means the database
     * decides, and `affectedRows() === 1` is the proof; reading the row first and updating it
     * afterwards would let two simultaneous requests both find it unused and both get in.
     *
     * Case and separators are normalised because these are read off a screen and typed back by a
     * person, and neither carries any information.
     */
    public function consumeRecoveryCode(int $accountId, string $code): bool
    {
        $this->db->table(self::RECOVERY_TABLE)
            ->where('account_id', $accountId)
            ->where('code_hash', $this->hashRecoveryCode($code))
            ->where('used_at', null)
            ->update(['used_at' => date('Y-m-d H:i:s')]);

        return $this->db->affectedRows() === 1;
    }

    public function unusedRecoveryCodeCount(int $accountId): int
    {
        return $this->db->table(self::RECOVERY_TABLE)
            ->where('account_id', $accountId)
            ->where('used_at', null)
            ->countAllResults();
    }

    private function hashRecoveryCode(string $code): string
    {
        return hash('sha256', (string) preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($code))));
    }

    // ===================== El freno de D8 =====================

    /**
     * Note on time: both the write and this comparison go through PHP's default timezone, which is
     * fixed for the console by App\Events\Load_config. They are read and written on the same host
     * in the same request cycle, so they cannot disagree -- but a caller that changes the process
     * timezone between the two would shift this window by whole hours.
     */
    private function isLocked(object $account): bool
    {
        return (int) $account->failed_login_count >= self::MAX_FAILED_ATTEMPTS
            && $account->failed_login_first_at !== null
            && strtotime((string) $account->failed_login_first_at) > time() - self::LOCKOUT_WINDOW_SECONDS;
    }

    private function windowHasExpired(object $account): bool
    {
        return $account->failed_login_first_at !== null
            && strtotime((string) $account->failed_login_first_at) <= time() - self::LOCKOUT_WINDOW_SECONDS;
    }

    /**
     * @return bool true when this failure is the one that tripped the limit -- the transition the
     *              controller records as `account.locked`, and only then.
     */
    private function registerFailure(object $account): bool
    {
        $count  = (int) $account->failed_login_count + 1;
        $update = ['failed_login_count' => $count];

        // The window is measured from the FIRST failure, so a stream of attempts cannot keep
        // pushing its end further away by arriving.
        if ($account->failed_login_first_at === null) {
            $update['failed_login_first_at'] = date('Y-m-d H:i:s');
        }

        $this->builder()->where('id', $account->id)->update($update);

        return $count === self::MAX_FAILED_ATTEMPTS;
    }

    private function clearFailures(int $accountId): bool
    {
        return $this->builder()->where('id', $accountId)->update([
            'failed_login_count'    => 0,
            'failed_login_first_at' => null,
        ]);
    }

    private function openSession(int $accountId): void
    {
        // Regenerate the session id on successful auth (and destroy the old
        // session's data server-side) so a pre-auth id an attacker may have
        // fixed on the victim can't be reused post-login.
        session()->regenerate(true);
        session()->remove(self::PENDING_KEY);
        session()->set(self::SESSION_KEY, $accountId);

        $this->touchLastLogin($accountId);
    }
}
