<?php

namespace App\Controllers;

use App\Libraries\TenantProvisioner;
use App\Models\PlatformAccount;
use CodeIgniter\Exceptions\PageNotFoundException;
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

        // Which of them cannot be deleted from here, worked out once so the
        // view never has to ask the provisioner anything. D3 keeps adopted
        // businesses listed and manageable (Casaletto is one), so the listing
        // shows the reason instead of quietly dropping the action.
        $adopted = [];

        foreach ($tenants as $tenant) {
            $adopted[$tenant->slug] = $this->provisioner->isAdopted($tenant);
        }

        return view('platform/admin/index', ['tenants' => $tenants, 'adopted' => $adopted]);
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
     *
     * Loads the row so the screen can name the database at stake and
     * refuse outright for an adopted business, and so a slug nobody
     * registered is a 404 rather than a form that fails on submit.
     */
    public function confirmDelete(string $slug): string
    {
        $tenant = $this->findTenant($slug);

        return view('platform/admin/confirm_delete', [
            'tenant'  => $tenant,
            'adopted' => $this->provisioner->isAdopted($tenant),
        ]);
    }

    /**
     * Three gates, in this order, and each one refuses the WHOLE
     * request rather than doing part of what was asked:
     *
     *  1. An adopted business is never deletable from here. Casaletto is
     *     one, and its schema is `ospos` -- the database the shop is
     *     selling from. TenantProvisioner::delete() refuses it too; this
     *     is the polite refusal, that one is the guarantee.
     *  2. The operator has to type the slug. A checkbox is something you
     *     tick by accident on the wrong row; a name is something you
     *     have to read first.
     *  3. Destroying the schema needs its NAME typed as well, separately.
     *     "ospos" warns far more than "casaletto" does.
     *
     * Until the activity log arrives (Entrega 2, see
     * docs/Funcional/gestion-de-plataforma-y-negocios.md section 6.5)
     * the record of who did this is a critical log line, written BEFORE
     * the destructive call so it survives a process that dies halfway
     * through a DROP.
     */
    public function delete(string $slug): ResponseInterface
    {
        $tenant = $this->findTenant($slug);

        if ($this->provisioner->isAdopted($tenant)) {
            return $this->refuse($slug, lang('Platform.delete_refused_adopted', [$slug, $tenant->db_name]));
        }

        if (!$this->typedCorrectly((string) $tenant->slug, $this->request->getPost('confirm_slug'))) {
            return $this->refuse($slug, lang('Platform.delete_refused_slug', [$slug]));
        }

        $dropSchema = $this->request->getPost('drop_schema') === '1';

        if ($dropSchema && !$this->typedCorrectly((string) $tenant->db_name, $this->request->getPost('confirm_db_name'))) {
            return $this->refuse($slug, lang('Platform.delete_refused_db_name', [$tenant->db_name]));
        }

        $who = $this->actor();
        $when = date('c');

        log_message('critical', "PLATFORM DELETE [$when] $who is deleting business '$slug' (schema '{$tenant->db_name}').");

        if ($dropSchema) {
            log_message('critical', "PLATFORM DROP SCHEMA [$when] $who is destroying database '{$tenant->db_name}', of business '$slug'. This cannot be undone.");
        }

        try {
            $this->provisioner->delete($slug, $dropSchema);
        } catch (RuntimeException $e) {
            log_message('critical', "PLATFORM DELETE FAILED [$when] $who tried to delete business '$slug': " . $e->getMessage());

            return redirect()->to('platform/admin')->with('error', $e->getMessage());
        }

        return redirect()->to('platform/admin')->with('message', lang('Platform.deleted', [$slug]));
    }

    /**
     * @throws PageNotFoundException when no business carries that slug.
     */
    private function findTenant(string $slug): object
    {
        $tenant = db_connect('platform')->table('tenants')->where('slug', $slug)->get()->getRow();

        if ($tenant === null) {
            throw PageNotFoundException::forPageNotFound("No business is registered under the slug '$slug'.");
        }

        return $tenant;
    }

    /**
     * Whitespace is trimmed -- neither a slug (`^[a-z0-9-]{1,20}$`) nor
     * a schema name can contain any, so trimming can never turn a wrong
     * answer into a right one, and a value pasted with a trailing space
     * should not read as a typo.
     */
    private function typedCorrectly(string $expected, ?string $typed): bool
    {
        return $expected !== '' && hash_equals($expected, trim((string) $typed));
    }

    /**
     * Back to the confirmation screen, not to the listing: a refusal is
     * almost always a typo, and the operator needs the same page to try
     * again on.
     */
    private function refuse(string $slug, string $message): RedirectResponse
    {
        log_message('critical', 'PLATFORM DELETE REFUSED [' . date('c') . '] ' . $this->actor() . " on business '$slug': $message");

        return redirect()->to('platform/admin/' . $slug . '/delete')->with('error', $message);
    }

    /**
     * Who is doing this, for the log. Named, not just an id: the id
     * means nothing to whoever reads the log a month from now.
     */
    private function actor(): string
    {
        $account = $this->account->getLoggedInAccount();

        return $account === null
            ? 'an unidentified session'
            : $account->email . ' (platform_account #' . $account->id . ')';
    }
}
