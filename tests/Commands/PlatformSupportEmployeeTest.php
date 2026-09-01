<?php

declare(strict_types=1);

namespace Tests\Commands;

use App\Commands\PlatformSupportEmployee;
use App\Libraries\Platform_support;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\OSPOS;

/**
 * `php spark platform:support-employee [slug]`, que es como los negocios que YA existen --Casaletto
 * entre ellos-- consiguen su empleado de soporte.
 *
 * POR QUÉ SE COMPRUEBA EL CÓDIGO DE SALIDA Y NO SOLO LA BASE
 *
 * Porque esto se va a encadenar detrás de un `&&` o a mirar desde un script de despliegue. Un
 * comando que imprime un error en rojo y devuelve 0 se lee como éxito en todas partes menos en los
 * ojos de quien esté delante -- que es justo el defecto del `migrate` de serie (ver la cabecera de
 * PlatformMigrate.php). Por eso el comando se instancia a mano en vez de usar el ayudante
 * `command()`: ese devuelve el texto y tira el código de retorno.
 *
 * EL NEGOCIO DE PRUEBA ES ADOPTADO, COMO CASALETTO
 *
 * Su fila se siembra con `db_user` vacío, así que la conexión cae a las credenciales compartidas.
 * Es el camino que rompería un código escrito suponiendo que todo negocio tiene usuario de MySQL
 * propio -- y lo rompería justo en el negocio que está vendiendo. Encaja sin forzar nada porque el
 * grupo `platform` apunta al mismo esquema que el de pruebas (§9.7).
 *
 * @internal
 */
final class PlatformSupportEmployeeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const SLUG           = 'prueba-soporte';
    private const SLUG_SUSPENDIDO = 'prueba-soporte-suspendido';
    private const SLUG_ROTO      = 'prueba-soporte-roto';

    protected function setUp(): void
    {
        parent::setUp();

        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();

        $this->crearRegistro();
    }

    protected function tearDown(): void
    {
        db_connect('platform')->query('DROP TABLE IF EXISTS `tenants`');
        db_connect('platform')->resetDataCache();

        // La suite comparte UNA base y no la refresca: las filas que este comando escribe dentro del
        // «negocio» son filas del esquema de pruebas, y se las encontraría el archivo siguiente.
        // Ver §9.16 del documento técnico.
        $this->borrarTodoRastroDeSoporte();

        parent::tearDown();
    }

    // ==================== El camino de siempre ====================

    public function testItCreatesTheSupportEmployeeAndReturnsZero(): void
    {
        $this->assertNull($this->soporte(), 'Antes de correrlo no está: si no, la prueba no probaría nada.');

        $salida = $this->correr();

        $this->assertSame(0, $salida);

        $empleado = $this->soporte();

        $this->assertNotNull($empleado);
        $this->assertSame(1, (int) $empleado->is_platform_support);
        $this->assertSame(0, (int) $empleado->deleted);
        $this->assertStringContainsString(self::SLUG, $this->getStreamFilterBuffer(), 'La salida nombra el negocio que se hizo.');
    }

    /**
     * LA PROPIEDAD QUE LO HACE UTILIZABLE. Esto se corre contra la base de Casaletto, que está
     * vendiendo, y se va a correr más de una vez -- después de cada despliegue, si hace falta.
     */
    public function testRunningItTwiceDoesNotDuplicateRowsOrPermissions(): void
    {
        $this->assertSame(0, $this->correr());

        $personId  = (int) $this->soporte()->person_id;
        $personas  = $this->totalDe('people');
        $empleados = $this->totalDe('employees');
        $permisos  = $this->totalDePermisosDe($personId);

        $this->assertSame(0, $this->correr(), 'La segunda corrida tampoco es un error: el estado que se pedía ya se cumple.');

        $this->assertSame($personas, $this->totalDe('people'));
        $this->assertSame($empleados, $this->totalDe('employees'));
        $this->assertSame($permisos, $this->totalDePermisosDe($personId));

        $this->assertSame(
            1,
            db_connect()->table('employees')->where('is_platform_support', 1)->countAllResults(),
            'Un segundo empleado de soporte sería un segundo juego de llaves que nadie sabe que existe.',
        );
        $this->assertSame(
            $personId,
            (int) $this->soporte()->person_id,
            'Y es el mismo de la primera vez, no uno nuevo con el anterior abandonado detrás.',
        );
    }

    // ==================== A quién le toca ====================

    /**
     * Sin slug se recorren SOLO los activos, que es la misma condición con la que `tenant:list`
     * alimenta al orquestador de migraciones. Nombrándolo, se hace igual: pedir un negocio por su
     * nombre es una decisión deliberada de quien escribe la orden.
     */
    public function testASuspendedBusinessIsSkippedUnlessItIsNamed(): void
    {
        $this->sembrarNegocio(self::SLUG_SUSPENDIDO, db_connect()->getDatabase(), 'suspended');

        // El activo y el suspendido apuntan al MISMO esquema de pruebas, así que si el suspendido se
        // hiciera no habría forma de notarlo por la base. Se quita el activo del registro para que
        // la corrida sin slug no tenga nada más que hacer.
        db_connect('platform')->table('tenants')->where('slug', self::SLUG)->delete();

        $this->assertSame(0, $this->correr(), 'Un registro sin negocios activos no es un error.');
        $this->assertNull($this->soporte(), 'Al suspendido no se le tocó.');

        $this->assertSame(0, $this->correr(self::SLUG_SUSPENDIDO));
        $this->assertNotNull($this->soporte(), 'Nombrándolo sí, porque eso lo pidió una persona.');
    }

    public function testAnUnknownSlugIsAnErrorAndWritesNothing(): void
    {
        $salida = $this->correr('este-negocio-no-existe');

        $this->assertSame(1, $salida);
        $this->assertNull($this->soporte(), 'No se escribió en ninguna parte.');
    }

    /**
     * Un registro vacío es una instalación de un solo negocio (desarrollo local, o un entorno
     * anterior al multi-negocio): no hay nada que hacer y no hay nada roto. Devolver 1 mandaría a
     * buscar un problema que no existe.
     */
    public function testAnEmptyRegistryIsNotAnError(): void
    {
        db_connect('platform')->table('tenants')->truncate();

        $this->assertSame(0, $this->correr());
        $this->assertNull($this->soporte());
    }

    // ==================== Cuando uno falla ====================

    /**
     * Un negocio inalcanzable no puede llevarse por delante a los demás -- pararse en el primero
     * dejaría el resto sin hacer por un problema que puede ser de uno solo --, pero tampoco puede
     * pasar desapercibido: se nombra y el código de salida es 1.
     */
    public function testABusinessThatFailsIsNamedAndDoesNotStopTheOthers(): void
    {
        $this->sembrarNegocio(self::SLUG_ROTO, 'esta_base_no_existe_en_ninguna_parte');

        $salida = $this->correr();

        $this->assertSame(1, $salida, 'Quien encadene esto detrás de un && tiene que enterarse.');
        $this->assertStringContainsString(self::SLUG_ROTO, $this->getStreamFilterBuffer(), 'Y tiene que saber CUÁL falló.');
        $this->assertNotNull($this->soporte(), 'El negocio sano se hizo igual.');
    }

    // ==================== Andamiaje ====================

    private function correr(string $slug = ''): int
    {
        $comando = new PlatformSupportEmployee(service('logger'), service('commands'));

        return (int) $comando->run($slug === '' ? [] : [$slug]);
    }

    private function soporte(): ?object
    {
        return db_connect()->table('employees')
            ->where('username', Platform_support::USERNAME)
            ->get()
            ->getRow();
    }

    private function totalDe(string $tabla): int
    {
        return db_connect()->table($tabla)->countAllResults();
    }

    private function totalDePermisosDe(int $personId): int
    {
        return db_connect()->table('grants')->where('person_id', $personId)->countAllResults();
    }

    /**
     * El registro de negocios, construido a mano y no con el runner de migraciones: el grupo
     * `platform` comparte esquema con el de pruebas y correr ese namespace entero chocaría con las
     * tablas que otros archivos levantan para sí.
     */
    private function crearRegistro(): void
    {
        $platform = db_connect('platform');

        $platform->query('DROP TABLE IF EXISTS `tenants`');
        $platform->query(
            'CREATE TABLE `tenants` (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(100) NOT NULL UNIQUE,
                company_name VARCHAR(255) NULL,
                db_name VARCHAR(100) NOT NULL,
                db_user VARCHAR(100) NOT NULL DEFAULT "",
                db_password VARCHAR(255) NULL,
                status VARCHAR(20) NOT NULL DEFAULT "active",
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )',
        );
        $platform->resetDataCache();

        $this->sembrarNegocio(self::SLUG, (string) db_connect()->getDatabase());
    }

    /** `db_user` vacío: un negocio ADOPTADO, como Casaletto, que cae a las credenciales compartidas. */
    private function sembrarNegocio(string $slug, string $dbName, string $estado = 'active'): void
    {
        db_connect('platform')->table('tenants')->insert([
            'slug'         => $slug,
            'company_name' => 'Negocio de prueba',
            'db_name'      => $dbName,
            'db_user'      => '',
            'status'       => $estado,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    private function borrarTodoRastroDeSoporte(): void
    {
        $filas = db_connect()->table('employees')
            ->select('person_id')
            ->groupStart()
            ->where('username', Platform_support::USERNAME)
            ->orWhere('is_platform_support', 1)
            ->groupEnd()
            ->get()
            ->getResultArray();

        if ($filas === []) {
            return;
        }

        $ids = array_map(static fn (array $fila): int => (int) $fila['person_id'], $filas);

        db_connect()->table('grants')->whereIn('person_id', $ids)->delete();
        db_connect()->table('employees')->whereIn('person_id', $ids)->delete();
        db_connect()->table('people')->whereIn('person_id', $ids)->delete();
    }
}
