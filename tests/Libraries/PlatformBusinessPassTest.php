<?php

declare(strict_types=1);

namespace Tests\Libraries;

use App\Libraries\Platform_business_pass;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\OSPOS;

/**
 * El pase que abre la caja de un cliente.
 *
 * Lo que hay que demostrar de él no es que funcione --eso es lo fácil-- sino que **deje de
 * funcionar**: a la segunda vez, pasado el minuto, y para el negocio equivocado. Un pase que sirva
 * dos veces es un enlace en el historial del navegador que abre la caja de un cliente mañana.
 *
 * @internal
 */
final class PlatformBusinessPassTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const TABLA = 'platform_business_passes';

    private Platform_business_pass $pases;

    protected function setUp(): void
    {
        parent::setUp();

        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();

        // A mano y no con el runner: el grupo `platform` comparte esquema con el de pruebas, y
        // correr ese namespace entero chocaría con las tablas que levantan otros archivos.
        $platform = db_connect('platform');
        $platform->query('DROP TABLE IF EXISTS `' . self::TABLA . '`');
        $platform->query(
            'CREATE TABLE `' . self::TABLA . '` (
                token_hash CHAR(64) NOT NULL PRIMARY KEY,
                account_id INT UNSIGNED NOT NULL,
                tenant_id INT UNSIGNED NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL
            )',
        );
        $platform->resetDataCache();

        $this->pases = new Platform_business_pass();
    }

    protected function tearDown(): void
    {
        db_connect('platform')->query('DROP TABLE IF EXISTS `' . self::TABLA . '`');
        db_connect('platform')->resetDataCache();

        parent::tearDown();
    }

    private function filas(): int
    {
        return db_connect('platform')->table(self::TABLA)->countAllResults();
    }

    // ========== Que funcione ==========

    public function testAFreshPassOpensTheBusinessItWasMintedFor(): void
    {
        $pase = $this->pases->mint(7, 3);

        $this->assertSame(['account_id' => 7, 'tenant_id' => 3], $this->pases->redeem($pase));
    }

    // ========== Y sobre todo, que deje de funcionar ==========

    /**
     * LA PRUEBA CENTRAL. El pase viaja en una URL, así que queda en el historial del navegador. Si
     * sirviera dos veces, ese historial abriría la caja de un cliente mañana.
     */
    public function testAPassWorksExactlyOnce(): void
    {
        $pase = $this->pases->mint(7, 3);

        $this->assertNotNull($this->pases->redeem($pase));
        $this->assertNull($this->pases->redeem($pase), 'Un segundo canje no puede abrir nada.');
    }

    public function testRedeemingItRemovesTheRow(): void
    {
        $pase = $this->pases->mint(7, 3);
        $this->pases->redeem($pase);

        $this->assertSame(0, $this->filas(), 'No basta con rechazarlo: la fila se va.');
    }

    public function testAnExpiredPassIsRefused(): void
    {
        $pase = $this->pases->mint(7, 3);

        // Se envejece la fila en la base en vez de esperar sesenta segundos.
        db_connect('platform')->table(self::TABLA)->update([
            'expires_at' => date('Y-m-d H:i:s', time() - 1),
        ]);

        $this->assertNull($this->pases->redeem($pase));
    }

    /**
     * Y un pase caducado también se tacha al presentarse. Si se dejara en la tabla, cada intento
     * volvería a leerlo -- y una fila que sigue ahí después de haber sido rechazada es la clase de
     * cosa que alguien "arregla" mañana relajando la comprobación de la fecha.
     */
    public function testAnExpiredPassIsAlsoRemoved(): void
    {
        $pase = $this->pases->mint(7, 3);
        db_connect('platform')->table(self::TABLA)->update(['expires_at' => date('Y-m-d H:i:s', time() - 1)]);

        $this->pases->redeem($pase);

        $this->assertSame(0, $this->filas());
    }

    public function testAnInventedPassOpensNothing(): void
    {
        $this->assertNull($this->pases->redeem('esto-no-lo-emitimos-nosotros'));
        $this->assertNull($this->pases->redeem(''));
    }

    // ========== Lo que se guarda, y lo que no ==========

    /**
     * Esta tabla abre la caja de un cliente. Si alguien la lee --un respaldo, una consulta de más--
     * lo que encuentre no puede servirle para entrar.
     */
    public function testThePassItselfIsNeverStored(): void
    {
        $pase = $this->pases->mint(7, 3);
        $fila = db_connect('platform')->table(self::TABLA)->get()->getRow();

        $this->assertNotSame($pase, $fila->token_hash);
        $this->assertSame(hash('sha256', $pase), $fila->token_hash);
        $this->assertSame(64, strlen($fila->token_hash));
    }

    public function testTwoPassesAreNeverTheSame(): void
    {
        $vistos = [];

        for ($i = 0; $i < 50; $i++) {
            $vistos[] = $this->pases->mint(7, 3);
        }

        $this->assertCount(50, array_unique($vistos));
    }

    public function testAPassFitsInAUrlWithoutEscaping(): void
    {
        $pase = $this->pases->mint(7, 3);

        $this->assertSame($pase, rawurlencode($pase), 'Si hay que escaparlo, alguien lo escapará mal algún día.');
    }

    // ========== La limpieza ==========

    public function testMintingSweepsTheExpiredOnes(): void
    {
        $this->pases->mint(7, 3);
        db_connect('platform')->table(self::TABLA)->update(['expires_at' => date('Y-m-d H:i:s', time() - 120)]);

        $this->pases->mint(7, 3);

        $this->assertSame(1, $this->filas(), 'El caducado se barre al emitir el siguiente.');
    }

    public function testAValidPassIsNotSweptWhenAnotherIsMinted(): void
    {
        $primero = $this->pases->mint(7, 3);
        $this->pases->mint(8, 4);

        $this->assertNotNull($this->pases->redeem($primero), 'Barrer no puede llevarse los que valen.');
    }
}
