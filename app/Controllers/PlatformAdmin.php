<?php

namespace App\Controllers;

use App\Libraries\TenantProvisioner;
use App\Models\PlatformAccount;
use CodeIgniter\HTTP\Exceptions\RedirectException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

/**
 * Business-management platform (Fase 8): the web panel that lets a
 * platform administrator create, modify, suspend and delete
 * negocios-cliente, wrapping App\Libraries\TenantProvisioner (the same
 * logic `php spark tenant:create` uses) instead of requiring SSH/CLI
 * access for day-to-day tenant lifecycle operations.
 *
 * Gated by platform_accounts.is_platform_admin -- unrelated to
 * Employee/Secure_Controller's tenant-scoped grants, since this panel
 * operates on the platform control schema, not any one tenant's data.
 * Deliberately offers no "log in as this tenant" action (impersonation
 * stays out of scope, see docs/Funcional/multi-tenant-multi-negocio.md
 * section 5).
 */
class PlatformAdmin extends BaseController
{
    private PlatformAccount $account;
    private TenantProvisioner $provisioner;

    public function __construct()
    {
        $this->account = model(PlatformAccount::class);

        if (!$this->account->isPlatformAdmin()) {
            throw new RedirectException('platform/login');
        }

        $this->provisioner = new TenantProvisioner();
    }

    public function index(): string
    {
        $tenants = db_connect('platform')->table('tenants')->orderBy('slug')->get()->getResult();

        return view('platform/admin/index', ['tenants' => $tenants]);
    }

    public function newTenant(): string
    {
        return view('platform/admin/form', ['error' => null]);
    }

    public function create(): string|RedirectResponse
    {
        $slug = (string) $this->request->getPost('slug');
        $companyName = (string) $this->request->getPost('company_name');

        try {
            $result = $this->provisioner->create($slug, $companyName);
        } catch (RuntimeException $e) {
            return view('platform/admin/form', ['error' => $e->getMessage()]);
        }

        return redirect()->to('platform/admin')->with(
            'message',
            "Negocio '{$result['slug']}' creado. Usuario admin: admin / contraseña: {$result['admin_password']} (entrégala de forma segura, no queda visible de nuevo)."
        );
    }

    public function suspend(string $slug): RedirectResponse
    {
        $this->provisioner->setStatus($slug, 'suspended');

        return redirect()->to('platform/admin');
    }

    public function activate(string $slug): RedirectResponse
    {
        $this->provisioner->setStatus($slug, 'active');

        return redirect()->to('platform/admin');
    }

    /**
     * Confirmation step -- deleting a tenant is destructive enough
     * (even without dropping the schema) that it must not be a bare GET
     * link.
     */
    public function confirmDelete(string $slug): string
    {
        return view('platform/admin/confirm_delete', ['slug' => $slug]);
    }

    public function delete(string $slug): ResponseInterface
    {
        $dropSchema = $this->request->getPost('drop_schema') === '1';

        try {
            $this->provisioner->delete($slug, $dropSchema);
        } catch (RuntimeException $e) {
            return redirect()->to('platform/admin')->with('error', $e->getMessage());
        }

        return redirect()->to('platform/admin')->with('message', "Negocio '$slug' eliminado.");
    }
}
