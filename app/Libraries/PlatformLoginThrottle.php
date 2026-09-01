<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\PlatformAccount;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * El freno de D8: tres intentos fallidos por cada dos horas, contados SOBRE LA CUENTA, con una
 * ventana que se cura sola.
 *
 * ------------------------------------------------------------------------------------------------
 * QUÉ QUIERE DECIR «LA VENTANA SE CURA SOLA», Y QUÉ NO
 * ------------------------------------------------------------------------------------------------
 *
 * El ancla es el PRIMER fallo, y no se mueve. Tres fallos a las 10:00 cierran la cuenta hasta las
 * 12:00 exactas: un cuarto intento a las 11:59 no empuja el final a las 13:59. Al pasar las 12:00
 * el contador se olvida solo y el siguiente intento se juzga por sí mismo, sin que nadie tenga que
 * hacer nada.
 *
 * La alternativa -- mover el ancla en cada intento -- es la que sale sola si uno escribe
 * `failed_login_first_at = NOW()` sin la condición, y convierte el freno en una cárcel: un atacante
 * que golpee cada media hora deja al dueño legítimo fuera para siempre, y encima gratis, porque a
 * él le da igual esperar. El freno tiene que castigar la ráfaga, no la existencia de la cuenta.
 *
 * Lo otro que exige D8 y que NO se decide aquí: que el mensaje de error sea el mismo cuando la
 * cuenta está frenada, cuando la contraseña es mala y cuando el correo no existe. Una cuenta que
 * conteste «demasiados intentos» mientras otra dirección contesta «contraseña incorrecta» acaba de
 * confirmar que existe. Eso vive en la pantalla, con una única cadena
 * (`Platform.invalid_credentials`) para los tres casos.
 *
 * ------------------------------------------------------------------------------------------------
 * POR QUÉ ESTA CLASE EXISTE SI EL MODELO YA SABE HACERLO
 * ------------------------------------------------------------------------------------------------
 *
 * La Fase 0 dejó esta lógica dentro de `App\Models\PlatformAccount`, en métodos privados y leyendo
 * `time()` directamente. Ahí no se puede probar sin `sleep(7200)`, y hay un segundo sitio que
 * necesita exactamente el mismo freno: el reto del segundo factor. Un código de seis dígitos con
 * ventana de ±1 período tiene tres valores válidos a la vez; sin freno, quien ya tenga la
 * contraseña puede recorrer el espacio entero a fuerza de peticiones, y el freno de la contraseña
 * no le estorba porque ya la pasó.
 *
 * Así que la decisión vive aquí, con el reloj inyectado, y el reto del segundo factor la usa.
 *
 * **`PlatformAccount::login()` conserva su copia**: ese archivo está congelado en esta entrega. Las
 * dos coinciden hoy -- las constantes son literalmente las mismas, importadas de allí, no copiadas
 * -- pero son dos cuerpos de código, y eso es deuda: está anotada en el informe de la entrega para
 * que el paso siguiente sea borrar los métodos privados del modelo y delegar aquí.
 *
 * ------------------------------------------------------------------------------------------------
 * LA ZONA HORARIA, QUE ES LA TRAMPA DE ESTE ARCHIVO
 * ------------------------------------------------------------------------------------------------
 *
 * `failed_login_first_at` es un DATETIME sin zona: guarda «2026-08-31 10:00:00» y nada más. Quien
 * lo escribe decide en qué zona significa eso, y quien lo lee vuelve a decidirlo. Si las dos
 * decisiones no coinciden, la ventana se desplaza horas enteras -- en la dirección mala, un freno
 * que ya caducó sigue cerrado, o uno vigente deja pasar.
 *
 * `PlatformAccount` lo escribe con `date()` y lo compara con `strtotime()`: ambos usan la zona por
 * DEFECTO DEL PROCESO en el instante en que se ejecutan. Hoy no pueden discrepar porque
 * `App\Events\Load_config` la fija al principio de cada petición de la consola. Pero es una
 * invariante sostenida por una llamada lejana, no por el tipo del dato.
 *
 * Aquí la zona se captura UNA vez, al construir, y se usa para escribir Y para leer. Dentro de esta
 * clase la ventana ya no puede moverse aunque algo cambie `date_default_timezone_set()` a mitad de
 * la petición. Lo que sigue sin poder arreglarse desde aquí es que una fila escrita por el modelo
 * en otra zona se lea mal: eso solo lo cierra guardar UTC en la columna, que es un cambio de
 * esquema y está fuera de esta entrega. Queda dicho en el informe.
 */
final class PlatformLoginThrottle
{
    /**
     * Importadas, no copiadas. Un `3` escrito otra vez aquí sería un `3` que alguien puede cambiar
     * en un sitio y no en el otro, y el síntoma sería que la cuenta se cierra al segundo intento o
     * al cuarto, sin que nada falle.
     */
    public const MAX_FAILED_ATTEMPTS = PlatformAccount::MAX_FAILED_ATTEMPTS;

    public const WINDOW_SECONDS = PlatformAccount::LOCKOUT_WINDOW_SECONDS;
    private const TABLE         = 'platform_accounts';

    /**
     * @var callable():int
     */
    private $clock;

    private readonly DateTimeZone $timezone;

    /**
     * @param (callable():int)|null $clock    devuelve el instante actual como epoch UNIX. Inyectado
     *                                        para que las pruebas puedan situarse a las 1:59 y a
     *                                        las 2:00:01 sin esperar dos horas.
     * @param BaseConnection|null   $db       conexión al esquema de control. Sin ella la clase
     *                                        sigue decidiendo y sigue actualizando la fila en
     *                                        memoria; simplemente no persiste. Así la lógica de D8
     *                                        se puede probar entera sin base de datos.
     * @param DateTimeZone|null     $timezone la zona en la que se interpreta y se escribe
     *                                        `failed_login_first_at`. Por omisión, la del proceso
     *                                        EN ESTE INSTANTE, que es la que usa PlatformAccount.
     */
    public function __construct(
        ?callable $clock = null,
        private readonly ?BaseConnection $db = null,
        ?DateTimeZone $timezone = null,
    ) {
        $this->clock    = $clock ?? static fn (): int => time();
        $this->timezone = $timezone ?? new DateTimeZone(date_default_timezone_get());
    }

    public function now(): int
    {
        return ($this->clock)();
    }

    // ===================== Preguntas =====================

    /**
     * ¿Está la cuenta frenada AHORA?
     *
     * Hacen falta las dos condiciones. El contador solo no basta: un `failed_login_count = 3` con
     * el ancla ya caducada es historia, no un bloqueo. Y el ancla sola tampoco: uno o dos fallos
     * recientes no cierran nada.
     */
    public function isLocked(object $account): bool
    {
        $anchor = $this->anchorOf($account);

        return $anchor !== null
            && $this->failureCountOf($account) >= self::MAX_FAILED_ATTEMPTS
            && $anchor > $this->now() - self::WINDOW_SECONDS;
    }

    /**
     * ¿La ventana abierta por el primer fallo ya se cerró?
     *
     * Cierto también cuando hay uno o dos fallos viejos y ningún bloqueo: esos también hay que
     * olvidarlos, o dos fallos de anteayer más uno de hoy cerrarían la cuenta.
     */
    public function windowHasExpired(object $account): bool
    {
        $anchor = $this->anchorOf($account);

        return $anchor !== null && $anchor <= $this->now() - self::WINDOW_SECONDS;
    }

    /**
     * El instante (epoch) en que el freno se levanta solo, o null si no está puesto.
     *
     * Se calcula desde el ancla, NUNCA desde «ahora»: la respuesta tiene que ser la misma se
     * pregunte cuando se pregunte, o el propio freno diría que dura dos horas más cada vez que
     * alguien lo mira.
     */
    public function lockedUntil(object $account): ?int
    {
        if (! $this->isLocked($account)) {
            return null;
        }

        return $this->anchorOf($account) + self::WINDOW_SECONDS;
    }

    /**
     * Cuántos intentos quedan antes de que se cierre. Cero cuando ya está cerrada.
     */
    public function attemptsRemaining(object $account): int
    {
        if ($this->windowHasExpired($account)) {
            return self::MAX_FAILED_ATTEMPTS;
        }

        return max(0, self::MAX_FAILED_ATTEMPTS - $this->failureCountOf($account));
    }

    // ===================== Cambios =====================

    /**
     * Anota un fallo.
     *
     * **El ancla solo se escribe cuando no había ninguna.** Esa condición es todo el diseño: es lo
     * que hace que la ventana no se extienda por llegar más intentos dentro de ella.
     *
     * @return bool cierto SOLO cuando este fallo es el que acaba de cerrar la cuenta -- la
     *              transición que D6 registra como `account.locked`, y que ocurre exactamente una
     *              vez. Los rechazos posteriores dentro de la misma ventana no son un hecho nuevo,
     *              y devolver cierto en todos ellos llenaría el registro de actividad con la misma
     *              línea durante dos horas.
     */
    public function registerFailure(object $account): bool
    {
        $count  = $this->failureCountOf($account) + 1;
        $update = ['failed_login_count' => $count];

        if ($this->anchorOf($account) === null) {
            $update['failed_login_first_at'] = $this->formatStamp($this->now());
        }

        $this->apply($account, $update);

        return $count === self::MAX_FAILED_ATTEMPTS;
    }

    /**
     * Olvida el contador si -- y solo si -- la ventana ya se cerró. Es la mitad «se cura sola» de
     * D8, y hay que llamarla ANTES de juzgar el intento para que se juzgue por sí mismo.
     *
     * @return bool si hizo falta olvidar algo
     */
    public function forgetExpiredWindow(object $account): bool
    {
        if (! $this->windowHasExpired($account)) {
            return false;
        }

        $this->clear($account);

        return true;
    }

    /**
     * Borra el contador: un acierto, o el desbloqueo manual de otro superadministrador.
     */
    public function clear(object $account): void
    {
        $this->apply($account, [
            'failed_login_count'    => 0,
            'failed_login_first_at' => null,
        ]);
    }

    // ===================== Interno =====================

    /**
     * Escribe en la fila que tiene en la mano Y, si hay conexión, en la base.
     *
     * Las dos, siempre, y en ese orden. Quien llama sigue usando `$account` después -- para decidir
     * si registrar `account.locked`, para pintar la pantalla-- y una fila en memoria que contradice
     * a la base es la clase de desacuerdo que no da error y se descubre en el comportamiento.
     */
    private function apply(object $account, array $columns): void
    {
        foreach ($columns as $column => $value) {
            $account->{$column} = $value;
        }

        if ($this->db === null || ! isset($account->id)) {
            return;
        }

        $this->db->table(self::TABLE)->where('id', (int) $account->id)->update($columns);
    }

    private function failureCountOf(object $account): int
    {
        return (int) ($account->failed_login_count ?? 0);
    }

    /**
     * El ancla como epoch, o null si no hay ninguna o si lo guardado no es una fecha.
     *
     * Una fila ilegible se trata como «sin ancla» a propósito: la alternativa es reventar en la
     * pantalla de entrada, y un dato corrupto en esta columna no puede ser motivo para dejar a
     * nadie fuera. El coste es que esa cuenta pierde su freno hasta el siguiente fallo, que
     * reescribe la columna con algo válido.
     */
    private function anchorOf(object $account): ?int
    {
        return $this->toEpoch($account->failed_login_first_at ?? null);
    }

    private function toEpoch(mixed $value): ?int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value, $this->timezone))->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * El formato de la columna: `Y-m-d H:i:s`, sin zona, igual que lo escribe `PlatformAccount`.
     * Lo que aquí se fija no es el formato sino la ZONA en la que se calcula, que es la capturada
     * al construir y no la que el proceso tenga en este momento.
     */
    private function formatStamp(int $epoch): string
    {
        return (new DateTimeImmutable('@' . $epoch))->setTimezone($this->timezone)->format('Y-m-d H:i:s');
    }
}
