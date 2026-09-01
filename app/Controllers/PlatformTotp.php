<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\PlatformTotp as TotpLibrary;
use App\Models\PlatformActivity;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Alta, confirmación y baja del segundo factor (D11), siempre para la cuenta que está en sesión.
 *
 * Archivo aparte de `PlatformAccounts` a propósito: son dos mitades de la Entrega 2 que se
 * escriben en paralelo, y un solo archivo tocado por los dos es un conflicto garantizado en el
 * único sitio donde no se puede resolver a ojo.
 *
 * ------------------------------------------------------------------------------------------------
 * LA REGLA QUE ORDENA TODA LA PANTALLA
 * ------------------------------------------------------------------------------------------------
 *
 * **`totp_enabled_at` no se escribe JAMÁS sin haber verificado antes un código real.** Es lo que
 * impide el único desenlace verdaderamente malo de esta pantalla: dejar encendido un factor que no
 * funciona. Quien eso le pase queda fuera de su propia consola y sin nadie a quien pedírselo -- hoy
 * hay un solo superadministrador de verdad--, y no hay canal de correo ni de SMS por donde
 * rescatarlo. Por eso el alta son dos peticiones y no una: la primera acuña el secreto, la segunda
 * exige la prueba de que llegó bien al teléfono, y solo esa segunda escribe en la cuenta.
 *
 * Las dos columnas se escriben JUNTAS, en el mismo `update()`. Guardar el secreto en el alta y la
 * fecha en la confirmación dejaría, entre las dos, una cuenta con secreto y sin factor -- inocuo
 * hoy, pero es exactamente el estado intermedio que alguien acabaría interpretando como «ya está».
 *
 * ------------------------------------------------------------------------------------------------
 * EL SECRETO ENTRE LAS DOS PETICIONES
 * ------------------------------------------------------------------------------------------------
 *
 * Vive en la sesión, **cifrado**, no en claro. La sesión de la consola es el manejador de base de
 * datos (`platform_sessions`, ver Config\Session): en claro, el secreto quedaría legible en una
 * segunda tabla además de en `platform_accounts`, y la de sesiones no la mira nadie. Cifrarlo
 * cuesta una llamada y hace que solo exista en claro dentro de la petición que lo enseña.
 *
 * No se guarda en la cuenta hasta confirmar. Si el operador abandona a mitad, no queda nada.
 *
 * ------------------------------------------------------------------------------------------------
 * SIN CÓDIGO QR, A PROPÓSITO
 * ------------------------------------------------------------------------------------------------
 *
 * No hay librería de QR en el repositorio y no se añade una por una pantalla que se usa una vez por
 * persona. La pantalla muestra **la clave en base32 y la URI `otpauth://`**, las dos copiables:
 * Contraseñas de Apple, 1Password y Bitwarden aceptan la clave tecleada, y la URI pegada abre la
 * entrada ya rellena en las que lo soportan. La clave sale en grupos de cuatro porque son 32
 * caracteres que alguien va a teclear mirando una pantalla.
 */
class PlatformTotp extends Platform_Controller
{
    /**
     * Dónde espera el secreto entre el POST que lo acuña y el POST que lo confirma. Con el mismo
     * prefijo `platform_` que las otras dos claves de sesión de la consola, para que se vean
     * juntas y nadie las confunda con las del punto de venta.
     */
    private const ENROLMENT_KEY = 'platform_totp_enrolment';

    /**
     * Qué apartado marca como activo `platform/console_layout`. La envoltura es de la otra mitad de
     * la Entrega 2 y sus claves están escritas allí: la de esta pantalla es `totp`.
     */
    private const NAV = 'totp';

    private TotpLibrary $totp;

    public function __construct()
    {
        parent::__construct();

        $this->totp = new TotpLibrary();
    }

    /**
     * El estado del factor para esta cuenta, y la única acción que tiene sentido desde él.
     */
    public function index(): string
    {
        return view('platform/accounts/totp', $this->stateForView());
    }

    /**
     * Acuña un secreto y enseña la pantalla de alta. POST porque cambia estado -- ver el comentario
     * de la ruta: un GET que acuña secretos lo dispara cualquier prefetch del navegador.
     */
    public function enroll(): RedirectResponse|string
    {
        if ($this->isEnabled()) {
            // Volver a darse de alta con el factor encendido sustituiría en silencio un secreto que
            // funciona. Quien cambie de teléfono lo apaga primero, que ya le pide la contraseña.
            return redirect()->to('platform/accounts/totp');
        }

        $secret = $this->totp->generateSecret();
        session()->set(self::ENROLMENT_KEY, $this->totp->encryptSecret($secret));

        return $this->setupScreen($secret);
    }

    /**
     * Enciende el factor -- y solo si el código escrito demuestra que el teléfono lo tiene bien.
     */
    public function confirm(): RedirectResponse|string
    {
        if ($this->isEnabled()) {
            return redirect()->to('platform/accounts/totp');
        }

        $secret = $this->totp->decryptSecret(session()->get(self::ENROLMENT_KEY));

        if ($secret === null) {
            // La sesión caducó, o esto llegó sin haber pasado por enroll(). No hay secreto que
            // confirmar y no se inventa uno: se vuelve al principio.
            return redirect()->to('platform/accounts/totp');
        }

        if (! $this->totp->verify($secret, (string) $this->request->getPost('code'))) {
            // El secreto SIGUE en sesión: quien se equivocó de dígito no debería tener que volver
            // a teclear 32 caracteres en el teléfono.
            return $this->setupScreen($secret, lang('Platform.totp_confirm_failed'));
        }

        $this->account->update($this->currentAccountId(), [
            'totp_secret'     => $this->totp->encryptSecret($secret),
            'totp_enabled_at' => date('Y-m-d H:i:s'),
        ]);

        session()->remove(self::ENROLMENT_KEY);

        $this->logActivity(
            PlatformActivity::ACCOUNT_TOTP_ENABLED,
            PlatformActivity::TARGET_ACCOUNT,
            (string) $this->currentAccountId(),
            ['email' => (string) $this->currentAccount()->email],
        );

        // Los códigos de rescate se entregan aquí y en ningún otro momento: encender el factor sin
        // dárselos deja a la cuenta con una única llave, la del teléfono.
        return $this->codesScreen(
            $this->account->issueRecoveryCodes($this->currentAccountId()),
            lang('Platform.totp_enabled'),
        );
    }

    /**
     * Apaga el factor. Exige la contraseña, porque apagarlo deja la cuenta detrás de una contraseña
     * sola y es justo lo que querría hacer quien se encuentre una sesión abierta.
     */
    public function disable(): RedirectResponse
    {
        if (! $this->isEnabled()) {
            return redirect()->to('platform/accounts/totp');
        }

        $password = (string) $this->request->getPost('password');

        if (! password_verify($password, (string) $this->currentAccount()->password_hash)) {
            return redirect()->to('platform/accounts/totp')->with('error', lang('Platform.totp_disable_failed'));
        }

        $this->account->update($this->currentAccountId(), [
            'totp_secret'     => null,
            'totp_enabled_at' => null,
        ]);

        $this->logActivity(
            PlatformActivity::ACCOUNT_TOTP_DISABLED,
            PlatformActivity::TARGET_ACCOUNT,
            (string) $this->currentAccountId(),
            ['email' => (string) $this->currentAccount()->email],
        );

        // Los códigos de rescate se quedan donde están. Solo se gastan en la pantalla del reto, a
        // la que ya no se llega con el factor apagado, así que no abren nada; y volver a darse de
        // alta los revoca de todas formas, porque issueRecoveryCodes() borra la tanda anterior
        // antes de emitir la nueva. Borrarlos aquí exigiría alcanzar la tabla desde el controlador
        // sin ganar nada.
        return redirect()->to('platform/accounts/totp')->with('message', lang('Platform.totp_disabled'));
    }

    /**
     * Una tanda nueva. La anterior deja de servir en el mismo acto -- si se regeneran porque la
     * hoja se perdió, dejar viva la vieja no habría revocado nada.
     */
    public function regenerateRecoveryCodes(): RedirectResponse|string
    {
        if (! $this->isEnabled()) {
            return redirect()->to('platform/accounts/totp');
        }

        return $this->codesScreen(
            $this->account->issueRecoveryCodes($this->currentAccountId()),
            lang('Platform.recovery_codes_regenerated'),
        );
    }

    // ===================== Interno =====================

    private function isEnabled(): bool
    {
        return $this->currentAccount()->totp_enabled_at !== null;
    }

    private function setupScreen(string $secret, ?string $error = null): string
    {
        return view('platform/accounts/totp_setup', [
            'title'          => lang('Platform.totp_enroll_title'),
            'nav'            => self::NAV,
            'secret_display' => $this->totp->formatSecretForDisplay($secret),
            'uri'            => $this->totp->provisioningUri($secret, (string) $this->currentAccount()->email),
            'issuer'         => $this->totp->issuer(),
            'email'          => (string) $this->currentAccount()->email,
            'error'          => $error,
        ]);
    }

    /**
     * @param list<string> $codes en claro. El ÚNICO momento en que son legibles: se guardan con
     *                            hash y esta pantalla no se puede volver a pedir.
     */
    private function codesScreen(array $codes, string $message): string
    {
        return view('platform/accounts/totp_codes', [
            'title'   => lang('Platform.recovery_codes_title'),
            'nav'     => self::NAV,
            'codes'   => $codes,
            'message' => $message,
        ]);
    }

    private function stateForView(): array
    {
        return [
            'title'           => lang('Platform.totp_title'),
            'nav'             => self::NAV,
            'enabled'         => $this->isEnabled(),
            'enabled_at'      => $this->currentAccount()->totp_enabled_at,
            'codes_remaining' => $this->isEnabled()
                ? $this->account->unusedRecoveryCodeCount($this->currentAccountId())
                : 0,
            'email' => (string) $this->currentAccount()->email,
        ];
    }
}
