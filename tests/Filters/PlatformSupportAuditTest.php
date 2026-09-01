<?php

declare(strict_types=1);

namespace Tests\Filters;

use App\Filters\PlatformSupportAudit;
use App\Libraries\Platform_business_entry;
use CodeIgniter\HTTP\Request;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * El registro de nivel 2, y sobre todo lo que NUNCA debe escribir.
 *
 * LA PRUEBA QUE JUSTIFICA ESTE ARCHIVO
 *
 * Este filtro observa peticiones POST del punto de venta de un cliente. Algunas llevan una
 * contraseña dentro --`/employees/save` la lleva-- y lo que escriba acaba en `platform_activity`,
 * que es una tabla que leen personas y que se guarda para siempre. Si un día alguien decide que
 * «sería útil guardar el cuerpo», esta prueba tiene que ponerse roja.
 *
 * @internal
 */
final class PlatformSupportAuditTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        session()->destroy();
    }

    protected function tearDown(): void
    {
        session()->destroy();
        parent::tearDown();
    }

    /**
     * Una petición de mentira con solo lo que este filtro le pide: `getPost()` y la URI.
     *
     * Se hace así y no construyendo una `IncomingRequest` de verdad porque lo que se prueba es la
     * REGLA --qué campos se recogen y cuáles no--, no el andamiaje de CodeIgniter. Una prueba que
     * dependa de cómo se rellenan las globales acaba fallando por algo que no tiene que ver.
     */
    private function peticion(array $cuerpo, string $ruta = '/employees/save/3'): RequestInterface
    {
        return new class ($cuerpo, $ruta) extends Request {
            public function __construct(private array $cuerpo, private string $ruta)
            {
                parent::__construct(new App());
            }

            public function getPost($index = null, $filter = null, $flags = null)
            {
                return $index === null ? $this->cuerpo : ($this->cuerpo[$index] ?? null);
            }

            public function getMethod(bool $upper = false): string
            {
                return 'POST';
            }

            public function getUri(): URI
            {
                return new URI('http://negocio.ejemplo/' . ltrim($this->ruta, '/'));
            }
        };
    }

    private function detalleRegistrado(array $cuerpo): array
    {
        $filtro = new PlatformSupportAudit();

        $reflexion = new \ReflectionMethod($filtro, 'identificadores');
        $reflexion->setAccessible(true);

        return $reflexion->invoke($filtro, $this->peticion($cuerpo));
    }

    // ========== Lo que nunca puede salir ==========

    public function testAPasswordIsNeverRecorded(): void
    {
        $detalle = $this->detalleRegistrado([
            'id'               => '3',
            'username'         => 'jmunoz',
            'password'         => 'la-contrasena-del-cliente',
            'repeat_password'  => 'la-contrasena-del-cliente',
        ]);

        $plano = json_encode($detalle, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('la-contrasena-del-cliente', $plano);
        $this->assertArrayNotHasKey('password', $detalle);
        $this->assertArrayNotHasKey('repeat_password', $detalle);
    }

    public function testNothingOutsideTheIdentityWhitelistIsRecorded(): void
    {
        $detalle = $this->detalleRegistrado([
            'id'          => '3',
            'first_name'  => 'Nombre real de una persona',
            'email'       => 'cliente@ejemplo.com',
            'comments'    => 'algo que escribió el cliente',
            'unit_price'  => '12500',
        ]);

        $this->assertSame(['id' => '3'], $detalle, 'Solo identificadores. Ni un valor más.');
    }

    // ========== Lo que sí ==========

    public function testTheIdentifiersThatTheRouteDoesNotCarryAreRecorded(): void
    {
        // /employees/delete no lleva a quién en la ruta: va en ids[]. Sin esto, una baja quedaría
        // anotada como «alguien hizo POST a /employees/delete» sin decir a quién.
        $detalle = $this->detalleRegistrado(['ids' => ['3', '7', '11']]);

        $this->assertSame('3,7,11', $detalle['ids']);
    }

    public function testALongValueIsCutAndDoesNotFillTheColumn(): void
    {
        $detalle = $this->detalleRegistrado(['id' => str_repeat('9', 500)]);

        $this->assertSame(100, strlen($detalle['id']));
    }

    public function testAnEmptyBodyRecordsNoIdentifiers(): void
    {
        $this->assertSame([], $this->detalleRegistrado([]));
        $this->assertSame([], $this->detalleRegistrado(['id' => '', 'ids' => []]));
    }

    // ========== Cuándo actúa ==========

    public function testItDoesNothingOutsideASupportSession(): void
    {
        $this->assertFalse(Platform_business_entry::isSupportSession());

        $filtro   = new PlatformSupportAudit();
        $respuesta = new Response(new App());

        // No lanza y no escribe: sin sesión de soporte no hay nada que observar.
        $this->assertNull($filtro->after($this->peticion(['id' => '3']), $respuesta));
    }

    public function testASupportSessionIsExactlyThePresenceOfTheAccountKey(): void
    {
        session()->set(Platform_business_entry::SUPPORT_ACCOUNT_KEY, 7);
        $this->assertTrue(Platform_business_entry::isSupportSession());
        $this->assertSame(7, Platform_business_entry::accountId());

        session()->remove(Platform_business_entry::SUPPORT_ACCOUNT_KEY);
        $this->assertFalse(Platform_business_entry::isSupportSession());
        $this->assertNull(Platform_business_entry::accountId());
    }
}
