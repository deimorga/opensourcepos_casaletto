<?php

namespace App\Controllers;

use App\Libraries\PlatformLoginThrottle;
use App\Libraries\PlatformTotp as TotpLibrary;
use App\Models\PlatformAccount;
use App\Models\PlatformActivity;
use App\Models\PlatformLoginOutcome;
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
    /**
     * La misma cadena que guarda `PlatformAccount` bajo su constante privada `PENDING_KEY`.
     *
     * Escrita otra vez aquí porque el modelo está congelado en esta entrega y no expone forma de
     * retirar la marca sin ascenderla. Es deuda pequeña y anotada: el sitio natural de esto es un
     * `abandonSecondFactor()` en el modelo, junto a `completeSecondFactor()`.
     */
    private const PENDING_SESSION_KEY = 'platform_pending_account_id';

    private PlatformAccount $account;

    public function __construct()
    {
        $this->account = model(PlatformAccount::class);
    }

    public function index(): RedirectResponse|string
    {
        if ($this->account->isLoggedIn()) {
            return $this->redirectAfterLogin();
        }

        $data = ['has_errors' => false, 'error' => null];

        if ($this->request->getMethod() !== 'POST') {
            return view('platform/login', $data);
        }

        $email    = (string) $this->request->getPost('email');
        $password = (string) $this->request->getPost('password');

        $result = $this->account->login($email, $password);

        if ($result->outcome === PlatformLoginOutcome::Success) {
            return $this->redirectAfterLogin();
        }

        if ($result->outcome === PlatformLoginOutcome::SecondFactorRequired) {
            // Nothing is authenticated yet: the account is only PENDING, and the challenge screen
            // is the only thing that can promote it. That screen belongs to Entrega 2; its route
            // is already reserved in app/Config/Routes.php.
            return redirect()->to('platform/login/totp');
        }

        // D6 records the moment the counter trips, because that is the modification: the account
        // stays shut until somebody acts. Every later refusal inside the same two-hour window is
        // not a new fact, which is exactly what $justLocked separates.
        if ($result->outcome === PlatformLoginOutcome::Locked && $result->justLocked && $result->account !== null) {
            model(PlatformActivity::class)->record(
                PlatformActivity::ACCOUNT_LOCKED,
                PlatformActivity::TARGET_ACCOUNT,
                (string) $result->account->id,
                ['email' => (string) $result->account->email],
                $result->account,
            );
        }

        // Locked and InvalidCredentials render the SAME message, deliberately. D8 requires that the
        // error not reveal whether the email exists, and an address that answers "too many
        // attempts" while an unknown one answers "wrong password" has just confirmed itself.
        // Platform.invalid_credentials names the two-hour brake, so it is true in both cases and
        // tells nobody which one they are in.
        $data['has_errors'] = true;
        $data['error']      = lang('Platform.invalid_credentials');

        return view('platform/login', $data);
    }

    /**
     * El reto del segundo factor (D11): la segunda mitad de lo que empezó `index()`.
     *
     * ------------------------------------------------------------------------------------------
     * LOS DOS ESTADOS, Y POR QUÉ LA CONTRASEÑA CORRECTA NO ABRE NADA
     * ------------------------------------------------------------------------------------------
     *
     * Quien llega aquí acertó la contraseña y **no está autenticado**: su sesión lleva
     * `platform_pending_account_id` y NO lleva `platform_account_id`. Son dos claves distintas y no
     * una con una bandera, porque el fallo de equivocarse ahí es silencioso y total -- un visitante
     * a medias que sostuviera la misma llave que uno entero simplemente estaría dentro, y ninguna
     * prueba que pregunte «¿hay sesión?» lo notaría. Solo `completeSecondFactor()` asciende de una
     * a la otra, y solo desde aquí.
     *
     * El identificador de sesión se regenera DOS veces en el camino completo: al pasar la
     * contraseña (dentro de `PlatformAccount::login()`) y al pasar el factor (dentro de
     * `openSession()`). El nivel de privilegio cambia en los dos pasos, y un identificador fijado
     * sobre la víctima antes de empezar no debe sobrevivir a ninguno de ellos.
     *
     * ------------------------------------------------------------------------------------------
     * UN SOLO CAMPO PARA EL CÓDIGO Y PARA EL DE RESCATE
     * ------------------------------------------------------------------------------------------
     *
     * Se prueban los dos, siempre en ese orden y sin adivinar cuál es cuál por su forma. Adivinar
     * por la forma es la trampa que parece inofensiva: un código de rescate cuyos 16 caracteres
     * hexadecimales incluyan justo seis dígitos se leería como código de la aplicación y quedaría
     * rechazado sin llegar nunca a la tabla, precisamente el día en que alguien perdió el teléfono.
     *
     * ------------------------------------------------------------------------------------------
     * EL FRENO DE D8 TAMBIÉN AQUÍ
     * ------------------------------------------------------------------------------------------
     *
     * Y sobre las mismas dos columnas que el freno de la contraseña, porque D8 cuenta los intentos
     * fallidos SOBRE LA CUENTA, no sobre la pantalla. Sin esto, quien ya tenga la contraseña puede
     * recorrer el espacio de seis dígitos a fuerza de peticiones sin que nada se lo impida: el
     * freno de la contraseña no le estorba porque ya la pasó, y con ±1 período hay tres códigos
     * válidos a la vez. Al cerrarse, la marca de pendiente se retira y la vuelta es a la pantalla
     * de entrada con el mismo mensaje de siempre.
     */
    public function totp(): RedirectResponse|string
    {
        $pendingId = $this->account->pendingSecondFactorAccountId();

        if ($pendingId === null) {
            // O ya entró -- y entonces esta pantalla no le toca -- o la sesión se perdió por el
            // camino y hay que volver a empezar por la contraseña.
            return $this->account->isLoggedIn()
                ? $this->redirectAfterLogin()
                : redirect()->to('platform/login')->with('error', lang('Platform.totp_challenge_expired'));
        }

        $account = $this->account->find($pendingId);

        if ($account === null) {
            return $this->abandonChallenge(lang('Platform.totp_challenge_expired'));
        }

        if ($this->request->getMethod() !== 'POST') {
            return view('platform/login_2fa', ['error' => null]);
        }

        $throttle = new PlatformLoginThrottle(null, db_connect('platform'));

        if ($throttle->isLocked($account)) {
            return $this->abandonChallenge(lang('Platform.invalid_credentials'));
        }

        $throttle->forgetExpiredWindow($account);

        $code   = (string) $this->request->getPost('code');
        $totp   = new TotpLibrary();
        $secret = $totp->decryptSecret($account->totp_secret);

        if ($secret !== null && $totp->verify($secret, $code)) {
            $throttle->clear($account);
            $this->account->completeSecondFactor($pendingId);

            return $this->redirectAfterLogin();
        }

        // El recuento se toma ANTES de ascender la sesión: `completeSecondFactor()` regenera el
        // identificador, y la cuenta que se consulta después es la misma pero la petición ya no es
        // la misma sesión. Leerlo aquí es leerlo una sola vez y sin sorpresas.
        if ($this->account->consumeRecoveryCode($pendingId, $code)) {
            $remaining = $this->account->unusedRecoveryCodeCount($pendingId);
            $throttle->clear($account);
            $this->account->completeSecondFactor($pendingId);

            return $this->redirectAfterLogin()
                ->with('message', lang('Platform.totp_challenge_used_recovery', [$remaining]));
        }

        if ($throttle->registerFailure($account)) {
            // La misma línea de registro que escribe `index()` cuando el freno salta con la
            // contraseña, y por la misma razón: D6 registra la modificación -- la cuenta queda
            // cerrada -- y no cada intento.
            model(PlatformActivity::class)->record(
                PlatformActivity::ACCOUNT_LOCKED,
                PlatformActivity::TARGET_ACCOUNT,
                (string) $account->id,
                ['email' => (string) $account->email],
                $account,
            );

            return $this->abandonChallenge(lang('Platform.invalid_credentials'));
        }

        return view('platform/login_2fa', ['error' => lang('Platform.totp_challenge_failed')]);
    }

    /**
     * Deja de estar a medias: retira la marca de pendiente y devuelve a la pantalla de entrada.
     *
     * Sin esto, una cuenta que se cierra durante el reto se queda con la marca puesta, y
     * `Platform_Controller` la manda de vuelta al reto en cada página -- un bucle del que solo se
     * sale borrando las cookies.
     *
     * Se retira la marca y se regenera el identificador en vez de destruir la sesión entera, y no
     * por delicadeza: `destroy()` tira también el almacenamiento, y el mensaje que se pone justo
     * después no llegaría a escribirse en ninguna parte. Quien fuese devuelto a la pantalla de
     * entrada la vería en blanco, sin la menor idea de por qué. `regenerate(true)` destruye igual
     * los datos del identificador viejo, que es lo único que había que asegurar aquí.
     */
    private function abandonChallenge(string $error): RedirectResponse
    {
        session()->remove(self::PENDING_SESSION_KEY);
        session()->regenerate(true);

        return redirect()->to('platform/login')->with('error', $error);
    }

    /**
     * Shows the business selector for an owner linked to more than one
     * tenant. Direct hits with 0 or 1 tenant are redirected elsewhere by
     * redirectAfterLogin() before ever reaching this action.
     */
    public function selectIndex(): RedirectResponse|string
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
        $wildcard  = $appConfig->allowedHostnameWildcards[0] ?? null;

        if ($wildcard === null) {
            return base_url();
        }

        $scheme = $appConfig->https_on ? 'https' : 'http';

        return $scheme . '://' . $slug . $wildcard . '/';
    }
}
