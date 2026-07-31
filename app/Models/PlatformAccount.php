<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Platform-level identity: business owners (who may be linked to more
 * than one tenant via platform_account_tenants) and platform
 * administrators (is_platform_admin=1). Lives in the `platform`
 * control schema -- completely separate from each tenant's own
 * `employees`/`people`, and from CI4's session-backed Employee login.
 *
 * Fase 8. See docs/Tecnico/multi-tenant-arquitectura.md section 10.
 */
class PlatformAccount extends Model
{
    protected $DBGroup      = 'platform';
    protected $table        = 'platform_accounts';
    protected $primaryKey   = 'id';
    protected $returnType   = 'object';
    protected $allowedFields = ['email', 'password_hash', 'is_platform_admin'];
    protected $useTimestamps = true;

    private const SESSION_KEY = 'platform_account_id';

    /**
     * Verifies credentials and, on success, stores the account id in
     * the session. Returns the account row, or null on failure.
     */
    public function login(string $email, string $password): ?object
    {
        $account = $this->where('email', $email)->first();

        if ($account === null || !password_verify($password, $account->password_hash)) {
            return null;
        }

        session()->set(self::SESSION_KEY, $account->id);

        return $account;
    }

    public function logout(): void
    {
        session()->remove(self::SESSION_KEY);
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

    public function createAccount(string $email, string $password, bool $isPlatformAdmin = false): int
    {
        $this->insert([
            'email'             => $email,
            'password_hash'     => password_hash($password, PASSWORD_DEFAULT),
            'is_platform_admin' => $isPlatformAdmin ? 1 : 0,
        ]);

        return (int) $this->getInsertID();
    }
}
