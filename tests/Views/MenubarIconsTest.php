<?php

declare(strict_types=1);

namespace Tests\Views;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Que cada módulo registrado tenga su icono, que no está en el repositorio.
 *
 * `public/images/menubar/` **es salida de build y está entero en .gitignore** (`.gitignore:5`, con
 * solo `.gitkeep` versionado). Los iconos los produce la tarea `copy-menubar` del gulpfile,
 * renombrando archivos del paquete npm `elegant-circles`.
 *
 * De ahí el fallo silencioso que esta prueba existe para atrapar:
 *
 *   registrar un módulo en la base y olvidar su línea en gulpfile.js deja una imagen rota en la
 *   barra superior, en el mosaico de Inicio y en el de Oficina --- y no da ningún error, ni en el
 *   build ni en la página, así que puede vivir semanas sin que nadie lo note.
 *
 * Las vistas arman la ruta desde el `module_id` (`app/Views/partial/header.php:109`,
 * `home/home.php:18`, `home/office.php:18`), así que la comprobación es exactamente esa: para cada
 * `module_id` de la tabla, un `rename("<module_id>.svg")` en el gulpfile.
 *
 * Se lee el gulpfile como texto y no se corre el build: `npm run build` necesita node_modules y
 * tarda, y lo que puede fallar aquí es la línea ausente, no el copiado.
 *
 * @internal
 */
final class MenubarIconsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private string $gulpfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gulpfile = file_get_contents(ROOTPATH . 'gulpfile.js');
    }

    /**
     * La prueba que habría evitado el descubrimiento tardío, y la que va a fallar la próxima vez que
     * alguien registre un módulo sin icono.
     */
    public function testEveryRegisteredModuleHasAnIconLineInTheBuild(): void
    {
        $modules = array_column(
            Database::connect()->table('modules')->select('module_id')->get()->getResultArray(),
            'module_id'
        );

        $this->assertNotEmpty($modules, 'Sin módulos no hay nada que comprobar y la prueba no valdría nada.');

        foreach ($modules as $module_id) {
            $this->assertStringContainsString(
                'rename("' . $module_id . '.svg")',
                $this->gulpfile,
                "El módulo \"$module_id\" no tiene línea en la tarea copy-menubar del gulpfile, así que su icono "
                . 'no se genera y la barra superior pinta una imagen rota. public/images/menubar está en .gitignore: '
                . 'dejar el SVG en el directorio a mano no lo arregla para nadie más.'
            );
        }
    }

    /**
     * El icono de comandas, nombrado aparte porque es el que este trabajo agrega.
     */
    public function testTheOrderTicketIconIsBuilt(): void
    {
        $this->assertStringContainsString('rename("order_tickets.svg")', $this->gulpfile);
    }

    /**
     * Cada línea de copy-menubar tiene que citar un archivo que el paquete de iconos realmente trae.
     * Un nombre mal escrito produce exactamente el mismo síntoma que la línea ausente.
     *
     * Se salta en limpio si no hay node_modules: la suite de CI instala composer, no npm.
     */
    public function testEveryIconLineNamesAFileThePackageActuallyShips(): void
    {
        $package = ROOTPATH . 'node_modules/elegant-circles/svg/full-color/';

        if (!is_dir($package)) {
            $this->markTestSkipped('node_modules no está instalado; el nombre del origen se comprueba en el build.');
        }

        preg_match_all(
            '#full-color/([a-z0-9\-]+\.svg)["\']\s*\)\s*,\s*rename\("([a-z0-9_]+\.svg)"#i',
            $this->gulpfile,
            $matches,
            PREG_SET_ORDER
        );

        $this->assertNotEmpty($matches, 'No se reconoció ninguna línea de copy-menubar; el patrón del gulpfile cambió.');

        foreach ($matches as [, $source, $target]) {
            $this->assertFileExists(
                $package . $source,
                "copy-menubar copia \"$source\" para producir \"$target\", y el paquete no trae ese archivo."
            );
        }
    }
}
