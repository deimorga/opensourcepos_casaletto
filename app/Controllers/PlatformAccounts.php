<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\PlatformAccount;
use App\Models\PlatformActivity;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use RuntimeException;

/**
 * Superadministradores (§6.1 del funcional).
 *
 * NO ES UN CRUD GENÉRICO. La pantalla contesta una pregunta concreta -- «¿cuál de estas cuentas no
 * debería existir?» -- y todo lo que hay aquí existe para contestarla. Por eso no hay pantalla de
 * edición: una cuenta se crea, se desbloquea o se elimina. Cambiar el correo de una cuenta ajena o
 * ponerle otra contraseña sería, en la práctica, apoderarse de ella sin dejar rastro de que cambió
 * de manos.
 *
 * LA CUENTA HUÉRFANA SE DELATA SOLA
 *
 * `admin@ospos-saas.micronuba.net` se creó con `php spark platform:create-account --admin` y nadie
 * anotó su contraseña. Tiene poder para eliminar cualquier negocio junto con su base de datos.
 * Las dos columnas que la señalan son `created_by_account_id` (NULL: nadie la creó desde esta
 * consola) y `last_login_at` (NULL: nadie ha entrado nunca con ella). Ninguna de las dos por
 * separado prueba nada -- una cuenta recién creada tampoco tiene ingresos --; juntas sí. El
 * listado marca esa fila, y esa marca es el motivo de la pantalla, no un adorno.
 *
 * TODAS LAS SALVAGUARDAS SON DEL LADO DEL SERVIDOR
 *
 * Ni a sí mismo, ni al último administrador: las dos las decide PlatformAccount::deleteAccount()
 * dentro de una transacción, porque «contar y luego borrar» son dos sentencias y entre ellas cabe
 * otra petición. Lo que hace este controlador antes de llamarla es distinto y complementario:
 * exige que el correo se escriba a mano. Una casilla se marca en la fila equivocada; un correo hay
 * que leerlo primero. Los dos frenos hacen falta, y ninguno sustituye al otro.
 */
class PlatformAccounts extends Platform_Controller
{
    /**
     * Lo que dice `Platform.account_password_help`. Si cambia una, cambia la otra.
     */
    public const MIN_PASSWORD_LENGTH = 12;

    /**
     * El listado. Una sola consulta con la tabla unida a sí misma para resolver quién creó cada
     * cuenta: con diez filas un N+1 no se nota, pero un listado que pregunta por fila envejece mal
     * y aquí no cuesta nada evitarlo.
     */
    public function index(): string
    {
        $rows = db_connect('platform')->table('platform_accounts a')
            ->select('a.id, a.email, a.is_platform_admin, a.created_at, a.last_login_at')
            ->select('a.created_by_account_id, a.failed_login_count, a.failed_login_first_at, a.totp_enabled_at')
            ->select('c.email AS created_by_email')
            ->join('platform_accounts c', 'c.id = a.created_by_account_id', 'left')
            ->orderBy('a.is_platform_admin', 'DESC')
            ->orderBy('a.email')
            ->get()
            ->getResult();

        $adminCount = $this->account->countAdmins();
        $accounts   = [];

        foreach ($rows as $row) {
            $accounts[] = [
                'row'    => $row,
                'locked' => $this->isLocked($row),

                // Las dos señales juntas, nunca una sola: una cuenta recién creada tampoco tiene
                // ingresos, y la primera cuenta de la plataforma tuvo que nacer en la terminal.
                'orphan'  => $row->created_by_account_id === null && $row->last_login_at === null,
                'is_self' => (int) $row->id === $this->currentAccountId(),

                // Si ofrecerle la baja tiene sentido. Esconder el enlace no es la salvaguarda --
                // esa vive en el modelo, dentro de una transacción -- sino no ofrecer un botón que
                // solo puede fallar.
                'deletable' => (int) $row->id !== $this->currentAccountId()
                    && ! ((bool) $row->is_platform_admin && $adminCount <= 1),
            ];
        }

        return view('platform/accounts/index', [
            'title'       => lang('Platform.accounts_title'),
            'nav'         => 'accounts',
            'accounts'    => $accounts,
            'admin_count' => $adminCount,
        ]);
    }

    public function newAccount(): string
    {
        return $this->accountForm();
    }

    /**
     * El alta. Cada rechazo devuelve el formulario con lo que ya se había escrito -- menos la
     * contraseña, que no se repite en el HTML -- y con el motivo delante, no un mensaje suelto
     * sobre una pantalla en blanco.
     */
    public function create(): RedirectResponse|string
    {
        $email    = trim((string) $this->request->getPost('email'));
        $password = (string) $this->request->getPost('password');
        $confirm  = (string) $this->request->getPost('password_confirm');
        $isAdmin  = $this->request->getPost('is_platform_admin') === '1';

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->accountForm(lang('Platform.account_create_failed_email_invalid'), '', $isAdmin);
        }

        if ($this->account->where('email', $email)->countAllResults() > 0) {
            return $this->accountForm(lang('Platform.account_create_failed_email_taken', [$email]), $email, $isAdmin);
        }

        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return $this->accountForm(
                lang('Platform.account_create_failed_password_short', [self::MIN_PASSWORD_LENGTH]),
                $email,
                $isAdmin,
            );
        }

        // hash_equals y no ===: la comparación es de dos cosas que el mismo operador acaba de
        // teclear, así que no hay nada que filtrar, pero es la misma forma que usa el resto de la
        // consola y no invita a nadie a escribir la versión insegura por costumbre.
        if (! hash_equals($password, $confirm)) {
            return $this->accountForm(lang('Platform.account_create_failed_password_mismatch'), $email, $isAdmin);
        }

        $id = $this->account->createAccount($email, $password, $isAdmin, $this->currentAccountId());

        // El detalle NUNCA lleva la contraseña. Esta tabla la leen personas.
        $this->logActivity(
            PlatformActivity::ACCOUNT_CREATED,
            PlatformActivity::TARGET_ACCOUNT,
            (string) $id,
            ['email' => $email, 'is_platform_admin' => $isAdmin],
        );

        return redirect()->to('platform/accounts')->with('message', lang('Platform.account_created', [$email]));
    }

    /**
     * La pantalla de confirmación. Si la baja va a ser rechazada de todas formas -- es uno mismo, o
     * es el último administrador -- lo dice aquí y no pinta el formulario. Enseñar un botón que solo
     * puede fallar es una forma cara de explicar una regla.
     *
     * LA SEGUNDA RAMA NO SE PUEDE ALCANZAR HOY, Y SE QUEDA IGUAL
     *
     * Quien opera esta consola es administrador -- Platform_Controller no deja pasar a nadie más --
     * así que si el objetivo TAMBIÉN es administrador hay por lo menos dos, y `countAdmins() <= 1`
     * no puede ser cierto. La única forma de apuntar al último administrador es apuntarse a uno
     * mismo, y ahí gana la primera rama. Por eso ninguna prueba de este controlador la cubre: la
     * regla de verdad vive en PlatformAccount::deleteAccount(), dentro de una transacción, para la
     * carrera entre dos peticiones simultáneas, y es allí donde está probada.
     *
     * Se queda escrita porque la premisa que la vuelve imposible es «solo entran administradores»,
     * y esa es exactamente la clase de premisa que una entrega futura cambia sin acordarse de esta
     * pantalla. Cuesta tres líneas.
     */
    public function confirmDelete(int $id): string
    {
        $target = $this->findAccount($id);

        $blocked = null;

        if ($id === $this->currentAccountId()) {
            $blocked = lang('Platform.account_delete_refused_self');
        } elseif ((bool) $target->is_platform_admin && $this->account->countAdmins() <= 1) {
            $blocked = lang('Platform.account_delete_refused_last_admin');
        }

        return view('platform/accounts/confirm_delete', [
            'title'   => lang('Platform.account_confirm_delete_title'),
            'nav'     => 'accounts',
            'account' => $target,
            'blocked' => $blocked,
        ]);
    }

    /**
     * La baja, con los frenos en este orden:
     *
     *  1. El correo escrito a mano. Va primero porque cubre el error frecuente -- la fila
     *     equivocada -- y porque su mensaje es el único que le sirve a quien se equivocó tecleando.
     *  2. Las dos reglas del modelo, dentro de su transacción: ni uno mismo, ni el último
     *     administrador. Se comprueban aunque confirmDelete() ya las haya mirado: aquella mirada es
     *     de hace una pantalla y esta es la que manda.
     *
     * El correo se lee ANTES de eliminar la fila. Después de la baja ya no hay de dónde sacarlo, y
     * el mensaje que se le muestra al operador -- y el detalle que queda en el registro -- se
     * quedarían con un número.
     */
    public function delete(int $id): RedirectResponse
    {
        $target = $this->findAccount($id);
        $email  = (string) $target->email;

        if (! $this->typedCorrectly($email, $this->request->getPost('confirm_email'))) {
            return redirect()->to('platform/accounts/' . $id . '/delete')
                ->with('error', lang('Platform.account_delete_refused_email', [$email]));
        }

        try {
            $this->account->deleteAccount($id, $this->currentAccountId());
        } catch (RuntimeException $e) {
            return redirect()->to('platform/accounts/' . $id . '/delete')->with('error', $e->getMessage());
        }

        // Se registra DESPUÉS, y solo si la baja ocurrió: una fila que dice «cuenta eliminada»
        // cuando la transacción se echó atrás sería peor que no tener registro.
        //
        // La fila conserva el correo de la cuenta eliminada en el detalle, y el de quien la eliminó
        // en `account_email`. Ninguno de los dos se puede volver a resolver por id: para eso está
        // desnormalizado el segundo y por eso el primero se copia aquí.
        $this->logActivity(
            PlatformActivity::ACCOUNT_DELETED,
            PlatformActivity::TARGET_ACCOUNT,
            (string) $id,
            ['email' => $email],
        );

        return redirect()->to('platform/accounts')->with('message', lang('Platform.account_deleted', [$email]));
    }

    /**
     * Levanta el freno de D8 sobre OTRA cuenta.
     *
     * No hay ruta para desbloquearse a sí mismo y tampoco haría falta: el freno solo cierra la
     * puerta de entrada, así que quien está dentro no lo está sufriendo. Que haga falta un segundo
     * superadministrador es justamente el motivo de que exista `php spark platform:unlock-account`,
     * para el día en que solo quede uno y se quede fuera.
     */
    public function unlock(int $id): RedirectResponse
    {
        $target = $this->findAccount($id);

        if (! $this->isLocked($target)) {
            return redirect()->to('platform/accounts')->with('error', lang('Platform.account_not_locked'));
        }

        $attempts = (int) $target->failed_login_count;

        $this->account->unlock($id);

        $this->logActivity(
            PlatformActivity::ACCOUNT_UNLOCKED,
            PlatformActivity::TARGET_ACCOUNT,
            (string) $id,
            ['email' => (string) $target->email, 'failed_login_count' => $attempts],
        );

        return redirect()->to('platform/accounts')
            ->with('message', lang('Platform.account_unlocked', [$target->email]));
    }

    public function password(): string
    {
        return $this->passwordForm();
    }

    /**
     * Cambiar la PROPIA contraseña, y solo la propia: el id sale de la sesión, nunca de la
     * petición. No existe ruta para recambiarle la contraseña a un tercero -- a un
     * superadministrador que no puede entrar se le desbloquea o se le reemplaza, no se le cambia
     * la llave por detrás.
     */
    public function changePassword(): RedirectResponse|string
    {
        $current = (string) $this->request->getPost('password_current');
        $new     = (string) $this->request->getPost('password_new');
        $confirm = (string) $this->request->getPost('password_new_confirm');

        if (! password_verify($current, (string) $this->currentAccount()->password_hash)) {
            return $this->passwordForm(lang('Platform.password_change_failed_current'));
        }

        if (strlen($new) < self::MIN_PASSWORD_LENGTH) {
            return $this->passwordForm(lang('Platform.password_change_failed_short', [self::MIN_PASSWORD_LENGTH]));
        }

        if (! hash_equals($new, $confirm)) {
            return $this->passwordForm(lang('Platform.password_change_failed_mismatch'));
        }

        if (hash_equals($current, $new)) {
            return $this->passwordForm(lang('Platform.password_change_failed_same'));
        }

        $this->account->changePassword($this->currentAccountId(), $new);

        // El identificador de sesión se renueva al cambiar la credencial, por la misma razón por la
        // que se renueva al entrar: si alguien fijó un identificador antes, deja de servirle ahora.
        // Los datos de la sesión actual sobreviven, así que el operador sigue dentro.
        session()->regenerate(true);

        // Qué se cambió, nunca a qué. El detalle va vacío a propósito.
        $this->logActivity(
            PlatformActivity::ACCOUNT_PASSWORD_CHANGED,
            PlatformActivity::TARGET_ACCOUNT,
            (string) $this->currentAccountId(),
        );

        return redirect()->to('platform/accounts')->with('message', lang('Platform.password_changed'));
    }

    /**
     * Si la cuenta está frenada AHORA MISMO.
     *
     * Es la misma regla que PlatformAccount::isLocked(), que es privada porque el modelo la usa
     * para decidir si deja entrar. Aquí se necesita para pintar una etiqueta y para saber si hay
     * algo que desbloquear. Las dos constantes son públicas justamente para esto, pero conviene
     * saber que son dos copias de la misma frase: si el freno de D8 cambia de forma, este método
     * hay que cambiarlo también, y ninguna prueba de la otra mitad lo va a notar.
     */
    private function isLocked(object $account): bool
    {
        return (int) $account->failed_login_count >= PlatformAccount::MAX_FAILED_ATTEMPTS
            && $account->failed_login_first_at !== null
            && strtotime((string) $account->failed_login_first_at) > time() - PlatformAccount::LOCKOUT_WINDOW_SECONDS;
    }

    /**
     * @throws PageNotFoundException cuando no hay ninguna cuenta con ese id
     */
    private function findAccount(int $id): object
    {
        $account = $this->account->find($id);

        if ($account === null) {
            throw PageNotFoundException::forPageNotFound("No platform account carries the id {$id}.");
        }

        return $account;
    }

    /**
     * Se recorta el espacio en blanco: un correo no puede llevarlo, así que recortarlo no puede
     * convertir una respuesta equivocada en una acertada, y un valor pegado con un espacio detrás
     * no debería leerse como un error de tecleo. Lo demás se compara exacto, mayúsculas incluidas:
     * la pantalla enseña el correo al lado del campo y esto es una confirmación, no un inicio de
     * sesión.
     */
    private function typedCorrectly(string $expected, ?string $typed): bool
    {
        return $expected !== '' && hash_equals($expected, trim((string) $typed));
    }

    private function accountForm(?string $error = null, string $email = '', bool $isAdmin = true): string
    {
        return view('platform/accounts/form', [
            'title'        => lang('Platform.new_account_title'),
            'nav'          => 'accounts',
            'error'        => $error,
            'email'        => $email,
            'is_admin'     => $isAdmin,
            'min_password' => self::MIN_PASSWORD_LENGTH,
        ]);
    }

    private function passwordForm(?string $error = null): string
    {
        return view('platform/accounts/password', [
            'title'        => lang('Platform.password_title'),
            'nav'          => 'password',
            'error'        => $error,
            'min_password' => self::MIN_PASSWORD_LENGTH,
        ]);
    }
}
