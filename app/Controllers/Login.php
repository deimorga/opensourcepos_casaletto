<?php

namespace App\Controllers;

use App\Libraries\MY_Migration;
use App\Libraries\Platform_business_entry;
use App\Libraries\PlatformTotp;
use App\Models\Employee;
use App\Models\PlatformAccount;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Model;
use Config\OSPOS;
use Config\Services;

/**
 * @property employee employee
 */
class Login extends BaseController
{
    public Model $employee;

    /**
     * @return RedirectResponse|string
     */
    public function index(): string|RedirectResponse
    {
        $this->employee = model(Employee::class);
        if (!$this->employee->is_logged_in()) {
            $migration = new MY_Migration(config('Migrations'));
            // Scope to the App namespace, same reason as the fix in
            // app/Events/Load_config.php: since Fase 3 registered the
            // Platform namespace, an unscoped findMigrations() here
            // would report Platform's (numerically higher) migrations
            // as the "latest" available, which App's own schema could
            // never match.
            $migration->setNamespace('App');
            $config = config(OSPOS::class)->settings;

            $gcaptcha_enabled = array_key_exists('gcaptcha_enable', $config)
                ? $config['gcaptcha_enable']
                : false;

            $migration->migrate_to_ci4();

            $validation = Services::validation();

            $data = [
                'has_errors'       => false,
                'is_new_install'   => !(MY_Migration::get_current_version()),
                'is_latest'        => $migration->is_latest(),
                'latest_version'   => $migration->get_latest_migration(),
                'gcaptcha_enabled' => $gcaptcha_enabled,
                'config'           => $config,
                'validation'       => $validation
            ];

            if ($this->request->getMethod() !== 'POST') {
                return view('login', $data);
            }

            if (!$data['is_latest'] || $data['is_new_install']) {
                set_time_limit(3600);

                $migration->setNamespace('App')->latest();
                return redirect()->to('login');
            }

            $rules = ['username' => 'required|login_check[data]'];
            $messages = [
                'username' => [
                    'required'    => lang('Login.required_username'),
                    'login_check' => lang('Login.invalid_username_and_password'),
                ]
            ];

            if (!$this->validate($rules, $messages)) {
                // LA CREDENCIAL DE PLATAFORMA SE INTENTA AQUÍ, Y NO ANTES.
                //
                // El login del negocio ya corrió y ya dijo que no. Eso es deliberado y es lo que
                // hace que esta entrega no pueda dejar fuera a nadie de Casaletto: la puerta de sus
                // empleados es exactamente la de ayer, corre primero, y esto solo ocurre cuando
                // aquella falló. Un fallo aquí niega una entrada nuestra, nunca la de un empleado.
                //
                // Ver App\Libraries\Platform_business_entry.
                $entrada = $this->intentarCredencialDePlataforma($data);

                if ($entrada !== null) {
                    return $entrada;
                }

                $data['has_errors'] = !empty($validation->getErrors());

                return view('login', $data);
            }
        }

        return redirect()->to('home');
    }

    /**
     * ¿Lo que se acaba de teclear es una credencial de plataforma?
     *
     * Devuelve `null` cuando no lo es --o cuando esto no es el sitio de un negocio-- para que la
     * pantalla siga diciendo lo de siempre: «usuario o contraseña incorrectos», sin revelar que
     * existe otro tipo de credencial ni cuál de las dos falló.
     *
     * Los cuatro desenlaces que sí se atienden:
     *
     * - **Falta el segundo factor**: a la pantalla del código. Es el camino normal de una cuenta
     *   nuestra, porque entrar a un negocio EXIGE tener el segundo factor puesto.
     * - **Cuenta sin segundo factor**: se niega y se le dice que lo active. Si bastara con no
     *   activarlo, el candado se saltaría no poniéndoselo.
     * - **Frenada**: tres intentos fallidos frenan dos horas, y el freno es el mismo de la consola
     *   porque vive en el modelo. Se dice, no se disimula: quien está frenado necesita saberlo.
     * - **Credencial que no es nuestra**: null, y sigue el camino de siempre.
     *
     * @param array<string, mixed> $data lo que espera la vista del login.
     * @return RedirectResponse|string|null
     */
    private function intentarCredencialDePlataforma(array &$data): string|RedirectResponse|null
    {
        $entrada   = Platform_business_entry::create();
        $resultado = $entrada->attempt(
            (string)$this->request->getPost('username'),
            (string)$this->request->getPost('password'),
        );

        if ($resultado === null) {
            return null;
        }

        if (Platform_business_entry::needsSecondFactor($resultado)) {
            return redirect()->to('login/totp');
        }

        if (Platform_business_entry::isSuccess($resultado)) {
            $data['platform_error'] = $entrada->refuseWithoutSecondFactor();
            $data['has_errors']     = true;

            return view('login', $data);
        }

        if (Platform_business_entry::isLocked($resultado)) {
            $data['platform_error'] = lang('Login.platform_account_locked');
            $data['has_errors']     = true;

            return view('login', $data);
        }

        return null;
    }

    /**
     * La pantalla del segundo factor, del lado del punto de venta.
     *
     * Solo existe mientras haya una entrada a medias: sin la contraseña correcta dada antes, no hay
     * cuenta pendiente y esto redirige a la entrada. Así la pantalla no es alcanzable por su
     * dirección ni sirve para averiguar si un correo existe.
     *
     * Acepta el código de la aplicación **o** uno de rescate, igual que la consola: quien perdió el
     * teléfono en la caja de un cliente necesita la misma salida que en su propia consola.
     *
     * @return RedirectResponse|string
     */
    public function totp(): string|RedirectResponse
    {
        $entrada   = Platform_business_entry::create();
        $pendiente = $entrada->pendingAccountId();

        if ($pendiente === null) {
            return redirect()->to('login');
        }

        $data = ['config' => config(OSPOS::class)->settings, 'error' => null];

        if ($this->request->getMethod() !== 'POST') {
            return view('login_totp', $data);
        }

        $codigo = trim((string)$this->request->getPost('code'));

        if (! $this->verificarSegundoFactor($pendiente, $codigo)) {
            // Un mensaje solo para los dos casos --código malo y código de rescate ya usado--
            // porque distinguirlos diría cuál de los dos se acertó a medias.
            $data['error'] = lang('Login.platform_second_factor_invalid');

            return view('login_totp', $data);
        }

        $rechazo = $entrada->finish($pendiente);

        if ($rechazo !== null) {
            $data['error'] = $rechazo;

            return view('login_totp', $data);
        }

        return redirect()->to('home');
    }

    /**
     * El código de la aplicación, o uno de rescate de un solo uso.
     *
     * El de rescate se intenta DESPUÉS: `consumeRecoveryCode()` lo marca como usado, así que
     * probarlo primero gastaría uno cada vez que alguien teclea mal el de seis dígitos.
     */
    private function verificarSegundoFactor(int $accountId, string $codigo): bool
    {
        $cuenta = model(PlatformAccount::class)->find($accountId);
        $totp   = new PlatformTotp();
        $secreto = $totp->decryptSecret($cuenta->totp_secret ?? null);

        if ($secreto !== null && $totp->verify($secreto, $codigo)) {
            return true;
        }

        return model(PlatformAccount::class)->consumeRecoveryCode($accountId, $codigo);
    }

    public function migrate(): ResponseInterface
    {
        try {
            $migration = new MY_Migration(config('Migrations'));
            $migration->migrate_to_ci4();

            set_time_limit(3600);
            $migration->setNamespace('App')->latest();

            return $this->response->setJSON([
                'success' => true,
                'message' => 'Migration completed successfully'
            ]);

        } catch (\Exception $e) {
            log_message('error', 'Migration failed: ' . $e->getMessage());

            return $this->response->setJSON([
                'success' => false,
                'message' => 'Migration failed: ' . $e->getMessage()
            ])->setStatusCode(500);
        }
    }
}
