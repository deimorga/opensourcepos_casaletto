<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;
use Throwable;

/**
 * The console's record of what it changed -- level 1 of section 7 of the technical design.
 *
 * D6 fixes both the scope and its cost: MODIFICATIONS are recorded, accesses are not. The system
 * will therefore never answer "who entered and when" -- `platform_accounts.last_login_at` covers
 * the only part of that anybody asked for -- and it will always answer "who changed what". That is
 * a decision, not an omission, and the list of actions below is where it is written down.
 *
 * WHAT IS DELIBERATELY ABSENT
 *
 * A successful login. A failed attempt. Opening a screen. What IS here is `account.locked`, because
 * the counter tripping is a real change of state that outlives the request and leaves somebody shut
 * out until another superadministrator acts.
 *
 * WHY THE ACTOR'S EMAIL IS COPIED ONTO EVERY ROW
 *
 * The first use of this log is deleting the orphan account. With only an id, the row saying who
 * deleted it would read "account #2 deleted account #3" and would stop being legible the day
 * account #2 is itself rotated out. There is no foreign key for the same reason: a log a DELETE
 * elsewhere can break is not a log.
 */
class PlatformActivity extends Model
{
    public const TENANT_CREATED           = 'tenant.created';
    public const TENANT_SUSPENDED         = 'tenant.suspended';
    public const TENANT_ACTIVATED         = 'tenant.activated';
    public const TENANT_DELETED           = 'tenant.deleted';
    public const TENANT_SCHEMA_DROPPED    = 'tenant.schema_dropped';
    // Entrega 3, D5. Restablecer la contraseña de un negocio SÍ es una modificación y por eso está
    // aquí; consultarla no lo es y por eso no está. Es la misma distinción que D6 hace en todo lo
    // demás, sostenida también cuando incomoda.
    public const TENANT_PASSWORD_RESET    = 'tenant.password_reset';
    public const ACCOUNT_CREATED          = 'account.created';
    public const ACCOUNT_DELETED          = 'account.deleted';
    public const ACCOUNT_PASSWORD_CHANGED = 'account.password_changed';
    public const ACCOUNT_LOCKED           = 'account.locked';
    public const ACCOUNT_UNLOCKED         = 'account.unlocked';
    public const ACCOUNT_TOTP_ENABLED     = 'account.totp_enabled';
    public const ACCOUNT_TOTP_DISABLED    = 'account.totp_disabled';

    /**
     * El nivel 2: una escritura hecha DENTRO del punto de venta de un negocio durante una sesión de
     * soporte. Lo escribe `App\Filters\PlatformSupportAudit`, y lleva la ruta y el desenlace --
     * nunca el cuerpo, que puede contener la contraseña de un empleado del cliente.
     */
    public const SUPPORT_WRITE = 'support.write';

    /**
     * Alguien entró al punto de venta de un cliente. Se anota la ENTRADA y no solo lo que se haga
     * después: haber estado dentro de la caja de un negocio es un dato por sí mismo, aunque no se
     * tocara nada.
     */
    public const SUPPORT_ENTERED = 'support.entered';

    /**
     * Every action this module writes, in the order the design lists them. Kept as a list so the
     * activity screen can offer a filter without inventing its own copy of the vocabulary, and so a
     * typo in a controller shows up as a value that is not in here rather than as a row nobody ever
     * finds again.
     */
    public const ACTIONS = [
        self::TENANT_CREATED,
        self::TENANT_SUSPENDED,
        self::TENANT_ACTIVATED,
        self::TENANT_DELETED,
        self::TENANT_SCHEMA_DROPPED,
        self::TENANT_PASSWORD_RESET,
        self::ACCOUNT_CREATED,
        self::ACCOUNT_DELETED,
        self::ACCOUNT_PASSWORD_CHANGED,
        self::ACCOUNT_LOCKED,
        self::ACCOUNT_UNLOCKED,
        self::ACCOUNT_TOTP_ENABLED,
        self::ACCOUNT_TOTP_DISABLED,
        self::SUPPORT_ENTERED,
        self::SUPPORT_WRITE,
    ];

    public const TARGET_TENANT  = 'tenant';
    public const TARGET_ACCOUNT = 'account';

    protected $DBGroup       = 'platform';
    protected $table         = 'platform_activity_log';
    protected $primaryKey    = 'id';
    protected $returnType    = 'object';
    protected $allowedFields = [
        'account_id',
        'account_email',
        'action',
        'target_type',
        'target_id',
        'detail',
        'ip_address',
        'created_at',
    ];

    // The table has created_at and no updated_at: a log entry is written once and never edited.
    // CI4's automatic timestamps would insist on both, so the one there is gets written by hand.
    protected $useTimestamps = false;

    /**
     * Writes one entry. Everything but the action is optional, because there is no version of this
     * that should ever throw: a log that can fail a request it was only observing would be a log
     * worth removing.
     *
     * The actor is resolved from the session when it is not passed, so no caller has to remember
     * to supply it -- forgetting would produce an anonymous row that looks perfectly legitimate.
     * Under `php spark` there is no session and the row is genuinely anonymous, which is honest.
     *
     * @param string      $action     one of the constants above
     * @param string|null $targetType self::TARGET_TENANT or self::TARGET_ACCOUNT
     * @param string|null $targetId   a slug for a business, the numeric id for an account
     * @param array       $detail     stored as JSON. NEVER put a password, a TOTP secret or a
     *                                recovery code in here: this table is read by people.
     * @param object|null $actor      the account doing it, when it is not the one in session
     */
    public function record(
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $detail = [],
        ?object $actor = null,
    ): int {
        $actor ??= model(PlatformAccount::class)->getLoggedInAccount();

        // Observar no puede tumbar lo observado.
        //
        // Este registro se escribe DESPUES de la operacion, asi que un fallo aqui daria un 500
        // sobre algo que ya ocurrio: el caso feo es `PlatformAdmin::delete()` con destruccion de
        // esquema -- la base del cliente ya no existe y la pantalla diria que la peticion fallo.
        // Y las migraciones de plataforma son un paso manual (`php spark platform:migrate`), asi
        // que la tabla puede no estar todavia en un despliegue recien hecho.
        //
        // Si no se puede escribir la fila, queda la linea critica en el log, que es el rastro que
        // este registro vino a sustituir y sigue estando en el volumen del contenedor.
        try {
            $this->insert([
                'account_id'    => $actor === null ? null : (int) $actor->id,
                'account_email' => $actor === null ? null : (string) $actor->email,
                'action'        => $action,
                'target_type'   => $targetType,
                'target_id'     => $targetId,
                'detail'        => $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE),
                'ip_address'    => service('request')->getIPAddress(),
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            log_message(
                'critical',
                'No se pudo registrar la actividad de plataforma "' . $action . '"'
                . ($targetId === null ? '' : ' sobre ' . $targetType . ' ' . $targetId)
                . ': ' . $e->getMessage()
            );

            return 0;
        }

        return (int) $this->getInsertID();
    }

    /**
     * The newest first, which is what the screen shows and what the created_at index exists for.
     *
     * Ordered by id as well, and not only by created_at: the column has one-second resolution, so
     * two entries written by the same request would otherwise come back in an order the database
     * was free to choose.
     *
     * @return list<object>
     */
    public function recent(int $limit = 200): array
    {
        return $this->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->findAll(max(1, $limit));
    }

    /**
     * Everything that ever happened to one business or one account -- the query the composite index
     * over (target_type, target_id) exists for.
     *
     * @return list<object>
     */
    public function forTarget(string $targetType, string $targetId, int $limit = 200): array
    {
        return $this->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->findAll(max(1, $limit));
    }
}
