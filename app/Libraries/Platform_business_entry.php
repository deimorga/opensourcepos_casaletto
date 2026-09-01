<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\PlatformAccount;
use App\Models\PlatformLoginOutcome;
use App\Models\PlatformLoginResult;

/**
 * Entrar al punto de venta de un negocio con una credencial de plataforma.
 *
 * LO QUE ESTA CLASE NO HACE, Y ES LO MÁS IMPORTANTE DE ELLA
 *
 * No toca `Employee::login()`. La puerta por la que entran los empleados del negocio todos los días
 * queda **byte a byte como estaba**, y además corre PRIMERO: esto solo se intenta cuando aquello ya
 * dijo que no. Un fallo aquí no puede dejar fuera a nadie del negocio, porque para llegar aquí su
 * credencial ya tuvo su turno.
 *
 * EL SEGUNDO FACTOR NO SE SALTA POR ESTA PUERTA
 *
 * Es la razón por la que esto no es un `if` de tres líneas dentro del login. Si la contraseña de
 * plataforma sola abriera el punto de venta de un cliente, el segundo factor quedaría de adorno
 * justo donde más vale: una contraseña filtrada abriría TODOS los negocios a la vez. Así que se
 * reutiliza `PlatformAccount::login()` entero --con su freno de tres intentos por dos horas y su
 * desenlace de «falta el segundo factor»-- y la sesión no se abre hasta que el código se verifica.
 *
 * El freno vive en el modelo y no en el controlador precisamente para esto: cubrir las dos entradas
 * sin que nadie tenga que acordarse de replicarlo. Ver `PlatformAccount::login()`.
 *
 * QUIÉN QUEDA COMO AUTOR
 *
 * Todo OSPOS cuelga de `person_id`. La sesión se abre sobre el **empleado de soporte** de ESE
 * negocio (`Platform_support`), no sobre un empleado del cliente: lo que se haga ahí dentro tiene
 * autor, y ese autor no es una persona del cliente a la que luego se le atribuya algo que no hizo.
 */
final class Platform_business_entry
{
    /** Qué cuenta de plataforma abrió esta sesión. Su presencia ES la sesión de soporte. */
    public const SUPPORT_ACCOUNT_KEY = 'platform_support_account_id';

    /** Su correo, para el aviso permanente y para el registro, sin volver a consultar la base. */
    public const SUPPORT_EMAIL_KEY = 'platform_support_email';

    public function __construct(private readonly PlatformAccount $account) {}

    public static function create(): self
    {
        return new self(model(PlatformAccount::class));
    }

    /**
     * Intenta la credencial de plataforma contra el negocio que sirve esta petición.
     *
     * Devuelve `null` cuando esto ni siquiera aplica --no hay negocio resuelto--, para que quien
     * llame siga por su camino normal sin distinguir casos.
     */
    public function attempt(string $usuario, string $contrasena): ?PlatformLoginResult
    {
        if (! TenantContext::isResolved()) {
            return null;
        }

        return $this->account->login($usuario, $contrasena);
    }

    /**
     * ¿Hay una entrada a medias esperando el código? Es lo que separa «te equivocaste» de «falta el
     * segundo factor», y lo que impide que la pantalla del código sea alcanzable sin haber dado
     * antes la contraseña correcta.
     */
    public function pendingAccountId(): ?int
    {
        return $this->account->pendingSecondFactorAccountId();
    }

    /**
     * Termina la entrada: asciende la cuenta pendiente y abre la sesión de soporte en este negocio.
     *
     * El orden importa. `completeSecondFactor()` se niega a ascender una cuenta que no sea la
     * pendiente, así que primero se comprueba el factor y solo después se busca al empleado de
     * soporte. Si ese empleado no existe en este negocio, NO se asciende nada: es preferible negar
     * la entrada a abrir una sesión sin autor, que dejaría registros huérfanos en la base de un
     * cliente.
     *
     * @return string|null El motivo del rechazo, o null si entró.
     */
    public function finish(int $accountId): ?string
    {
        $soporte = $this->supportEmployee();

        if ($soporte === null) {
            return lang('Login.platform_support_employee_missing');
        }

        if (! $this->account->completeSecondFactor($accountId)) {
            return lang('Login.platform_second_factor_expired');
        }

        $this->openSupportSession($accountId, (int) $soporte->person_id);

        return null;
    }

    /**
     * Abre la sesión de soporte. `completeSecondFactor()` ya regeneró el identificador de sesión,
     * así que aquí solo se marca de quién es.
     */
    public function openSupportSession(int $accountId, int $personId): void
    {
        $cuenta = $this->account->find($accountId);

        session()->set('person_id', $personId);
        session()->set(self::SUPPORT_ACCOUNT_KEY, $accountId);
        session()->set(self::SUPPORT_EMAIL_KEY, $cuenta->email ?? '');
    }

    /**
     * El empleado de soporte de ESTE negocio, o null si todavía no lo tiene.
     *
     * Se busca por la columna y no solo por el usuario: el nombre de usuario es un dato que alguien
     * podría cambiar desde la base, y lo que define a esta fila es la marca.
     */
    public function supportEmployee(): ?object
    {
        return db_connect()->table('employees')
            ->where('username', Platform_support::USERNAME)
            ->where('is_platform_support', 1)
            ->where('deleted', 0)
            ->get()
            ->getRow();
    }

    /** ¿La sesión que sirve esta petición es de soporte? Lo usan el aviso y el registro de nivel 2. */
    public static function isSupportSession(): bool
    {
        return session()->get(self::SUPPORT_ACCOUNT_KEY) !== null;
    }

    public static function accountId(): ?int
    {
        $id = session()->get(self::SUPPORT_ACCOUNT_KEY);

        return $id === null ? null : (int) $id;
    }

    public static function accountEmail(): string
    {
        return (string) session()->get(self::SUPPORT_EMAIL_KEY);
    }

    /**
     * Una cuenta SIN segundo factor no entra al punto de venta de un cliente.
     *
     * `PlatformAccount::login()` devuelve `Success` --y abre sesión-- cuando la cuenta no tiene el
     * segundo factor activado. Para la consola eso está bien; para esta puerta no: si bastara con no
     * activarlo, el candado que se acaba de poner se salta simplemente no poniéndoselo, y una
     * contraseña filtrada abriría la caja de todos los negocios a la vez.
     *
     * Se rechaza Y se cierra la sesión que el modelo acababa de abrir: dejarla puesta significaría
     * que la contraseña, sola, deja rastro de haber entrado en el negocio de un cliente.
     */
    public function refuseWithoutSecondFactor(): string
    {
        session()->destroy();

        return lang('Login.platform_second_factor_required');
    }

    /** Atajos legibles para el controlador, que solo necesita saber qué pantalla mostrar. */
    public static function needsSecondFactor(?PlatformLoginResult $resultado): bool
    {
        return $resultado?->outcome === PlatformLoginOutcome::SecondFactorRequired;
    }

    public static function isLocked(?PlatformLoginResult $resultado): bool
    {
        return $resultado?->outcome === PlatformLoginOutcome::Locked;
    }

    public static function isSuccess(?PlatformLoginResult $resultado): bool
    {
        return $resultado?->outcome === PlatformLoginOutcome::Success;
    }
}
