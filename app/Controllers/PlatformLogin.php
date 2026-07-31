<?php

namespace App\Controllers;

use App\Models\PlatformAccount;
use CodeIgniter\HTTP\RedirectResponse;
use Config\App as AppConfig;

/**
 * Neutral login for the SaaS platform, separate from each tenant's own
 * Employee::login() (unchanged). Authenticates against
 * platform_accounts, then either:
 *  - sends a platform admin straight to the business-management panel
 *    (PlatformAdmin, Fase 8), or
 *  - sends a business owner directly to their one negocio, or shows a
 *    selector if they own more than one (platform_account_tenants).
 *
 * See docs/Tecnico/multi-tenant-arquitectura.md section 10.
 */
class PlatformLogin extends BaseController
{
    private PlatformAccount $account;

    public function __construct()
    {
        $this->account = model(PlatformAccount::class);
    }

    /**
     * @return string|RedirectResponse
     */
    public function index(): string|RedirectResponse
    {
        if ($this->account->isLoggedIn()) {
            return $this->redirectAfterLogin();
        }

        $data = ['has_errors' => false, 'error' => null];

        if ($this->request->getMethod() !== 'POST') {
            return view('platform/login', $data);
        }

        $email = (string) $this->request->getPost('email');
        $password = (string) $this->request->getPost('password');

        if ($this->account->login($email, $password) === null) {
            $data['has_errors'] = true;
            $data['error'] = lang('Platform.invalid_credentials');

            return view('platform/login', $data);
        }

        return $this->redirectAfterLogin();
    }

    /**
     * Shows the business selector for an owner linked to more than one
     * tenant. Direct hits with 0 or 1 tenant are redirected elsewhere by
     * redirectAfterLogin() before ever reaching this action.
     */
    public function selectIndex(): string|RedirectResponse
    {
        $account = $this->account->getLoggedInAccount();

        if ($account === null) {
            return redirect()->to('platform/login');
        }

        return view('platform/select_business', [
            'tenants' => $this->account->getTenantsForAccount((int) $account->id),
        ]);
    }

    public function select(string $slug): RedirectResponse
    {
        $account = $this->account->getLoggedInAccount();

        if ($account === null) {
            return redirect()->to('platform/login');
        }

        foreach ($this->account->getTenantsForAccount((int) $account->id) as $tenant) {
            if ($tenant->slug === $slug) {
                return redirect()->to($this->tenantUrl($slug));
            }
        }

        return redirect()->to('platform/select')->with('error', lang('Platform.tenant_not_linked'));
    }

    public function logout(): RedirectResponse
    {
        $this->account->logout();

        return redirect()->to('platform/login');
    }

    private function redirectAfterLogin(): RedirectResponse
    {
        if ($this->account->isPlatformAdmin()) {
            return redirect()->to('platform/admin');
        }

        $account = $this->account->getLoggedInAccount();
        $tenants = $this->account->getTenantsForAccount((int) $account->id);

        if (count($tenants) === 1) {
            return redirect()->to($this->tenantUrl($tenants[0]->slug));
        }

        if (count($tenants) === 0) {
            $this->account->logout();

            return redirect()->to('platform/login')->with('error', lang('Platform.no_tenants_linked'));
        }

        return redirect()->to('platform/select');
    }

    /**
     * Builds the tenant's URL from the configured wildcard suffix (ex.
     * ".ospos-saas.micronuba.net", see app/Config/App.php). Falls back
     * to the current base URL when no wildcard is configured (local/dev
     * environments that only serve a single host).
     */
    private function tenantUrl(string $slug): string
    {
        $appConfig = config(AppConfig::class);
        $wildcard = $appConfig->allowedHostnameWildcards[0] ?? null;

        if ($wildcard === null) {
            return base_url();
        }

        $scheme = $appConfig->https_on ? 'https' : 'http';

        return $scheme . '://' . $slug . $wildcard . '/';
    }
}
