<?php

declare(strict_types=1);

namespace Tests\Libraries;

use App\Libraries\PlatformTotp;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Encryption as EncryptionConfig;
use ParagonIE\ConstantTime\Base32;

/**
 * El segundo factor (D11): que los códigos sean los del RFC, que la ventana sea la que se prometió,
 * y que el secreto quepa en su columna.
 *
 * Sin base de datos. Todo lo de aquí es cálculo, y el cifrado se hace contra una clave puesta en
 * `Config\Encryption` dentro de la propia prueba.
 *
 * ------------------------------------------------------------------------------------------------
 * POR QUÉ HAY VECTORES DEL RFC EN UNA PRUEBA DE ESTE PROYECTO
 * ------------------------------------------------------------------------------------------------
 *
 * Porque el otro extremo de este cálculo es la aplicación del teléfono del dueño, y no está aquí
 * para preguntarle. Los vectores del RFC 6238 (apéndice B) son el único árbitro común: si estos
 * cinco salen, lo que calcula esta clase es lo mismo que calcula cualquier aplicación del mundo, y
 * un fallo en la certificación ya no podrá ser del algoritmo.
 *
 * Los del RFC están publicados a 8 dígitos; aquí se comparan sus últimos 6, que es lo que produce
 * la misma truncación con `digits = 6`.
 *
 * @internal
 */
final class PlatformTotpTest extends CIUnitTestCase
{
    /**
     * El secreto de los vectores del RFC 6238: la cadena ASCII "12345678901234567890", 20 bytes,
     * en base32.
     */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /**
     * Un instante fijo, a mitad de su período de 30 s, para que ninguna prueba dependa de en qué
     * segundo del minuto se ejecutó.
     */
    private const NOW = 1_788_192_015;

    private PlatformTotp $totp;

    protected function setUp(): void
    {
        parent::setUp();

        // El entorno de pruebas no trae clave de cifrado. Sin ella `encrypt()` revienta, y con una
        // fija las pruebas del cifrado son deterministas en todo menos en el IV, que es lo que
        // tiene que ser aleatorio.
        config(EncryptionConfig::class)->key = str_repeat('k', 32);

        $this->totp = new PlatformTotp(static fn (): int => self::NOW);
    }

    // ========== Los vectores oficiales ==========

    /**
     * @dataProvider rfc6238Vectors
     */
    public function testItProducesTheCodesOfTheRfc6238TestVectors(int $timestamp, string $expected): void
    {
        $this->assertSame($expected, $this->totp->codeAt(self::RFC_SECRET, $timestamp));
    }

    public static function rfc6238Vectors(): array
    {
        // RFC 6238, apéndice B, columna SHA1. Los ocho dígitos publicados, recortados a seis.
        return [
            'T=59 (1970)'        => [59, '287082'],
            'T=1111111109'       => [1111111109, '081804'],
            'T=1111111111'       => [1111111111, '050471'],
            'T=1234567890'       => [1234567890, '005924'],
            'T=2000000000'       => [2000000000, '279037'],
        ];
    }

    /**
     * Los tres parámetros que tienen que coincidir con la aplicación. Escritos como aserción y no
     * como comentario: cambiar cualquiera de ellos rompe todas las altas ya hechas, en silencio y
     * solo cuando alguien intente entrar.
     */
    public function testTheParametersAreTheStandardOnes(): void
    {
        $this->assertSame(30, PlatformTotp::PERIOD);
        $this->assertSame(6, PlatformTotp::DIGITS);
        $this->assertSame('sha1', PlatformTotp::DIGEST);
    }

    // ========== La ventana de ±1 período ==========

    public function testTheCodeOfTheCurrentWindowIsAccepted(): void
    {
        $code = $this->totp->codeAt(self::RFC_SECRET, self::NOW);

        $this->assertTrue($this->totp->verify(self::RFC_SECRET, $code));
    }

    public function testTheCodeFromThirtySecondsAgoIsStillAccepted(): void
    {
        $code = $this->totp->codeAt(self::RFC_SECRET, self::NOW - 30);

        $this->assertTrue($this->totp->verify(self::RFC_SECRET, $code), 'Un período de deriva es lo prometido.');
    }

    public function testTheCodeFromSixtySecondsAgoIsRefused(): void
    {
        $code = $this->totp->codeAt(self::RFC_SECRET, self::NOW - 60);

        $this->assertFalse($this->totp->verify(self::RFC_SECRET, $code), 'Dos períodos ya no.');
    }

    public function testTheCodeOfTheNextWindowIsAcceptedAndTheOneAfterIsNot(): void
    {
        $this->assertTrue($this->totp->verify(self::RFC_SECRET, $this->totp->codeAt(self::RFC_SECRET, self::NOW + 30)));
        $this->assertFalse($this->totp->verify(self::RFC_SECRET, $this->totp->codeAt(self::RFC_SECRET, self::NOW + 60)));
    }

    /**
     * El caso por el que NO se usa el `$leeway` de otphp.
     *
     * Su margen está en segundos y no puede pasar de 29, así que en el último segundo de un período
     * `t - 29` cae dentro del MISMO período y el anterior no se comprueba: el mismo código de hace
     * medio minuto se acepta o se rechaza según en qué segundo se pulse el botón. Aquí se calculan
     * los tres contadores contiguos, así que el borde no existe.
     */
    public function testThePreviousWindowIsAcceptedEvenAtTheLastSecondOfTheCurrentOne(): void
    {
        $lastSecond = (intdiv(self::NOW, 30) * 30) + 29;
        $totp       = new PlatformTotp(static fn (): int => $lastSecond);
        $previous   = $totp->codeAt(self::RFC_SECRET, $lastSecond - 30);

        $this->assertTrue($totp->verify(self::RFC_SECRET, $previous));
    }

    public function testThePreviousWindowIsAcceptedAlsoAtTheFirstSecondOfTheCurrentOne(): void
    {
        $firstSecond = intdiv(self::NOW, 30) * 30;
        $totp        = new PlatformTotp(static fn (): int => $firstSecond);
        $previous    = $totp->codeAt(self::RFC_SECRET, $firstSecond - 30);

        $this->assertTrue($totp->verify(self::RFC_SECRET, $previous));
    }

    // ========== Lo que se escribe en el campo ==========

    public function testSpacesInTheTypedCodeAreIgnored(): void
    {
        $code = $this->totp->codeAt(self::RFC_SECRET, self::NOW);

        $this->assertTrue($this->totp->verify(self::RFC_SECRET, substr($code, 0, 3) . ' ' . substr($code, 3)));
    }

    public function testSomethingThatIsNotSixDigitsIsRefusedWithoutComputingAnything(): void
    {
        $this->assertFalse($this->totp->verify(self::RFC_SECRET, ''));
        $this->assertFalse($this->totp->verify(self::RFC_SECRET, '12345'));
        $this->assertFalse($this->totp->verify(self::RFC_SECRET, '1234567'));
        $this->assertFalse($this->totp->verify(self::RFC_SECRET, 'ABCDEF'));
    }

    /**
     * `looksLikeCode()` NO se usa para decidir si algo es un código de rescate, y esta prueba es el
     * recordatorio: un código de rescate con exactamente seis dígitos entre sus letras contestaría
     * que sí, y descartarlo por eso lo dejaría fuera justo el día que hace falta.
     */
    public function testLooksLikeCodeOnlyAnswersAboutShape(): void
    {
        $this->assertTrue($this->totp->looksLikeCode('123456'));
        $this->assertTrue($this->totp->looksLikeCode('123 456'));
        $this->assertFalse($this->totp->looksLikeCode('12345'));
        $this->assertTrue($this->totp->looksLikeCode('AB12-34CD-56EF-GHJK'), 'Seis dígitos entre letras: por esto no se usa para descartar.');
    }

    // ========== El secreto ==========

    public function testTheGeneratedSecretIsTheHundredAndSixtyBitsTheRfcRecommends(): void
    {
        $secret = $this->totp->generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret, 'Base32 en mayúsculas, sin relleno.');
        $this->assertSame(PlatformTotp::SECRET_BYTES, strlen(Base32::decodeUpper($secret)));
        $this->assertSame(20, PlatformTotp::SECRET_BYTES, '160 bits: RFC 4226 §4 (R6), y lo que toda aplicación acepta.');
    }

    public function testTwoSecretsAreNeverTheSame(): void
    {
        $this->assertNotSame($this->totp->generateSecret(), $this->totp->generateSecret());
    }

    public function testTheSecretIsShownInGroupsOfFourAndStillWorksWithTheSpacesIn(): void
    {
        $secret = $this->totp->generateSecret();
        $shown  = $this->totp->formatSecretForDisplay($secret);

        $this->assertSame(8, count(explode(' ', $shown)), 'Ocho grupos de cuatro: 32 caracteres para teclear.');
        $this->assertSame(
            $this->totp->codeAt($secret, self::NOW),
            $this->totp->codeAt($shown, self::NOW),
            'Copiado con los espacios dentro, sigue sirviendo.',
        );
    }

    // ========== El cifrado, y la columna ==========

    public function testTheSecretSurvivesARoundTripThroughTheEncrypter(): void
    {
        $secret = $this->totp->generateSecret();

        $this->assertSame($secret, $this->totp->decryptSecret($this->totp->encryptSecret($secret)));
    }

    /**
     * LA PRUEBA QUE EXISTE POR UN INCIDENTE REAL.
     *
     * `tenants.db_password` se guardó con un `base64_encode()` de más encima del cifrado, se pasó
     * de VARCHAR(255), y MySQL truncó el sobrante sin decir nada: el descifrado falló mucho después
     * y en otro sitio. `totp_secret` es VARCHAR(512) para que quepa con holgura, y esto lo comprueba
     * en vez de confiarlo a un comentario en la migración.
     */
    public function testTheEncryptedSecretFitsInTheVarchar512Column(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $encrypted = $this->totp->encryptSecret($this->totp->generateSecret());

            $this->assertLessThanOrEqual(
                512,
                strlen($encrypted),
                'El secreto cifrado tiene que caber en totp_secret VARCHAR(512).',
            );
        }
    }

    /**
     * Y que no lleve una capa de más. Si alguien envolviera esto en `base64_encode()`, el texto
     * dejaría de tener el `$...$` que el encrypter de CodeIgniter produce y esta prueba lo vería.
     */
    public function testTheStoredFormIsTheEncrypterOutputAndNothingElse(): void
    {
        $secret    = $this->totp->generateSecret();
        $encrypted = $this->totp->encryptSecret($secret);

        // Directamente contra el encrypter, sin pasar por decryptSecret(): si la clase hubiera
        // envuelto el resultado en algo, esto fallaría porque habría que quitar la envoltura antes.
        $this->assertSame(
            $secret,
            service('encrypter')->decrypt($encrypted),
            'El encrypter tiene que poder abrirlo sin quitarle ninguna capa primero.',
        );
    }

    public function testAnUnreadableSecretComesBackAsNullInsteadOfThrowing(): void
    {
        $this->assertNull($this->totp->decryptSecret(null));
        $this->assertNull($this->totp->decryptSecret(''));
        $this->assertNull($this->totp->decryptSecret('esto-no-es-nada-cifrado'));
        $this->assertNull($this->totp->decryptSecret(str_repeat('a', 300)));
    }

    // ========== La URI que lee el teléfono ==========

    /**
     * La certificación la hará el dueño con la aplicación de su celular. Si esta URI está mal
     * formada, la aplicación no la acepta y lo descubriríamos en el peor momento posible, así que
     * cada pieza se comprueba por separado.
     */
    public function testTheProvisioningUriHasEveryPieceAnAuthenticatorAppReads(): void
    {
        $secret = $this->totp->generateSecret();
        $uri    = $this->totp->provisioningUri($secret, 'super@micronuba.net');

        $parts = parse_url($uri);
        parse_str($parts['query'] ?? '', $query);

        $this->assertSame('otpauth', $parts['scheme'] ?? null);
        $this->assertSame('totp', $parts['host'] ?? null, 'El tipo va en el host de la URI, no en la ruta.');
        $this->assertSame(
            PlatformTotp::ISSUER . ':super@micronuba.net',
            rawurldecode(ltrim($parts['path'] ?? '', '/')),
            'Emisor y etiqueta separados por dos puntos, que es el Key Uri Format.',
        );
        $this->assertSame($secret, $query['secret'] ?? null, 'En base32, tal cual, sin cifrar.');
        $this->assertSame(
            PlatformTotp::ISSUER,
            $query['issuer'] ?? null,
            'El emisor va también como parámetro: las aplicaciones viejas leen uno y las nuevas el otro.',
        );
    }

    /**
     * `algorithm`, `digits` y `period` NO viajan cuando son los del estándar. No es un descuido:
     * es lo que emiten los servicios grandes, es una URI más corta de leer, y toda aplicación asume
     * exactamente esos valores cuando faltan.
     */
    public function testTheStandardParametersAreLeftOutOfTheUri(): void
    {
        parse_str(
            parse_url($this->totp->provisioningUri($this->totp->generateSecret(), 'super@micronuba.net'), PHP_URL_QUERY) ?: '',
            $query,
        );

        $this->assertArrayNotHasKey('algorithm', $query);
        $this->assertArrayNotHasKey('digits', $query);
        $this->assertArrayNotHasKey('period', $query);
    }

    /**
     * El nombre que verá el dueño en su teléfono. Sin dos puntos -- partirían la etiqueta -- y sin
     * nada que dependa del entorno, para que la entrada se llame igual después de cualquier mudanza
     * de dominio.
     */
    public function testTheIssuerIsReadableAndCarriesNoColon(): void
    {
        $this->assertStringNotContainsString(':', PlatformTotp::ISSUER);
        $this->assertNotSame('', trim(PlatformTotp::ISSUER));
        $this->assertSame(PlatformTotp::ISSUER, $this->totp->issuer());
    }

    /**
     * Que la URI sea, además de bien formada, VERDAD: el secreto que lleva dentro produce el mismo
     * código que verifica esta clase. Es lo que la aplicación del teléfono va a comprobar.
     */
    public function testTheSecretInsideTheUriIsTheOneThatVerifies(): void
    {
        $secret = $this->totp->generateSecret();
        parse_str(parse_url($this->totp->provisioningUri($secret, 'super@micronuba.net'), PHP_URL_QUERY) ?: '', $query);

        $this->assertTrue($this->totp->verify($secret, $this->totp->codeAt((string) $query['secret'], self::NOW)));
    }

    // ========== El reloj ==========

    public function testTheInjectedClockIsTheOnlySourceOfNow(): void
    {
        $this->assertSame(self::NOW, $this->totp->now());
        $this->assertSame($this->totp->codeAt(self::RFC_SECRET, self::NOW), $this->totp->currentCode(self::RFC_SECRET));
    }
}
