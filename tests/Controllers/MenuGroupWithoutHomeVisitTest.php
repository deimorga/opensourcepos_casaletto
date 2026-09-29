<?php

namespace Tests\Controllers;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * La primera pantalla después del ingreso muestra el menú de Inicio, aunque no sea Inicio.
 *
 * Desde que un celular con comandas entra directo a /comandas, la sesión no trae `menu_group` (solo
 * lo fijan Home y Office). Secure_Controller lo tomaba como oficina: Rodrigo Tovar, con todos sus
 * módulos en Inicio y ninguno en Oficina, veía el menú vacío (producción, 2026-09-29).
 */
class MenuGroupWithoutHomeVisitTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    protected function setUp(): void
    {
        parent::setUp();

        db_connect()->resetDataCache();
    }

    private function homeModuleIds(int $personId): array
    {
        return array_column(
            model(\App\Models\Module::class)->get_allowed_home_modules($personId)->getResultArray(),
            'module_id'
        );
    }

    public function testASessionWithNoMenuGroupShowsTheHomeMenu(): void
    {
        $home = $this->homeModuleIds(1);
        $this->assertNotEmpty($home, 'El empleado 1 necesita módulos de inicio para que la prueba diga algo.');

        $_SESSION = ['person_id' => 1];
        $this->withSession($_SESSION);

        $cuerpo = (string) $this->get('customers')->getBody();

        foreach ($home as $moduleId) {
            if ($moduleId === 'office') {
                continue; // Se oculta cuando no hay nada detrás; lo prueba OfficeWithoutModulesTest.
            }
            $this->assertStringContainsString('href="' . base_url($moduleId) . '"', $cuerpo, "Falta «{$moduleId}» en el menú.");
        }
    }

    public function testAnExplicitOfficeGroupIsStillHonoured(): void
    {
        $_SESSION = ['person_id' => 1, 'menu_group' => 'office'];
        $this->withSession($_SESSION);

        $cuerpo = (string) $this->get('customers')->getBody();

        foreach (array_column(model(\App\Models\Module::class)->get_allowed_office_modules(1)->getResultArray(), 'module_id') as $moduleId) {
            $this->assertStringContainsString('href="' . base_url($moduleId) . '"', $cuerpo);
        }
    }
}
