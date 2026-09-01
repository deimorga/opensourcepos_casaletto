<?php

declare(strict_types=1);

namespace Tests\Libraries;

use App\Libraries\TenantConfigProfile;
use App\Libraries\TenantProvisioner;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\OSPOS;

/**
 * Lo que el alta deja escrito DENTRO del negocio: la credencial, el nombre de la persona y el perfil
 * de configuración.
 *
 * POR QUÉ ESTO NO PRUEBA create() ENTERO
 *
 * Porque no se puede, y decirlo es mejor que fingirlo: create() empieza por `CREATE DATABASE`, y el
 * usuario de la base de pruebas no tiene ese permiso -- a propósito, es la misma restricción que
 * impide que una prueba borre un esquema de verdad. Lo que sí se puede probar, y es donde estaban
 * los defectos, es el bloque que escribe en el esquema ya migrado. Por eso vive en su propio método
 * público y recibe la conexión: no es una comodidad de diseño, es la única forma de que este código
 * tenga cobertura.
 *
 * Lo que queda sin cubrir por prueba automática, y hay que mirar en la certificación en staging: que
 * create() de verdad llame a esto, que la fila de `platform_control.tenants` se escriba con el nombre
 * y la credencial, y que las migraciones del esquema nuevo pasen. Un negocio creado a mano en staging
 * lo enseña todo de una vez.
 *
 * @internal
 */
final class TenantProvisionerInitialAdminTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const COMPANY = 'Frutería La Cosecha';

    private TenantProvisioner $provisioner;
    private int $personId;
    private string $username;

    /** @var array<string, string|null> */
    private array $before = [];

    protected function setUp(): void
    {
        parent::setUp();

        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();

        $this->provisioner = new TenantProvisioner();

        $this->rememberSettings();
        $this->personId = $this->createSeededEmployee();
    }

    protected function tearDown(): void
    {
        $this->restoreSettings();

        db_connect()->table('employees')->where('person_id', $this->personId)->delete();
        db_connect()->table('people')->where('person_id', $this->personId)->delete();

        parent::tearDown();
    }

    /**
     * El esquema de pruebas es compartido y `$refresh` es false en toda la casa, así que lo que este
     * archivo escriba en `ospos_app_config` se lo encuentra el siguiente. Se fotografía y se repone.
     */
    private function rememberSettings(): void
    {
        foreach (array_keys(TenantConfigProfile::appConfig('')) as $key) {
            $row = db_connect()->table('app_config')->where('key', $key)->get()->getRow();

            $this->before[$key] = $row === null ? null : (string) $row->value;
        }
    }

    private function restoreSettings(): void
    {
        foreach ($this->before as $key => $value) {
            if ($value === null) {
                db_connect()->table('app_config')->where('key', $key)->delete();

                continue;
            }

            db_connect()->table('app_config')->where('key', $key)->update(['value' => $value]);
        }
    }

    /**
     * Un empleado exactamente como lo deja `initial_schema.sql`: la persona se llama «John Doe», el
     * usuario es el de Casaletto y el idioma está vacío. Es el estado del que hay que salir.
     */
    private function createSeededEmployee(): int
    {
        db_connect()->table('people')->insert([
            'first_name'   => 'John',
            'last_name'    => 'Doe',
            'phone_number' => '555-555-5555',
            'email'        => 'changeme@example.com',
            'address_1'    => 'Address 1',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
        ]);

        $personId = (int) db_connect()->insertID();

        // El nombre de usuario NO es `admin` aquí, a diferencia de producción, porque
        // `employees.username` es único y el esquema de pruebas lo comparten decenas de archivos:
        // reclamar `admin` haría que este archivo fallara o hiciera fallar a otro según el orden en
        // que corrieran. Que el de serie sea `admin` se afirma aparte, contra la constante.
        $this->username = 'alta_prueba_' . $personId;

        db_connect()->table('employees')->insert([
            'username'      => 'sembrado_' . $personId,
            'password'      => password_hash('la-de-la-semilla', PASSWORD_DEFAULT),
            'person_id'     => $personId,
            'deleted'       => 0,
            'hash_version'  => 2,
            'language'      => null,
            'language_code' => null,
        ]);

        return $personId;
    }

    /**
     * @return array{username: string, password: string, hash: string}
     */
    private function seed(): array
    {
        return $this->provisioner->seedInitialAdmin(
            db_connect(),
            self::COMPANY,
            $this->personId,
            $this->username,
        );
    }

    private function person(): object
    {
        return db_connect()->table('people')->where('person_id', $this->personId)->get()->getRow();
    }

    private function employee(): object
    {
        return db_connect()->table('employees')->where('person_id', $this->personId)->get()->getRow();
    }

    private function setting(string $key): ?string
    {
        $row = db_connect()->table('app_config')->where('key', $key)->get()->getRow();

        return $row === null ? null : (string) $row->value;
    }

    // ========== El nombre ==========

    /**
     * §4.6: el sistema cambiaba el usuario y la contraseña, pero nunca la fila de la persona. Todo
     * negocio nuevo nacía con un administrador llamado «John Doe».
     */
    public function testTheAdministratorIsNoLongerCalledJohnDoe(): void
    {
        $this->seed();

        $person = $this->person();

        $this->assertNotSame('John', $person->first_name);
        $this->assertNotSame('Doe', $person->last_name);
    }

    /**
     * Y se llama de algo que sirve: el apellido es el nombre del negocio, así que en una lista de
     * varios negocios no hay dos administradores con el mismo nombre.
     */
    public function testTheAdministratorIsNamedAfterTheBusiness(): void
    {
        $this->seed();

        $person = $this->person();

        $this->assertSame(TenantConfigProfile::ADMIN_FIRST_NAME, $person->first_name);
        $this->assertSame(self::COMPANY, $person->last_name);
    }

    // ========== La credencial ==========

    /**
     * Sigue siendo lo que era: el reemplazo del administrador sembrado NO se puede quitar. La
     * semilla trae el usuario y el hash bcrypt reales de Casaletto, así que sin este paso la
     * contraseña de administrador de todo negocio nuevo sería la de otro cliente.
     */
    public function testTheSeededCasalettoCredentialIsReplaced(): void
    {
        $admin = $this->seed();

        $employee = $this->employee();

        $this->assertSame($this->username, $employee->username);
        $this->assertTrue(password_verify($admin['password'], (string) $employee->password));
        $this->assertFalse(password_verify('la-de-la-semilla', (string) $employee->password));
    }

    /**
     * El hash devuelto es exactamente el que quedó escrito. Es lo que la consola guarda como testigo
     * para saber después si el cliente cambió la contraseña; si no fuera el mismo, la ficha diría
     * «la cambió» desde el primer día.
     */
    public function testTheReturnedHashIsTheOneThatWasWritten(): void
    {
        $admin = $this->seed();

        $this->assertSame($admin['hash'], (string) $this->employee()->password);
    }

    /**
     * En producción el usuario es `admin` (D9: uno solo, con todos los permisos). Aquí se afirma
     * contra la constante porque la prueba de arriba usa un nombre único por el índice único.
     */
    public function testTheDefaultUsernameIsStillAdmin(): void
    {
        $this->assertSame('admin', TenantProvisioner::DEFAULT_ADMIN_USERNAME);
    }

    // ========== El perfil, aplicado por el alta ==========

    /**
     * Las tres claves de cableado, escritas por el alta y no a mano después. Es la diferencia entre
     * «alguien recordó diez ajustes» y «el sistema los hizo».
     */
    public function testTheProfileIsAppliedByTheSignUpItself(): void
    {
        $this->seed();

        $this->assertSame('3', $this->setting('quantity_decimals'));
        $this->assertSame('item_number', $this->setting('barcode_content'));
        $this->assertSame('es-MX', $this->setting('language_code'));
        $this->assertSame('co', $this->setting('country_codes'));
    }

    public function testTheCompanyNameIsWrittenIntoTheBusinessConfiguration(): void
    {
        $this->seed();

        $this->assertSame(self::COMPANY, $this->setting('company'));
    }

    /**
     * El idioma del empleado gana sobre el del negocio, y el orden importa: el perfil se aplica
     * DESPUÉS del UPDATE que escribe usuario y contraseña. Al revés, ese UPDATE se llevaría por
     * delante el idioma recién puesto y el negocio nacería hablando la lengua de la semilla.
     */
    public function testTheInitialEmployeeKeepsTheProfileLanguageAfterTheCredentialIsWritten(): void
    {
        $this->seed();

        $this->assertSame('es-MX', $this->employee()->language_code);
        $this->assertSame('spanish', $this->employee()->language);
    }
}
