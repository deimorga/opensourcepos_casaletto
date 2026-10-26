<?php

declare(strict_types=1);

namespace Tests\Commands;

use App\Commands\PlatformMailTest;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\StreamFilterTrait;

/**
 * `php spark platform:mail-test <correo>`.
 *
 * Lo que importa es el código de salida: este comando es la comprobación de que el correo de la
 * plataforma funciona en un ambiente, y un 0 tiene que significar que el servidor de correo aceptó el
 * mensaje. Por eso se instancia a mano, como en PlatformAccountUnlockTest: el ayudante command()
 * tira el código de retorno.
 *
 * Ninguna prueba llega a un servidor de correo real: la de «no contesta» apunta a un puerto local
 * cerrado, que rechaza al instante.
 *
 * @internal
 */
final class PlatformMailTestCommandTest extends CIUnitTestCase
{
    use StreamFilterTrait;

    private const VARS = ['PLATFORM_SMTP_HOST', 'PLATFORM_SMTP_PORT', 'PLATFORM_MAIL_FROM'];

    /**
     * @var array<string, false|string>
     */
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::VARS as $var) {
            $this->previous[$var] = getenv($var);
            putenv($var);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $var => $value) {
            putenv($value === false ? $var : "{$var}={$value}");
        }

        parent::tearDown();
    }

    public function testWithoutARecipientItSaysHowToUseIt(): void
    {
        $this->assertSame(1, $this->run_with([]));
        $this->assertStringContainsString('platform:mail-test <correo>', $this->getStreamFilterBuffer());
    }

    public function testWithoutConfigurationItSaysWhatIsMissing(): void
    {
        $this->assertSame(1, $this->run_with(['deimorga@gmail.com']));
        $this->assertStringContainsString('PLATFORM_SMTP_HOST', $this->getStreamFilterBuffer());
    }

    public function testAMailServerThatDoesNotAnswerIsAFailureWithItsReason(): void
    {
        putenv('PLATFORM_SMTP_HOST=127.0.0.1');
        putenv('PLATFORM_SMTP_PORT=1');
        putenv('PLATFORM_MAIL_FROM=ospos@micronuba.net');

        $this->assertSame(1, $this->run_with(['deimorga@gmail.com']));
        $this->assertStringContainsString('no aceptó el mensaje', $this->getStreamFilterBuffer());
    }

    /**
     * @param list<string> $params
     */
    private function run_with(array $params): int
    {
        $command = new PlatformMailTest(service('logger'), service('commands'));

        return (int) $command->run($params);
    }
}
