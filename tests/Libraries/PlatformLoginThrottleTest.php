<?php

declare(strict_types=1);

namespace Tests\Libraries;

use App\Libraries\PlatformLoginThrottle;
use App\Models\PlatformAccount;
use CodeIgniter\Test\CIUnitTestCase;
use DateTimeZone;

/**
 * El freno de D8, entero, sin base de datos y sin esperar dos horas.
 *
 * Nada aquí toca MySQL a propósito: la clase se construye sin conexión, decide sobre la fila que se
 * le pasa y la actualiza en memoria. Es la única forma de probar «a las dos horas y un segundo
 * entra» sin un `sleep(7201)` que nadie ejecutaría nunca, y de probar la trampa de la zona horaria
 * cambiándola a mitad de la prueba.
 *
 * Lo que estas pruebas NO cubren, y hay que saberlo: que `PlatformAccount::login()` -- que conserva
 * su propia copia de esta lógica, porque ese archivo estaba congelado en la Entrega 2 -- se
 * comporte igual. Eso está en tests/Models/PlatformAccountTest.php, y las dos coincidirán mientras
 * las constantes sigan importadas y no copiadas.
 *
 * @internal
 */
final class PlatformLoginThrottleTest extends CIUnitTestCase
{
    /**
     * Las diez de la mañana de un día cualquiera. Un instante fijo y no `time()`: una prueba que
     * dependa del reloj real falla sola una vez al año, a la hora del cambio horario, y nadie sabe
     * por qué.
     */
    private const AT_TEN = 1_788_192_000;

    private int $now;

    private PlatformLoginThrottle $throttle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now      = self::AT_TEN;
        $this->throttle = new PlatformLoginThrottle(fn (): int => $this->now);
    }

    private function account(): object
    {
        return (object) [
            'id'                    => 1,
            'failed_login_count'    => 0,
            'failed_login_first_at' => null,
        ];
    }

    // ========== Tres por cada dos horas ==========

    public function testTwoFailuresDoNotLockAndTheThirdDoes(): void
    {
        $account = $this->account();

        $this->assertFalse($this->throttle->registerFailure($account), 'El primer fallo no cierra nada.');
        $this->assertFalse($this->throttle->isLocked($account));

        $this->now += 60;
        $this->assertFalse($this->throttle->registerFailure($account), 'El segundo tampoco.');
        $this->assertFalse($this->throttle->isLocked($account), 'Dos fallos no bloquean.');

        $this->now += 60;
        $this->assertTrue($this->throttle->registerFailure($account), 'El tercero es el que cierra.');
        $this->assertTrue($this->throttle->isLocked($account), 'Tres fallos bloquean.');
    }

    /**
     * `registerFailure()` devuelve cierto UNA vez y no más. Es lo que separa «la cuenta acaba de
     * quedar cerrada» -- la modificación que D6 registra -- de «sigue cerrada», que no es un hecho
     * nuevo. Sin esta distinción el registro de actividad se llenaría con la misma línea durante
     * dos horas seguidas.
     */
    public function testOnlyTheFailureThatTripsTheLimitReportsIt(): void
    {
        $account = $this->account();

        $this->throttle->registerFailure($account);
        $this->throttle->registerFailure($account);

        $this->assertTrue($this->throttle->registerFailure($account));
        $this->assertFalse($this->throttle->registerFailure($account), 'El cuarto ya no es noticia.');
        $this->assertFalse($this->throttle->registerFailure($account), 'Ni el quinto.');
    }

    public function testTwoHoursAndOneSecondLaterTheAccountIsInAgain(): void
    {
        $account = $this->lockedAccount();

        $this->now = self::AT_TEN + PlatformLoginThrottle::WINDOW_SECONDS - 1;
        $this->assertTrue($this->throttle->isLocked($account), 'Un segundo antes de las dos horas sigue cerrada.');

        $this->now = self::AT_TEN + PlatformLoginThrottle::WINDOW_SECONDS + 1;
        $this->assertFalse($this->throttle->isLocked($account), 'A las dos horas y un segundo entra.');
    }

    // ========== La ventana NO se extiende ==========

    /**
     * El corazón de «la ventana se cura sola»: el ancla es el PRIMER fallo y no se mueve.
     *
     * Si se moviera, un atacante que golpeara cada media hora dejaría al dueño legítimo fuera para
     * siempre, y gratis: a él esperar no le cuesta nada. El freno tiene que castigar la ráfaga, no
     * la existencia de la cuenta.
     */
    public function testAFourthAttemptInsideTheWindowDoesNotMoveTheAnchor(): void
    {
        $account = $this->lockedAccount();
        $anchor  = $account->failed_login_first_at;

        $this->now = self::AT_TEN + 7140; // la hora 1:59
        $this->throttle->registerFailure($account);

        $this->assertSame($anchor, $account->failed_login_first_at, 'El ancla es la del primer fallo, siempre.');
        $this->assertSame(
            self::AT_TEN + PlatformLoginThrottle::WINDOW_SECONDS,
            $this->throttle->lockedUntil($account),
            'El freno sigue levantándose a las 12:00, no a las 13:59.',
        );

        $this->now = self::AT_TEN + PlatformLoginThrottle::WINDOW_SECONDS + 1;
        $this->assertFalse($this->throttle->isLocked($account), 'Y a las dos horas del PRIMER fallo, entra.');
    }

    public function testLockedUntilIsTheSameAnswerHoweverOftenItIsAsked(): void
    {
        $account  = $this->lockedAccount();
        $expected = self::AT_TEN + PlatformLoginThrottle::WINDOW_SECONDS;

        $this->assertSame($expected, $this->throttle->lockedUntil($account));

        $this->now += 1800;
        $this->assertSame($expected, $this->throttle->lockedUntil($account), 'Mirar el freno no lo alarga.');

        $this->now += 1800;
        $this->assertSame($expected, $this->throttle->lockedUntil($account));
    }

    public function testLockedUntilIsNullWhenNothingIsLocked(): void
    {
        $this->assertNull($this->throttle->lockedUntil($this->account()));
    }

    // ========== La ventana se cura sola ==========

    public function testAnExpiredWindowIsForgottenSoTheNextAttemptIsJudgedOnItsOwn(): void
    {
        $account = $this->lockedAccount();

        $this->now = self::AT_TEN + PlatformLoginThrottle::WINDOW_SECONDS + 1;

        $this->assertTrue($this->throttle->windowHasExpired($account));
        $this->assertTrue($this->throttle->forgetExpiredWindow($account));
        $this->assertSame(0, (int) $account->failed_login_count);
        $this->assertNull($account->failed_login_first_at);
        $this->assertSame(PlatformLoginThrottle::MAX_FAILED_ATTEMPTS, $this->throttle->attemptsRemaining($account));
    }

    public function testAWindowThatIsStillOpenIsNotForgotten(): void
    {
        $account = $this->lockedAccount();

        $this->now = self::AT_TEN + 7199;

        $this->assertFalse($this->throttle->forgetExpiredWindow($account));
        $this->assertSame(3, (int) $account->failed_login_count, 'Nada que olvidar todavía.');
    }

    /**
     * Dos fallos viejos también se olvidan, no solo los bloqueos. Si no, dos de anteayer más uno de
     * hoy cerrarían la cuenta, y eso no es «tres por cada dos horas».
     */
    public function testOldFailuresBelowTheLimitAreForgottenToo(): void
    {
        $account = $this->account();
        $this->throttle->registerFailure($account);
        $this->throttle->registerFailure($account);

        $this->now = self::AT_TEN + PlatformLoginThrottle::WINDOW_SECONDS + 1;

        $this->assertTrue($this->throttle->forgetExpiredWindow($account));
        $this->assertFalse($this->throttle->registerFailure($account), 'Este es el primero de la ventana nueva.');
        $this->assertFalse($this->throttle->isLocked($account));
    }

    // ========== Un acierto limpia ==========

    public function testASuccessClearsTheCounter(): void
    {
        $account = $this->account();
        $this->throttle->registerFailure($account);
        $this->throttle->registerFailure($account);

        $this->throttle->clear($account);

        $this->assertSame(0, (int) $account->failed_login_count);
        $this->assertNull($account->failed_login_first_at);
        $this->assertFalse($this->throttle->isLocked($account));
        $this->assertSame(PlatformLoginThrottle::MAX_FAILED_ATTEMPTS, $this->throttle->attemptsRemaining($account));
    }

    public function testClearingALockedAccountLetsItInImmediately(): void
    {
        $account = $this->lockedAccount();

        $this->assertTrue($this->throttle->isLocked($account));
        $this->throttle->clear($account);
        $this->assertFalse($this->throttle->isLocked($account), 'Es lo que hace el desbloqueo de otro superadministrador.');
    }

    // ========== La zona horaria ==========

    /**
     * La trampa de este archivo. `failed_login_first_at` es un DATETIME sin zona: quien lo escribe
     * decide en qué zona significa eso y quien lo lee vuelve a decidirlo. Si las dos decisiones se
     * separan, la ventana se desplaza HORAS -- un freno caducado que sigue cerrado, o uno vigente
     * que deja pasar.
     *
     * La clase captura la zona al construir y la usa para escribir y para leer, así que un cambio
     * de `date_default_timezone_set()` a media petición no puede moverla.
     */
    public function testTheWindowDoesNotMoveWhenTheProcessTimezoneChangesMidRequest(): void
    {
        $original = date_default_timezone_get();

        try {
            date_default_timezone_set('America/Bogota');

            $throttle = new PlatformLoginThrottle(fn (): int => $this->now);
            $account  = $this->account();

            $throttle->registerFailure($account);
            $throttle->registerFailure($account);
            $throttle->registerFailure($account);

            // Catorce horas de diferencia con Bogotá: si la ventana se leyera con la zona del
            // proceso en vez de con la capturada, este salto la movería medio día.
            date_default_timezone_set('Asia/Tokyo');

            $this->now = self::AT_TEN + 3600;
            $this->assertTrue($throttle->isLocked($account), 'Sigue cerrada una hora después, mire quien mire.');
            $this->assertSame(self::AT_TEN + PlatformLoginThrottle::WINDOW_SECONDS, $throttle->lockedUntil($account));

            $this->now = self::AT_TEN + PlatformLoginThrottle::WINDOW_SECONDS + 1;
            $this->assertFalse($throttle->isLocked($account), 'Y se cura en el instante correcto.');
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function testAnExplicitTimezoneIsUsedForBothWritingAndReading(): void
    {
        $throttle = new PlatformLoginThrottle(fn (): int => $this->now, null, new DateTimeZone('UTC'));
        $account  = $this->account();

        $throttle->registerFailure($account);

        $this->assertSame(
            gmdate('Y-m-d H:i:s', self::AT_TEN),
            $account->failed_login_first_at,
            'Escrito en la zona que se le dio, no en la del proceso.',
        );

        $throttle->registerFailure($account);
        $throttle->registerFailure($account);

        $this->now = self::AT_TEN + PlatformLoginThrottle::WINDOW_SECONDS - 1;
        $this->assertTrue($throttle->isLocked($account), 'Y releído en la misma.');
    }

    // ========== Filas que no deberían existir, pero existen ==========

    /**
     * Una fila ilegible se trata como «sin freno», nunca como «cerrada»: un dato corrupto en esta
     * columna no puede ser motivo para dejar fuera a nadie, y reventar aquí sería reventar en la
     * pantalla de entrada.
     */
    public function testAnUnreadableAnchorDoesNotLockAnybodyOut(): void
    {
        $account = (object) ['id' => 1, 'failed_login_count' => 9, 'failed_login_first_at' => 'esto-no-es-una-fecha'];

        $this->assertFalse($this->throttle->isLocked($account));
        $this->assertFalse($this->throttle->windowHasExpired($account));
    }

    public function testACountWithoutAnAnchorLocksNobody(): void
    {
        $account = (object) ['id' => 1, 'failed_login_count' => 99, 'failed_login_first_at' => null];

        $this->assertFalse($this->throttle->isLocked($account), 'Sin ancla no hay ventana, y sin ventana no hay freno.');
    }

    public function testAnAnchorWithoutEnoughFailuresLocksNobody(): void
    {
        $account = $this->account();
        $this->throttle->registerFailure($account);

        $this->assertFalse($this->throttle->isLocked($account));
        $this->assertSame(2, $this->throttle->attemptsRemaining($account));
    }

    /**
     * Una cuenta que no llegó de la base -- porque se borró entre dos peticiones, por ejemplo --
     * no tiene ni columnas. No debe reventar.
     */
    public function testARowWithoutTheColumnsIsSimplyNotLocked(): void
    {
        $this->assertFalse($this->throttle->isLocked((object) []));
        $this->assertSame(PlatformLoginThrottle::MAX_FAILED_ATTEMPTS, $this->throttle->attemptsRemaining((object) []));
    }

    // ========== Las constantes ==========

    /**
     * D8 dice tres y dos horas. Escrito como aserción y no como comentario, porque el día que
     * alguien las cambie tiene que enterarse aquí y no en producción.
     */
    public function testTheLimitsAreTheOnesDecisionD8Names(): void
    {
        $this->assertSame(3, PlatformLoginThrottle::MAX_FAILED_ATTEMPTS);
        $this->assertSame(2 * 3600, PlatformLoginThrottle::WINDOW_SECONDS);
    }

    /**
     * Importadas del modelo, no copiadas: si algún día dejaran de coincidir, el freno de la
     * contraseña y el del segundo factor contarían distinto sobre las mismas dos columnas.
     */
    public function testTheLimitsAreTheSameOnesThePlatformAccountModelUses(): void
    {
        $this->assertSame(PlatformAccount::MAX_FAILED_ATTEMPTS, PlatformLoginThrottle::MAX_FAILED_ATTEMPTS);
        $this->assertSame(PlatformAccount::LOCKOUT_WINDOW_SECONDS, PlatformLoginThrottle::WINDOW_SECONDS);
    }

    private function lockedAccount(): object
    {
        $account = $this->account();

        $this->throttle->registerFailure($account);
        $this->throttle->registerFailure($account);
        $this->throttle->registerFailure($account);

        return $account;
    }
}
