<?php

declare(strict_types=1);

namespace Tests\Libraries;

use App\Libraries\Platform_support;
use App\Libraries\TenantProvisioner;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\OSPOS;
use RuntimeException;

/**
 * Que el empleado de soporte EXISTA dentro de un negocio, y que exista de la forma correcta.
 *
 * LO QUE ESTE ARCHIVO PRUEBA DE VERDAD
 *
 * No que se escriban tres filas --eso lo hace cualquier INSERT--, sino las dos cosas que hacen que
 * esta fila no sea un agujero de seguridad ni una bomba de relojería:
 *
 *  1. Que su contraseña NO abra la puerta del cliente. Es una cuenta con todos los permisos en el
 *     negocio de otro; si alguna cadena la autenticara por el login de empleados, habríamos metido
 *     una llave maestra en la caja de cada cliente.
 *  2. Que correrlo dos veces no duplique nada. Esto va a correr contra la base de Casaletto, que
 *     está vendiendo, y va a correrse más de una vez -- después de cada despliegue, si hace falta.
 *
 * Y los dos rechazos, que son la otra mitad: un esquema sin migrar y un nombre de usuario ya
 * ocupado por una persona real. En los dos casos lo que se afirma es que NO se escribió nada.
 *
 * ESTE ARCHIVO NO CUBRE ESCONDERLO NI ENTRAR CON ÉL
 *
 * Eso es `tests/Models/EmployeeSupportHidingTest.php`. Aquí solo se crea.
 *
 * @internal
 */
final class TenantProvisionerSupportEmployeeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    /**
     * Prefijo de la tabla de mentira con la que se prueba el rechazo por columna ausente. Se crea
     * en el MISMO esquema de pruebas, con otro prefijo, en vez de tocar `ospos_employees`: quitarle
     * y devolverle una columna a la tabla de verdad dejaría la suite entera rota si esta prueba se
     * cayera a la mitad.
     */
    private const PREFIJO_SIN_COLUMNA = 'sinsoporte_';

    private TenantProvisioner $provisioner;

    /** El empleado de carne y hueso que ocupa el usuario, en la prueba del rechazo. */
    private ?int $personIdOcupante = null;

    protected function setUp(): void
    {
        parent::setUp();

        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();

        $this->provisioner = new TenantProvisioner();
    }

    /**
     * La suite comparte UNA base y no la refresca entre archivos: lo que se siembre aquí se lo
     * encuentra el siguiente. Ver §9.16 del documento técnico -- cuatro pruebas de otros archivos
     * se pusieron rojas por esto esta misma semana.
     */
    protected function tearDown(): void
    {
        $this->borrarTodoRastroDeSoporte();

        db_connect()->query('DROP TABLE IF EXISTS `' . self::PREFIJO_SIN_COLUMNA . 'employees`');
        db_connect()->resetDataCache();

        parent::tearDown();
    }

    // ==================== Cómo nace ====================

    public function testTheSupportEmployeeIsBornMarkedAndNotDeleted(): void
    {
        $resultado = $this->provisioner->seedPlatformSupportEmployee(db_connect());

        $this->assertTrue($resultado['created']);

        $empleado = $this->soporte();

        $this->assertNotNull($empleado, 'La fila tiene que estar: sin ella no hay a quién colgarle lo que hagamos dentro.');
        $this->assertSame(1, (int) $empleado->is_platform_support);
        $this->assertSame(0, (int) $empleado->deleted, 'Se esconde por columna propia, NUNCA marcándolo como borrado (§4.3).');
        $this->assertSame(Platform_support::USERNAME, $empleado->username);
        $this->assertSame($resultado['person_id'], (int) $empleado->person_id);
    }

    /**
     * La fila de `people` va con la de `employees`, y con el mismo `person_id`. Separadas es
     * exactamente como se llegó a que el administrador de todo negocio nuevo se llamara «John Doe».
     */
    public function testItIsARealPersonRowToo(): void
    {
        $resultado = $this->provisioner->seedPlatformSupportEmployee(db_connect());

        $persona = db_connect()->table('people')
            ->where('person_id', $resultado['person_id'])
            ->get()
            ->getRow();

        $this->assertNotNull($persona);
        $this->assertSame(Platform_support::FIRST_NAME, $persona->first_name);
        $this->assertSame(Platform_support::LAST_NAME, $persona->last_name);
    }

    // ==================== La contraseña que no abre nada ====================

    /**
     * LA PRUEBA CENTRAL DEL ARCHIVO.
     *
     * Esta cuenta tiene todos los permisos dentro del negocio de un cliente. Si alguna cadena la
     * autenticara por el login de empleados, sería una llave maestra en la caja de cada cliente.
     * Lo que se guarda no es un hash de nada: `password_verify()` sobre una cadena que no es un
     * hash válido devuelve false para TODA entrada, y no lanza.
     */
    public function testItsPasswordAuthenticatesNothing(): void
    {
        $this->provisioner->seedPlatformSupportEmployee(db_connect());

        $guardado = (string) $this->soporte()->password;

        $candidatos = [
            '',
            ' ',
            'pointofsale',
            'admin',
            Platform_support::USERNAME,
            Platform_support::UNUSABLE_PASSWORD,
            $guardado,
        ];

        foreach ($candidatos as $candidato) {
            $this->assertFalse(
                password_verify($candidato, $guardado),
                'Ninguna cadena puede autenticar al empleado de soporte, y «' . $candidato . '» lo hizo.',
            );
        }

        $this->assertSame(
            'unknown',
            password_get_info($guardado)['algoName'],
            'Lo guardado no es un hash de nada: si lo fuera, existiría una contraseña que abre.',
        );
    }

    /**
     * Y la otra rama del login, la vieja: con `hash_version = 1` OSPOS compara contra `md5()`, que
     * son 32 caracteres hexadecimales. Lo guardado no puede coincidir con eso ni por casualidad, y
     * además la fila nace en la versión 2, que es la que usa `password_verify()`.
     */
    public function testTheOldMd5BranchCannotMatchItEither(): void
    {
        $this->provisioner->seedPlatformSupportEmployee(db_connect());

        $empleado = $this->soporte();
        $guardado = (string) $empleado->password;

        $this->assertSame(2, (int) $empleado->hash_version);
        $this->assertNotSame(32, strlen($guardado), 'Un md5 tiene 32 caracteres; esto no puede parecerse a uno.');

        foreach (['', 'pointofsale', 'admin', Platform_support::UNUSABLE_PASSWORD] as $candidato) {
            $this->assertNotSame(md5($candidato), $guardado);
        }
    }

    // ==================== Los permisos ====================

    /**
     * Todos los de la tabla, no una lista escrita a mano: cada negocio tiene los suyos --las
     * migraciones añaden permisos con el tiempo y cada ubicación de existencias crea tres más-- y
     * una lista fija nacería incompleta en el primer negocio con dos bodegas.
     */
    public function testItHasEveryPermissionInTheTable(): void
    {
        $resultado = $this->provisioner->seedPlatformSupportEmployee(db_connect());

        $todos     = $this->todosLosPermisos();
        $otorgados = $this->permisosDe($resultado['person_id']);

        $this->assertNotSame([], $todos, 'Si la tabla estuviera vacía, esta prueba no probaría nada.');
        $this->assertSame($todos, $otorgados);
        $this->assertSame(count($todos), $resultado['grants_added']);
    }

    /**
     * `menu_group` es dónde sale el módulo en el menú, no si se puede entrar. Los que SON un módulo
     * van a «both» para que no se nos esconda ninguna pantalla; los subpermisos a «--», que es lo
     * que manda el propio formulario de empleados para ellos.
     */
    public function testTheModulesAreVisibleInBothMenusAndTheSubpermissionsAreNot(): void
    {
        $resultado = $this->provisioner->seedPlatformSupportEmployee(db_connect());

        $filas = db_connect()->table('grants')
            ->select('grants.permission_id, grants.menu_group, permissions.module_id')
            ->join('permissions', 'permissions.permission_id = grants.permission_id')
            ->where('grants.person_id', $resultado['person_id'])
            ->get()
            ->getResultArray();

        $this->assertNotSame([], $filas);

        $modulos = 0;

        foreach ($filas as $fila) {
            if ($fila['permission_id'] === $fila['module_id']) {
                $this->assertSame('both', $fila['menu_group'], $fila['permission_id'] . ' es un módulo y tiene que verse.');
                $modulos++;
            } else {
                $this->assertSame('--', $fila['menu_group'], $fila['permission_id'] . ' es un subpermiso.');
            }
        }

        $this->assertGreaterThan(0, $modulos, 'Sin módulos, la mitad de la afirmación no se ejercitó.');
    }

    // ==================== Correrlo dos veces ====================

    /**
     * La propiedad que hace que este código se pueda correr contra la base de Casaletto sin miedo.
     */
    public function testRunningItTwiceCreatesNothingNew(): void
    {
        $primero = $this->provisioner->seedPlatformSupportEmployee(db_connect());

        $personas  = $this->totalDe('people');
        $empleados = $this->totalDe('employees');
        $permisos  = count($this->permisosDe($primero['person_id']));

        $segundo = $this->provisioner->seedPlatformSupportEmployee(db_connect());

        $this->assertFalse($segundo['created'], 'La segunda corrida no crea: encuentra.');
        $this->assertSame($primero['person_id'], $segundo['person_id'], 'Y encuentra al MISMO, no a un segundo empleado de soporte.');
        $this->assertSame(0, $segundo['grants_added']);

        $this->assertSame($personas, $this->totalDe('people'));
        $this->assertSame($empleados, $this->totalDe('employees'));
        $this->assertSame($permisos, count($this->permisosDe($primero['person_id'])));

        $this->assertSame(
            1,
            db_connect()->table('employees')->where('is_platform_support', 1)->countAllResults(),
            'Un segundo empleado de soporte sería un segundo juego de llaves que nadie sabe que existe.',
        );
    }

    /**
     * Y la razón de que la segunda corrida sirva para algo: un permiso que aparece después --lo
     * añade una migración, o lo crea una ubicación de existencias nueva, que reparte los suyos
     * entre los empleados que el negocio lista y de los que este ya no es uno-- se completa.
     */
    public function testASecondRunFillsInAPermissionThatWentMissing(): void
    {
        $primero = $this->provisioner->seedPlatformSupportEmployee(db_connect());

        $huerfano = $this->todosLosPermisos()[0];

        db_connect()->table('grants')
            ->where('person_id', $primero['person_id'])
            ->where('permission_id', $huerfano)
            ->delete();

        $segundo = $this->provisioner->seedPlatformSupportEmployee(db_connect());

        $this->assertFalse($segundo['created'], 'No se rehace el empleado: se le completa lo que le falta.');
        $this->assertSame(1, $segundo['grants_added']);
        $this->assertSame($this->todosLosPermisos(), $this->permisosDe($primero['person_id']));
    }

    /**
     * Y no le toca los permisos a nadie más. Es la mitad silenciosa del trabajo: un `delete` mal
     * puesto sobre `grants` dejaría al negocio sin sus propios permisos y nadie lo vería hasta que
     * un empleado dejara de poder vender.
     */
    public function testNobodyElsesGrantsAreTouched(): void
    {
        $antes = $this->totalDe('grants');
        $mios  = db_connect()->table('grants')->where('person_id', 1)->countAllResults();

        $resultado = $this->provisioner->seedPlatformSupportEmployee(db_connect());

        $this->assertSame(
            $antes + count($this->permisosDe($resultado['person_id'])),
            $this->totalDe('grants'),
            'Se añaden los del soporte y ni uno menos de los que ya había.',
        );
        $this->assertSame($mios, db_connect()->table('grants')->where('person_id', 1)->countAllResults());
    }

    // ==================== Los dos rechazos ====================

    /**
     * Un esquema al que todavía no le corrieron las migraciones no tiene la columna, y escribir ahí
     * dejaría un empleado sin marcar: invisible para nosotros y visible para el cliente, que es lo
     * peor de los dos mundos. Se niega y dice qué hay que correr.
     */
    public function testASchemaWithoutTheColumnIsRefusedAndNothingIsWritten(): void
    {
        $sinColumna = $this->conexionSinLaColumna();

        try {
            $this->provisioner->seedPlatformSupportEmployee($sinColumna);
            $this->fail('Escribir en un esquema sin migrar tiene que negarse.');
        } catch (RuntimeException $e) {
            // Se compara contra lang() y no contra el texto: una prueba que afirma sobre la frase se
            // pone roja al traducirla sin que cambie nada de lo que comprueba (§9.17).
            $this->assertSame(
                lang('Platform.error_support_column_missing', [db_connect()->getDatabase()]),
                $e->getMessage(),
            );
        }

        $this->assertNull($this->soporte(), 'No se escribió en las tablas de verdad.');
        $this->assertSame(
            0,
            $sinColumna->table('employees')->countAllResults(),
            'Ni en la de mentira: se niega ANTES de tocar nada.',
        );
    }

    /**
     * EL RECHAZO QUE MÁS IMPORTA.
     *
     * El usuario está ocupado por una fila SIN la marca: hay un empleado de verdad llamándose así.
     * Marcarlo lo escondería de su propio negocio; sobreescribirlo le quitaría su contraseña. Las
     * dos cosas son daño real sobre una persona real, así que no se hace ninguna y se dice.
     */
    public function testAUsernameTakenByARealEmployeeIsRefusedAndThatEmployeeIsLeftIntact(): void
    {
        $suContrasena = password_hash('la-suya-de-verdad', PASSWORD_DEFAULT);
        $this->personIdOcupante = $this->sembrarOcupante($suContrasena);

        try {
            $this->provisioner->seedPlatformSupportEmployee(db_connect());
            $this->fail('Quitarle el nombre de usuario a un empleado real tiene que negarse.');
        } catch (RuntimeException $e) {
            $this->assertSame(
                lang('Platform.error_support_username_taken', [
                    db_connect()->getDatabase(),
                    Platform_support::USERNAME,
                ]),
                $e->getMessage(),
            );
        }

        $ocupante = db_connect()->table('employees')
            ->where('person_id', $this->personIdOcupante)
            ->get()
            ->getRow();

        $this->assertNotNull($ocupante, 'La fila del empleado real sigue ahí.');
        $this->assertSame($suContrasena, (string) $ocupante->password, 'Con SU contraseña, que sigue abriendo.');
        $this->assertTrue(password_verify('la-suya-de-verdad', (string) $ocupante->password));
        $this->assertSame(0, (int) $ocupante->is_platform_support, 'Y sin la marca: no lo hemos escondido de su negocio.');
        $this->assertSame(0, (int) $ocupante->deleted);

        $this->assertSame(
            0,
            db_connect()->table('employees')->where('is_platform_support', 1)->countAllResults(),
            'Y no se creó ningún empleado de soporte a medias.',
        );
        $this->assertSame(
            0,
            db_connect()->table('grants')->where('person_id', $this->personIdOcupante)->countAllResults(),
            'Ni se le regalaron permisos a la persona equivocada.',
        );
    }

    // ==================== Andamiaje ====================

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

    /** @return list<string> */
    private function todosLosPermisos(): array
    {
        $filas = db_connect()->table('permissions')
            ->select('permission_id')
            ->orderBy('permission_id', 'asc')
            ->get()
            ->getResultArray();

        return array_column($filas, 'permission_id');
    }

    /** @return list<string> */
    private function permisosDe(int $personId): array
    {
        $filas = db_connect()->table('grants')
            ->select('permission_id')
            ->where('person_id', $personId)
            ->orderBy('permission_id', 'asc')
            ->get()
            ->getResultArray();

        return array_column($filas, 'permission_id');
    }

    /**
     * Una `employees` sin la columna, en el mismo esquema y con otro prefijo. `CREATE TABLE ... LIKE`
     * y no un CREATE escrito a mano: así la tabla de mentira sigue pareciéndose a la de verdad el
     * día que a la de verdad le añadan algo.
     */
    private function conexionSinLaColumna(): BaseConnection
    {
        $prefijo = self::PREFIJO_SIN_COLUMNA;

        db_connect()->query("DROP TABLE IF EXISTS `{$prefijo}employees`");
        db_connect()->query("CREATE TABLE `{$prefijo}employees` LIKE `ospos_employees`");
        db_connect()->query("ALTER TABLE `{$prefijo}employees` DROP COLUMN `is_platform_support`");

        $config = config(Database::class);
        $grupo  = $config->{$config->defaultGroup};

        $grupo['DBPrefix'] = $prefijo;

        return Database::connect($grupo, false);
    }

    /** Un empleado de carne y hueso que ya se llama como el de soporte. */
    private function sembrarOcupante(string $hash): int
    {
        db_connect()->table('people')->insert([
            'first_name'   => 'Persona',
            'last_name'    => 'Real',
            'phone_number' => '',
            'email'        => '',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
        ]);

        $personId = (int) db_connect()->insertID();

        db_connect()->table('employees')->insert([
            'username'            => Platform_support::USERNAME,
            'password'            => $hash,
            'person_id'           => $personId,
            'deleted'             => 0,
            'hash_version'        => 2,
            'is_platform_support' => 0,
        ]);

        return $personId;
    }

    /**
     * Todo lo que este archivo pueda haber sembrado: el empleado de soporte de cualquiera de las
     * pruebas y el ocupante de la última. Se busca por las dos señas --la marca y el usuario--
     * porque las pruebas de los rechazos crean una fila que no lleva la marca.
     */
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

        $ids = array_map(static fn (array $fila): int => (int) $fila['person_id'], $filas);

        if ($this->personIdOcupante !== null) {
            $ids[] = $this->personIdOcupante;
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return;
        }

        db_connect()->table('grants')->whereIn('person_id', $ids)->delete();
        db_connect()->table('employees')->whereIn('person_id', $ids)->delete();
        db_connect()->table('people')->whereIn('person_id', $ids)->delete();

        $this->personIdOcupante = null;
    }
}
