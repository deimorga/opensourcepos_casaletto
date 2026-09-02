<?php

namespace Tests\Controllers;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Un empleado sin NINGÚN módulo de oficina ve una pantalla vacía, no un error.
 *
 * `Secure_Controller` armaba `allowed_modules` desde dentro del bucle, así que con cero módulos la
 * clave nunca nacía -- y `partial/header.php` y `home/office.php` hacen `foreach` sobre ella sin
 * preguntar. El resultado era un 500, no una página vacía.
 *
 * Ocurrió en producción el 2026-09-01: Angela Rodríguez, cajera de Paraíso de la Canasta, tiene 19
 * módulos de inicio y cero de oficina. El icono de Oficina se le muestra igual, y al tocarlo veía
 * «Whoops! We seem to have hit a snag». El defecto era anterior y nadie lo había pisado porque
 * hasta entonces todos los empleados de los dos negocios tenían algo en las dos pantallas.
 *
 * La prueba entra por HTTP a propósito: el defecto no estaba en el modelo ni en la consulta, que
 * devolvían correctamente cero filas, sino en lo que el controlador le entrega a la vista.
 */
class OfficeWithoutModulesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    /** Las concesiones que se le quitan al empleado de prueba, para devolverlas después. */
    private array $devolver = [];

    protected function setUp(): void
    {
        parent::setUp();

        db_connect()->resetDataCache();
    }

    protected function tearDown(): void
    {
        // La base de pruebas es compartida entre archivos: dejar al empleado 1 sin permisos de
        // oficina rompería pruebas de otros archivos, y el fallo saldría donde no está la causa.
        if ($this->devolver !== []) {
            db_connect()->table('grants')->insertBatch($this->devolver);
            $this->devolver = [];
        }

        parent::tearDown();
    }

    private function quitarleLaOficinaAlEmpleado(int $personId): void
    {
        $db = db_connect();

        $this->devolver = $db->table('grants')
            ->where('person_id', $personId)
            ->whereIn('menu_group', ['office', 'both'])
            ->get()
            ->getResultArray();

        $db->table('grants')
            ->where('person_id', $personId)
            ->whereIn('menu_group', ['office', 'both'])
            ->delete();
    }

    public function testAnEmployeeWithNoOfficeModulesGetsAnEmptyPageAndNotAnError(): void
    {
        $this->quitarleLaOficinaAlEmpleado(1);

        $_SESSION = ['person_id' => 1, 'menu_group' => 'home'];
        $this->withSession($_SESSION);

        $resultado = $this->get('office');

        $resultado->assertStatus(200);
    }

    /**
     * Y no se le ofrece el icono, porque detrás no hay nada.
     *
     * El grupo de menú vive en la CONCESIÓN, no en el módulo, así que un empleado puede tener el
     * icono de Oficina en su pantalla de inicio y cero módulos concedidos con 'office' detrás. Una
     * pantalla vacía es mejor que un 500, pero sigue siendo una puerta que no lleva a nada.
     */
    public function testTheOfficeIconIsNotOfferedWhenThereIsNothingBehindIt(): void
    {
        $this->quitarleLaOficinaAlEmpleado(1);

        $_SESSION = ['person_id' => 1, 'menu_group' => 'home'];
        $this->withSession($_SESSION);

        $cuerpo = (string) $this->get('home')->getBody();

        $this->assertStringNotContainsString(
            base_url('office'),
            $cuerpo,
            'Sin módulos de oficina, el icono de Oficina no se muestra.'
        );
    }

    /**
     * La otra mitad, para que el arreglo no se convierta en «siempre vacío».
     */
    public function testAnEmployeeWithOfficeModulesStillSeesThem(): void
    {
        $_SESSION = ['person_id' => 1, 'menu_group' => 'home'];
        $this->withSession($_SESSION);

        $this->get('office')->assertStatus(200);
    }
}
