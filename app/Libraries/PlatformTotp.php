<?php

declare(strict_types=1);

namespace App\Libraries;

use DateTimeImmutable;
use DateTimeZone;
use OTPHP\TOTP;
use ParagonIE\ConstantTime\Base32;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * El segundo factor de D11, envuelto en una sola clase para que nadie tenga que volver a decidir
 * ninguno de sus parámetros.
 *
 * Envuelve `spomky-labs/otphp` (11.5), fijado en composer.lock y dentro de la imagen. Todo lo que
 * aquí se decide -- el tamaño del secreto, la deriva aceptada, el nombre que verá el dueño en su
 * teléfono, cómo se guarda el secreto -- se decide UNA vez, aquí, porque cada uno de esos valores
 * tiene una forma de estar mal que no da error: da códigos que no coinciden, o una entrada en el
 * teléfono que no se puede distinguir de otra, o un secreto truncado en la base.
 *
 * ------------------------------------------------------------------------------------------------
 * POR QUÉ NO SE USA `TOTP::verify()` CON SU `$leeway`
 * ------------------------------------------------------------------------------------------------
 *
 * El requisito es ±1 PERÍODO de 30 s: vale el código de esta ventana y el de la anterior, y no vale
 * el de hace dos. El `$leeway` de otphp no expresa eso: está en SEGUNDOS y compara contra
 * `at($t - $leeway)`, `at($t)` y `at($t + $leeway)`, con la restricción `$leeway < $period`. Con el
 * mayor valor legal, 29, la ventana anterior se pierde justo al final de la actual:
 *
 *     t = 59  ->  t - 29 = 30  ->  contador 1, el MISMO que t. El contador 0 no se comprueba.
 *     t = 31  ->  t - 29 =  2  ->  contador 0. Ese sí.
 *
 * Es decir, el mismo código de hace medio minuto se acepta o se rechaza según en qué segundo del
 * período se pulse el botón. Un fallo intermitente en la pantalla de entrada, que nadie reproduce
 * y que se acaba echándole al reloj del teléfono.
 *
 * Por eso aquí se calcula `at($t - 30)`, `at($t)` y `at($t + 30)` explícitamente: son los tres
 * contadores contiguos, siempre, sea cual sea el segundo.
 *
 * ------------------------------------------------------------------------------------------------
 * EL SECRETO: 160 BITS, NO LOS 512 QUE TRAE otphp POR DEFECTO
 * ------------------------------------------------------------------------------------------------
 *
 * `TOTP::generate()` genera 64 bytes, que en base32 son 104 caracteres. Aquí NO hay código QR --
 * no hay librería de QR en el repositorio y no se añade una--, así que **el dueño teclea la clave
 * a mano**. 104 caracteres tecleados desde una pantalla es una fuente de errores por sí sola, y
 * además hay aplicaciones que no aceptan secretos tan largos.
 *
 * 20 bytes son los 160 bits que recomienda el RFC 4226 §4 (R6) y que asume el RFC 6238: 32
 * caracteres base32, exactos, sin relleno `=`, ocho grupos de cuatro. Es lo que genera cualquier
 * servicio grande y lo que toda aplicación acepta.
 *
 * ------------------------------------------------------------------------------------------------
 * CÓMO SE GUARDA: `service('encrypter')->encrypt()` Y NADA MÁS ENCIMA
 * ------------------------------------------------------------------------------------------------
 *
 * Sin `base64_encode()` alrededor. Con `rawData=false` el encrypter YA devuelve texto imprimible
 * (HMAC en hexadecimal + cifrado en base64); el encode extra casi dobla la longitud, desbordó
 * `tenants.db_password` en VARCHAR(255) y MySQL truncó el sobrante sin decir una palabra. El
 * descifrado falló mucho después y en otro sitio. La columna `totp_secret` es VARCHAR(512)
 * precisamente por eso -- ver la migración 20260902000001.
 *
 * ------------------------------------------------------------------------------------------------
 * EL RELOJ
 * ------------------------------------------------------------------------------------------------
 *
 * TOTP cuenta desde el epoch UTC: **no depende de la zona horaria**, depende de que el reloj del
 * servidor esté en hora. Si el VPS pierde el NTP, los códigos empiezan a fallar sin ninguna
 * explicación en pantalla. `Platform.totp_clock_note` dice justo eso al operador.
 *
 * El reloj se inyecta para que las pruebas no dependan de `sleep()`, y se le pasa a otphp como un
 * PSR-20 ClockInterface: su constructor emite un `E_USER_DEPRECATED` si se le entrega `null`, y
 * phpunit.xml.dist corre con `failOnWarning="true"`.
 */
final class PlatformTotp
{
    /**
     * Los tres parámetros que tienen que coincidir con la aplicación del teléfono. Son los valores
     * por omisión del estándar, y por eso NO viajan en la URI (`filterOptions()` de otphp los quita
     * cuando son los de siempre): una URI sin `algorithm`, `digits` ni `period` es exactamente la
     * que emiten los servicios grandes y la que toda aplicación entiende igual.
     */
    public const PERIOD = 30;

    public const DIGITS = 6;
    public const DIGEST = 'sha1';

    /**
     * 160 bits: RFC 4226 §4 (R6). En base32 son 32 caracteres justos, sin relleno.
     */
    public const SECRET_BYTES = 20;

    /**
     * ±1 período. Un período entero de deriva a cada lado, ni un segundo más: con 6 dígitos y una
     * ventana de 30 s, cada período extra multiplica por otro tanto las combinaciones que un
     * atacante acierta por minuto.
     */
    public const DRIFT_PERIODS = 1;

    /**
     * Lo que el dueño verá como nombre de la entrada en su teléfono, junto a su correo.
     *
     * Sin dos puntos (otphp los rechaza en el emisor: parten la etiqueta de la URI) y sin nada
     * que dependa del entorno, para que la entrada se llame igual hoy y después de cualquier
     * mudanza de dominio. Consecuencia conocida y aceptada: quien se dé de alta en staging Y en
     * producción tendrá dos entradas con el mismo nombre y tendrá que renombrar una en su app.
     */
    public const ISSUER = 'OSPOS Plataforma';

    /**
     * @var callable():int
     */
    private $clock;

    /**
     * @param (callable():int)|null $clock devuelve el instante actual como epoch UNIX. Se inyecta
     *                                     para que las pruebas puedan situarse en cualquier
     *                                     ventana sin esperar 30 segundos reales.
     */
    public function __construct(?callable $clock = null, private readonly string $issuer = self::ISSUER)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function now(): int
    {
        return ($this->clock)();
    }

    // ===================== El secreto =====================

    /**
     * Un secreto nuevo, en base32 mayúsculas y sin relleno `=`.
     *
     * 20 bytes de `random_bytes()` dan 160 bits, que son 32 caracteres base32 exactos -- la
     * división es justa, así que no hay relleno que quitar y la longitud es siempre la misma.
     */
    public function generateSecret(): string
    {
        return rtrim(Base32::encodeUpper(random_bytes(self::SECRET_BYTES)), '=');
    }

    /**
     * El secreto partido en grupos de cuatro, que es como se lee en voz alta y como se teclea sin
     * perder el sitio. La aplicación ignora los espacios; quien lo copie con ellos dentro tampoco
     * se equivoca, porque tanto otphp como esta clase normalizan antes de usarlo.
     */
    public function formatSecretForDisplay(string $secret): string
    {
        return trim(chunk_split($this->normaliseSecret($secret), 4, ' '));
    }

    /**
     * Cifrado para la columna `totp_secret`. UNA sola capa, ver la cabecera de esta clase.
     */
    public function encryptSecret(string $secret): string
    {
        return service('encrypter')->encrypt($this->normaliseSecret($secret));
    }

    /**
     * @return string|null null cuando la columna está vacía o el texto cifrado no se puede abrir
     *                     -- clave rotada, fila truncada, basura. Devolver null en vez de reventar
     *                     es deliberado: quien llama tiene que poder decir "ese código no vale" sin
     *                     enseñarle una traza a nadie en la pantalla de entrada.
     */
    public function decryptSecret(?string $encrypted): ?string
    {
        if ($encrypted === null || $encrypted === '') {
            return null;
        }

        try {
            $secret = service('encrypter')->decrypt($encrypted);
        } catch (Throwable) {
            return null;
        }

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    // ===================== La URI para el teléfono =====================

    /**
     * La URI `otpauth://` que la aplicación del teléfono entiende.
     *
     * Sale con la forma `otpauth://totp/Emisor%3Aetiqueta?issuer=Emisor&secret=...`. El emisor va
     * DOS veces a propósito, delante de la etiqueta y como parámetro: es lo que pide la
     * especificación de Key Uri Format de Google, y las aplicaciones antiguas leen uno y las nuevas
     * el otro. Sin `algorithm`, `digits` ni `period`, porque son los del estándar y otphp los omite
     * -- una URI más corta y exactamente la que emite todo el mundo.
     *
     * @param string $label lo que distinguirá una cuenta de otra dentro de la misma entrada:
     *                      el correo del superadministrador. Sin dos puntos (otphp los rechaza,
     *                      porque partirían la etiqueta).
     */
    public function provisioningUri(string $secret, string $label): string
    {
        $totp = $this->totp($secret);
        $totp->setLabel($label);
        $totp->setIssuer($this->issuer);

        return $totp->getProvisioningUri();
    }

    public function issuer(): string
    {
        return $this->issuer;
    }

    // ===================== Los códigos =====================

    /**
     * El código de seis dígitos de la ventana en la que cae $timestamp.
     */
    public function codeAt(string $secret, int $timestamp): string
    {
        return $this->totp($secret)->at(max(0, $timestamp));
    }

    public function currentCode(string $secret): string
    {
        return $this->codeAt($secret, $this->now());
    }

    public function verify(string $secret, string $code): bool
    {
        return $this->verifyAt($secret, $code, $this->now());
    }

    /**
     * ±1 período exacto, calculado sobre los tres contadores contiguos. Ver la cabecera de la clase
     * para por qué no se usa el `$leeway` de otphp.
     *
     * El bucle NO corta al primer acierto: recorrer siempre los tres candidatos hace que el tiempo
     * de respuesta no dependa de CUÁL de ellos acertó, y la comparación es `hash_equals`. Un código
     * de seis dígitos no es un secreto largo, pero el que se filtra por el reloj se filtra igual.
     */
    public function verifyAt(string $secret, string $code, int $timestamp): bool
    {
        $code = $this->normaliseCode($code);

        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $totp    = $this->totp($secret);
        $matched = false;

        for ($step = -self::DRIFT_PERIODS; $step <= self::DRIFT_PERIODS; $step++) {
            $at = $timestamp + ($step * self::PERIOD);

            if ($at < 0) {
                continue;
            }

            if (hash_equals($totp->at($at), $code)) {
                $matched = true;
            }
        }

        return $matched;
    }

    /**
     * ¿Esto que se tecleó tiene siquiera forma de código TOTP?
     *
     * Lo usa la pantalla del reto para decidir si lo que llegó es un código de la aplicación o uno
     * de rescate, que se escriben en el mismo campo (ver el comentario de la ruta
     * `platform/login/totp` en app/Config/Routes.php).
     */
    public function looksLikeCode(string $code): bool
    {
        return strlen($this->normaliseCode($code)) === self::DIGITS;
    }

    // ===================== Interno =====================

    /**
     * Los espacios existen porque las aplicaciones muestran «123 456», y quien lo copia se los
     * lleva. No llevan información y quitarlos no debilita nada.
     */
    private function normaliseCode(string $code): string
    {
        return (string) preg_replace('/\D/', '', $code);
    }

    private function normaliseSecret(string $secret): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z2-7]/', '', $secret));
    }

    private function totp(string $secret): TOTP
    {
        $totp = TOTP::createFromSecret($this->normaliseSecret($secret), $this->psrClock());
        $totp->setPeriod(self::PERIOD);
        $totp->setDigits(self::DIGITS);
        $totp->setDigest(self::DIGEST);

        return $totp;
    }

    /**
     * otphp 11.3 avisa por deprecación si se construye un TOTP sin reloj, y phpunit.xml.dist corre
     * con `failOnWarning="true"`: una deprecación tumbaría la suite entera. Aquí no se usa ninguna
     * de las operaciones de otphp que consultan el reloj -- todo pasa por `at($timestamp)` con el
     * instante explícito-- pero el constructor lo exige igual, así que se le entrega el mismo
     * reloj inyectado y nunca puede haber dos nociones distintas de "ahora".
     */
    private function psrClock(): ClockInterface
    {
        $clock = $this->clock;

        return new class ($clock) implements ClockInterface {
            /**
             * @param callable():int $clock
             */
            public function __construct(private $clock)
            {
            }

            public function now(): DateTimeImmutable
            {
                return (new DateTimeImmutable('@' . ($this->clock)()))->setTimezone(new DateTimeZone('UTC'));
            }
        };
    }
}
