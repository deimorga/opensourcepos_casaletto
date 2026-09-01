<?php

namespace App\Controllers;

use App\Libraries\Platform_business_pass;
use App\Libraries\TenantConfigProfile;
use App\Libraries\TenantProvisioner;
use App\Models\PlatformActivity;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App as AppConfig;
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
 *
 * ENTREGA 2: THE GUARD AND THE LOG BOTH MOVED
 *
 * The admin check used to live in this constructor. It now comes from Platform_Controller, which
 * every screen of the console shares -- including the one that answers a pending second factor,
 * which this class had no way of knowing about and would have bounced back to the login form
 * forever.
 *
 * And what used to be only a `log_message('critical', ...)` line is now also a row in
 * `platform_activity_log`. The critical lines stay where they are, written BEFORE the destructive
 * call, because they are the only trace that survives a process which dies halfway through a DROP.
 * The activity row is written AFTER, and only on success: a log entry announcing a deletion that
 * was rolled back is worse than no entry at all. The two answer different questions and neither
 * replaces the other.
 */
class PlatformAdmin extends Platform_Controller
{
    private TenantProvisioner $provisioner;

    public function __construct()
    {
        parent::__construct();

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
        $names   = [];
        $urls    = [];

        foreach ($tenants as $tenant) {
            $adopted[$tenant->slug] = $this->provisioner->isAdopted($tenant);
            $names[$tenant->slug]   = $this->businessName($tenant);
            $urls[$tenant->slug]    = $this->tenantUrl((string) $tenant->slug);
        }

        return view('platform/admin/index', [
            'title'   => lang('Platform.admin_panel_title'),
            'nav'     => 'businesses',
            'tenants' => $tenants,
            'adopted' => $adopted,
            'names'   => $names,
            'urls'    => $urls,
        ]);
    }

    public function newTenant(): string
    {
        return view('platform/admin/form', ['error' => null]);
    }

    public function create(): RedirectResponse|string
    {
        $slug        = (string) $this->request->getPost('slug');
        $companyName = (string) $this->request->getPost('company_name');

        try {
            $result = $this->provisioner->create($slug, $companyName);
        } catch (RuntimeException $e) {
            return view('platform/admin/form', ['error' => $e->getMessage()]);
        }

        // The generated admin password is NOT in the detail, and must never be: this table is read
        // by people and kept forever, while that password is meant to be shown once and changed.
        $this->logActivity(
            PlatformActivity::TENANT_CREATED,
            PlatformActivity::TARGET_TENANT,
            (string) $result['slug'],
            ['company_name' => $companyName, 'profile' => TenantConfigProfile::ID],
        );

        // A la ficha del negocio, con la contraseña ya a la vista, y NO al listado con la
        // contraseña dentro de un mensaje flash. Dos razones, y las dos importan:
        //
        // 1. Ese mensaje se guarda en la sesión, que en esta consola vive en una tabla de
        //    `platform_control`: la contraseña del cliente acababa escrita en claro en nuestra base
        //    de datos hasta la siguiente petición. Aquí solo existe en el cuerpo de una respuesta.
        // 2. La frase decía «no queda visible de nuevo», que desde esta entrega es falso. La ficha
        //    la vuelve a mostrar mientras el cliente no la haya cambiado, y ese es justamente el
        //    miedo que D5 vino a quitar.
        return redirect()->to('platform/admin/' . rawurlencode((string) $result['slug']) . '?reveal=1');
    }

    /**
     * La ficha del negocio (§6.3): dónde se gestiona un cliente en concreto.
     *
     * LA CONTRASEÑA SE MUESTRA CON UN CLIC, NO SIEMPRE
     *
     * El estado se calcula siempre -- hay que ir a preguntarle al negocio si su hash sigue siendo el
     * nuestro, y esa comprobación es la que borra la copia cuando el cliente ya la cambió -- pero el
     * texto en claro solo viaja en la respuesta cuando alguien lo pidió con `?reveal=1`. Abrir la
     * ficha para mirar el estado de un negocio no debería dejar una contraseña en pantalla, ni en la
     * captura que alguien haga de ella.
     *
     * Y es una GET y no una POST a propósito: mostrar no cambia nada, así que refrescar la página
     * vuelve a mostrar exactamente lo mismo. La dirección lleva la marca, nunca el secreto.
     */
    public function show(string $slug): string
    {
        $tenant = $this->findTenant($slug);

        return view('platform/admin/detail', [
            'title'      => lang('Platform.business_title', [$this->businessName($tenant)]),
            'nav'        => 'businesses',
            'tenant'     => $tenant,
            'name'       => $this->businessName($tenant),
            'url'        => $this->tenantUrl($slug),
            'adopted'    => $this->provisioner->isAdopted($tenant),
            'credential' => $this->provisioner->adminCredential($slug),
            'reveal'     => $this->request->getGet('reveal') === '1',
            'settings'   => $this->currentSettings($slug),
            'wiring'     => TenantConfigProfile::WIRING,
            'profile_id' => TenantConfigProfile::ID,
        ]);
    }

    /**
     * Entrar al punto de venta de un negocio desde la consola, sin volver a teclear nada.
     *
     * Antes «Abrir» era un enlace a la dirección del negocio, y ahí esperaba el FORMULARIO de
     * entrada: la sesión de consola y su segundo factor, ya superados, no servían de nada y había
     * que repetir correo, contraseña y código para llegar a un sitio al que ya se tenía derecho.
     *
     * Ahora se emite un pase de un solo uso y sesenta segundos --ver `Platform_business_pass`-- y se
     * redirige con él. El negocio lo canjea y abre la sesión de soporte.
     *
     * Se registra la ENTRADA, no solo lo que se haga después: saber que alguien entró a la caja de
     * un cliente es un dato por sí mismo, aunque no tocara nada.
     */
    public function enter(string $slug): RedirectResponse
    {
        $tenant  = $this->findTenant($slug);
        $account = $this->account->getLoggedInAccount();

        // El pase presupone el segundo factor, no lo sustituye: si esta cuenta no lo tiene, entrar
        // por aquí sería la puerta de atrás de la regla que la entrada por URL ya aplica.
        if ($account->totp_enabled_at === null) {
            return redirect()->to('platform/admin/' . rawurlencode($slug))
                ->with('error', lang('Platform.enter_needs_second_factor'));
        }

        $pase = (new Platform_business_pass())->mint((int) $account->id, (int) $tenant->id);

        $this->logActivity(
            PlatformActivity::SUPPORT_ENTERED,
            PlatformActivity::TARGET_TENANT,
            $slug,
        );

        return redirect()->to($this->tenantUrl($slug) . 'login/pass?t=' . rawurlencode($pase));
    }

    /**
     * Confirmación del restablecimiento.
     *
     * NO se pide escribir el slug, a diferencia de la baja. La baja destruye y no se deshace; esto
     * se deshace haciéndolo otra vez, y la pantalla existe para llegar a un cliente que está al
     * teléfono sin poder entrar a su negocio. Poner ahí un campo que teclear alarga esa llamada sin
     * evitar ningún daño.
     *
     * Lo que sí hace la pantalla es NOMBRAR: el negocio, su base de datos y el usuario exacto al que
     * se le va a cambiar la contraseña. Casaletto es el caso incómodo -- su usuario no es `admin` y
     * sus empleados son personas reales -- y por eso el usuario es un campo, relleno con el que la
     * plataforma tenga guardado, y no una suposición del código.
     */
    public function confirmResetPassword(string $slug): string
    {
        $tenant = $this->findTenant($slug);

        return view('platform/admin/confirm_reset_password', [
            'title'    => lang('Platform.reset_password_title'),
            'nav'      => 'businesses',
            'tenant'   => $tenant,
            'username' => trim((string) ($tenant->admin_username ?? '')) !== ''
                ? (string) $tenant->admin_username
                : TenantProvisioner::DEFAULT_ADMIN_USERNAME,
        ]);
    }

    /**
     * Restablece la contraseña del administrador de un negocio (D5).
     *
     * Redirige a la ficha con la contraseña a la vista en vez de pintarla aquí. Es lo que hace que
     * refrescar la página no genere otra contraseña distinta: la consola no regenera el testigo CSRF
     * en cada envío, así que una respuesta HTML servida directamente desde este POST se podría
     * reenviar con F5 y dejar al cliente con una contraseña que ya no es la que se le dictó.
     */
    public function resetPassword(string $slug): RedirectResponse
    {
        $this->findTenant($slug);

        $username = trim((string) $this->request->getPost('username'));

        try {
            $result = $this->provisioner->resetAdminPassword($slug, $username === '' ? null : $username);
        } catch (RuntimeException $e) {
            return redirect()->to('platform/admin/' . rawurlencode($slug) . '/reset-password')
                ->with('error', $e->getMessage());
        }

        // La contraseña NO va en el detalle. Esta tabla la leen personas y se guarda para siempre;
        // lo que hace falta saber dentro de un año es a quién se le restableció, no cuál fue.
        $this->logActivity(
            PlatformActivity::TENANT_PASSWORD_RESET,
            PlatformActivity::TARGET_TENANT,
            $slug,
            ['username' => $result['username']],
        );

        // La plataforma no pudo guardar su copia -- casi siempre, el esquema de control por detrás
        // del código. La contraseña del negocio YA cambió, así que el cliente está fuera desde este
        // instante y esta es la única vez que alguien la va a ver: la ficha no podrá enseñarla.
        //
        // Va por `flashdata`, que se consume al leerse, y NO por el registro de actividad ni por el
        // log: los dos se guardan para siempre.
        if ($result['copy_saved'] === false) {
            return redirect()->to('platform/admin/' . rawurlencode($slug))
                ->with('error', lang('Platform.reset_password_uncopied', [$result['username'], $result['password']]));
        }

        return redirect()->to('platform/admin/' . rawurlencode($slug) . '?reveal=1')
            ->with('message', lang('Platform.reset_password_done', [$result['username']]));
    }

    /**
     * Lo que el negocio tiene hoy en las claves del perfil, o un arreglo vacío si no se le pudo
     * preguntar.
     *
     * Un negocio suspendido, o uno cuya base ya no está, no puede dejar sin abrir su propia ficha:
     * es precisamente la pantalla desde la que se le reactiva. La tabla de configuración se queda
     * sin dibujar y lo dice; lo demás sigue funcionando.
     *
     * @return array<string, string|null>
     */
    private function currentSettings(string $slug): array
    {
        try {
            return $this->provisioner->currentSettings($slug, array_keys(TenantConfigProfile::appConfig('')));
        } catch (RuntimeException $e) {
            log_message('error', "No se pudo leer la configuración del negocio '{$slug}': " . $e->getMessage());

            return [];
        }
    }

    /**
     * The tenant is looked up first, and a slug nobody registered is a 404 rather than a silent
     * redirect. setStatus() on an unknown slug updates nothing and says nothing, so without this
     * the activity log would gain a row claiming a business was suspended when no such business
     * exists -- a log that can lie about the easy cases is not worth reading on the hard ones.
     */
    public function suspend(string $slug): RedirectResponse
    {
        $this->findTenant($slug);
        $this->provisioner->setStatus($slug, 'suspended');
        $this->logActivity(PlatformActivity::TENANT_SUSPENDED, PlatformActivity::TARGET_TENANT, $slug);

        return redirect()->to('platform/admin');
    }

    public function activate(string $slug): RedirectResponse
    {
        $this->findTenant($slug);
        $this->provisioner->setStatus($slug, 'active');
        $this->logActivity(PlatformActivity::TENANT_ACTIVATED, PlatformActivity::TARGET_TENANT, $slug);

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

        // Still its own standalone page rather than the console layout, like the new-business form
        // above. Both were written and reviewed for Entrega 1 and are waiting to be certified on
        // staging; rewriting their markup now would put that certification back to the start for
        // no gain. Converting them is a follow-up, not a prerequisite.
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
     * TWO RECORDS, AND BOTH ARE NEEDED
     *
     * The critical log lines are written BEFORE the destructive call, so
     * they survive a process that dies halfway through a DROP -- which
     * is exactly the case where somebody will want to know what was
     * being attempted. They say "is deleting", in the present, because
     * at that point it has not happened yet.
     *
     * The activity rows (section 6.5) are written AFTER, and only if the
     * teardown returned. A row in a table people read that announces a
     * deletion which was then rolled back is worse than no row: the
     * critical log is a technical trace, this one is the answer to "what
     * did we change?".
     *
     * A refusal produces neither: none of the twelve actions in
     * App\Models\PlatformActivity covers "somebody tried and mistyped",
     * and inventing one here would mean adding a language key, which
     * this Entrega closed on purpose. The refusal keeps its critical log
     * line, which is where it has been since Entrega 1.
     */
    public function delete(string $slug): ResponseInterface
    {
        $tenant = $this->findTenant($slug);

        if ($this->provisioner->isAdopted($tenant)) {
            return $this->refuse($slug, lang('Platform.delete_refused_adopted', [$slug, $tenant->db_name]));
        }

        if (! $this->typedCorrectly((string) $tenant->slug, $this->request->getPost('confirm_slug'))) {
            return $this->refuse($slug, lang('Platform.delete_refused_slug', [$slug]));
        }

        $dropSchema = $this->request->getPost('drop_schema') === '1';

        if ($dropSchema && ! $this->typedCorrectly((string) $tenant->db_name, $this->request->getPost('confirm_db_name'))) {
            return $this->refuse($slug, lang('Platform.delete_refused_db_name', [$tenant->db_name]));
        }

        $who  = $this->actor();
        $when = date('c');

        log_message('critical', "PLATFORM DELETE [{$when}] {$who} is deleting business '{$slug}' (schema '{$tenant->db_name}').");

        if ($dropSchema) {
            log_message('critical', "PLATFORM DROP SCHEMA [{$when}] {$who} is destroying database '{$tenant->db_name}', of business '{$slug}'. This cannot be undone.");
        }

        try {
            $this->provisioner->delete($slug, $dropSchema);
        } catch (RuntimeException $e) {
            log_message('critical', "PLATFORM DELETE FAILED [{$when}] {$who} tried to delete business '{$slug}': " . $e->getMessage());

            return redirect()->to('platform/admin')->with('error', $e->getMessage());
        }

        $this->logActivity(
            PlatformActivity::TENANT_DELETED,
            PlatformActivity::TARGET_TENANT,
            $slug,
            ['db_name' => (string) $tenant->db_name, 'schema_dropped' => $dropSchema],
        );

        // A separate row, not just a flag on the one above. Unregistering a business and destroying
        // its database are two decisions on this screen -- the second has its own confirmation
        // field -- and the log has to be able to answer "when did we destroy a database?" without
        // reading the detail of every deletion.
        if ($dropSchema) {
            $this->logActivity(
                PlatformActivity::TENANT_SCHEMA_DROPPED,
                PlatformActivity::TARGET_TENANT,
                $slug,
                ['db_name' => (string) $tenant->db_name],
            );
        }

        return redirect()->to('platform/admin')->with('message', lang('Platform.deleted', [$slug]));
    }

    /**
     * Cómo se llama este negocio en pantalla. El nombre guardado si lo hay, y el slug si no --
     * Casaletto y Paraíso se dieron de alta antes de que la columna existiera, e inventarles un
     * nombre desde aquí sería peor que enseñar el que ya se conoce.
     */
    private function businessName(object $tenant): string
    {
        $name = trim((string) ($tenant->company_name ?? ''));

        return $name !== '' ? $name : (string) $tenant->slug;
    }

    /**
     * La dirección pública del negocio, construida con el comodín configurado.
     *
     * ES UNA COPIA DE PlatformLogin::tenantUrl(), Y ESO ESTÁ ANOTADO A PROPÓSITO
     *
     * Lo correcto sería que las dos leyeran de un sitio común -- PlatformContext es el sitio -- pero
     * ese movimiento obliga a editar PlatformLogin, que esta entrega tiene cerrado porque lo está
     * tocando el otro lado del trabajo. Duplicar diez líneas y dejar escrito dónde está la otra
     * copia es menos malo que un conflicto en el archivo del que depende entrar a la consola.
     * Unificarlas es una tarea de una línea en cuanto las dos entregas estén juntas.
     */
    private function tenantUrl(string $slug): string
    {
        $appConfig = config(AppConfig::class);
        $wildcard  = $appConfig->allowedHostnameWildcards[0] ?? null;

        if ($wildcard === null) {
            return base_url();
        }

        return ($appConfig->https_on ? 'https' : 'http') . '://' . $slug . $wildcard . '/';
    }

    /**
     * @throws PageNotFoundException when no business carries that slug.
     */
    private function findTenant(string $slug): object
    {
        $tenant = db_connect('platform')->table('tenants')->where('slug', $slug)->get()->getRow();

        if ($tenant === null) {
            throw PageNotFoundException::forPageNotFound("No business is registered under the slug '{$slug}'.");
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
        log_message('critical', 'PLATFORM DELETE REFUSED [' . date('c') . '] ' . $this->actor() . " on business '{$slug}': {$message}");

        return redirect()->to('platform/admin/' . $slug . '/delete')->with('error', $message);
    }

    /**
     * Who is doing this, for the critical log. Named, not just an id:
     * the id means nothing to whoever reads the log a month from now.
     *
     * Reads Platform_Controller's copy of the row rather than asking the
     * session again. Past that constructor there is always somebody, so
     * there is no longer an "unidentified session" branch to write.
     */
    private function actor(): string
    {
        $account = $this->currentAccount();

        return $account->email . ' (platform_account #' . $account->id . ')';
    }
}
