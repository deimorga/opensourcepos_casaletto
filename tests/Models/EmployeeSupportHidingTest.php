<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Libraries\Platform_support;
use App\Models\Employee;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\OSPOS;

/**
 * Que el empleado de soporte no se vea, y que **todos los demás sí**.
 *
 * LA PRUEBA QUE IMPORTA NO ES LA PRIMERA, ES LA SEGUNDA
 *
 * Esconder de menos es cosmético: el cliente ve un usuario raro en su lista. Esconder de más oculta a
 * un empleado REAL, y eso se descubre el día que alguien no aparece en un reporte de ventas que sí
 * hizo. Por cada afirmación de «no está» hay otra de «los de siempre siguen estando».
 *
 * @internal
 */
final class EmployeeSupportHidingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private Employee $employee;
    private int $soportePersonId;
    private int $normalPersonId;
    private string $usuarioNormal;

    protected function setUp(): void
    {
        parent::setUp();

        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();

        $this->employee      = model(Employee::class);
        $this->usuarioNormal = 'empleado_normal_' . uniqid();

        $this->soportePersonId = $this->crear(
            Platform_support::personData(),
            Platform_support::employeeData(),
        );

        $this->normalPersonId = $this->crear(
            [
                'first_name' => 'Empleada',
                'last_name'  => 'DeVerdad',
                'phone_number' => '', 'email' => '', 'address_1' => '', 'address_2' => '',
                'city' => '', 'state' => '', 'zip' => '', 'country' => '', 'comments' => '',
            ],
            [
                'username'            => $this->usuarioNormal,
                'password'            => password_hash('la-suya', PASSWORD_DEFAULT),
                'hash_version'        => 2,
                'deleted'             => 0,
                'is_platform_support' => 0,
            ],
        );
    }

    protected function tearDown(): void
    {
        // La suite comparte una sola base y no la refresca: lo que se siembra aquí se lo encuentran
        // las pruebas de otros archivos. Ver §9.16 del documento técnico.
        foreach ([$this->soportePersonId, $this->normalPersonId] as $personId) {
            db_connect()->table('employees')->where('person_id', $personId)->delete();
            db_connect()->table('people')->where('person_id', $personId)->delete();
        }

        parent::tearDown();
    }

    private function crear(array $persona, array $empleado): int
    {
        db_connect()->table('people')->insert($persona);
        $personId = (int) db_connect()->insertID();

        db_connect()->table('employees')->insert($empleado + ['person_id' => $personId]);

        return $personId;
    }

    /** @return list<int> */
    private function idsDe(iterable $filas): array
    {
        $ids = [];

        foreach ($filas as $fila) {
            $ids[] = (int) $fila->person_id;
        }

        return $ids;
    }

    // ========== Que no se vea ==========

    public function testTheSupportEmployeeIsNotInTheList(): void
    {
        $ids = $this->idsDe($this->employee->get_all()->getResult());

        $this->assertNotContains($this->soportePersonId, $ids);
    }

    public function testTheSupportEmployeeIsNotInTheSearch(): void
    {
        $encontrados = $this->employee->search(Platform_support::FIRST_NAME, 100);
        $ids = $this->idsDe(is_object($encontrados) ? $encontrados->getResult() : []);

        $this->assertNotContains($this->soportePersonId, $ids);
    }

    public function testTheSupportEmployeeIsNotInTheSuggestions(): void
    {
        $sugerencias = $this->employee->get_search_suggestions(Platform_support::FIRST_NAME, 25);

        foreach ($sugerencias as $sugerencia) {
            $this->assertStringNotContainsString(
                Platform_support::USERNAME,
                is_array($sugerencia) ? implode(' ', $sugerencia) : (string) $sugerencia,
            );
        }
    }

    // ========== Que los de verdad SÍ se vean ==========

    public function testARealEmployeeIsStillInTheList(): void
    {
        $ids = $this->idsDe($this->employee->get_all()->getResult());

        $this->assertContains(
            $this->normalPersonId,
            $ids,
            'Esconder de más oculta a un empleado real, y eso se descubre tarde y mal.',
        );
    }

    public function testARealEmployeeIsStillCounted(): void
    {
        $conteo = $this->employee->get_total_rows();

        $this->assertGreaterThan(0, $conteo);
        $this->assertSame(
            count($this->idsDe($this->employee->get_all(10000)->getResult())),
            $conteo,
            'El conteo de la grilla y la grilla tienen que contar lo mismo, o la paginación miente.',
        );
    }

    public function testARealEmployeeCanStillLogIn(): void
    {
        // LA PUERTA DIARIA DE CASALETTO. Nada de esta entrega puede tocarla.
        $this->assertTrue($this->employee->login($this->usuarioNormal, 'la-suya'));
    }

    // ========== Y que la fila de soporte no abra nada ==========

    public function testTheSupportEmployeeCannotLogInWithAnyPassword(): void
    {
        foreach ([Platform_support::UNUSABLE_PASSWORD, '', 'pointofsale', 'admin'] as $intento) {
            $this->assertFalse(
                $this->employee->login(Platform_support::USERNAME, $intento),
                'La fila de soporte no puede autenticarse por la puerta del cliente.',
            );
        }
    }

    public function testItsStoredPasswordIsNotAHashOfAnything(): void
    {
        $fila = db_connect()->table('employees')->where('person_id', $this->soportePersonId)->get()->getRow();

        $this->assertSame(Platform_support::UNUSABLE_PASSWORD, $fila->password);
        $this->assertFalse(password_verify(Platform_support::UNUSABLE_PASSWORD, $fila->password));
        $this->assertNull(password_get_info($fila->password)['algo'] ?? null);
    }

    /**
     * LA SEPARACIÓN QUE ARREGLA UN FALLO SILENCIOSO
     *
     * `Stock_location::_insert_new_permission()` reparte los permisos de una bodega nueva iterando
     * la lista de empleados. Si esa lista escondiera al de soporte, cada bodega que creara un
     * cliente lo dejaría sin esos permisos, sin avisar, y el síntoma llegaría meses después como
     * «soporte no ve una de mis bodegas».
     *
     * Por eso hay dos consultas: la que se pinta esconde, y la que reparte permisos no.
     */
    public function testThePermissionQueryDoesIncludeTheSupportEmployee(): void
    {
        $ids = $this->idsDe($this->employee->get_all_for_permissions()->getResult());

        $this->assertContains($this->soportePersonId, $ids, 'Sin esto, cada bodega nueva lo deja fuera.');
        $this->assertContains($this->normalPersonId, $ids);
    }

    public function testTheTwoQueriesDifferOnlyBySupport(): void
    {
        $mostrados = $this->idsDe($this->employee->get_all(10000)->getResult());
        $todos     = $this->idsDe($this->employee->get_all_for_permissions()->getResult());

        $this->assertSame(
            [$this->soportePersonId],
            array_values(array_diff($todos, $mostrados)),
            'La única diferencia entre las dos listas tiene que ser el empleado de soporte.',
        );
    }

    public function testItIsMarkedWithItsOwnColumnAndNotAsDeleted(): void
    {
        $fila = db_connect()->table('employees')->where('person_id', $this->soportePersonId)->get()->getRow();

        $this->assertSame(1, (int) $fila->is_platform_support);
        $this->assertSame(0, (int) $fila->deleted, 'No está borrado: marcarlo así funcionaría hoy y mentiría mañana.');
    }
}
