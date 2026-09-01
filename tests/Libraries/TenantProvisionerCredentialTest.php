<?php

declare(strict_types=1);

namespace Tests\Libraries;

use App\Libraries\TenantProvisioner;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\OSPOS;
use RuntimeException;

/**
 * La contraseña consultable (D5): consultarla mientras siga siendo válida, y restablecerla cuando ya
 * no lo sea.
 *
 * LO QUE ESTE ARCHIVO PRUEBA DE VERDAD, Y NO ES LA CRIPTOGRAFÍA
 *
 * Que la consola sepa DEJAR DE MOSTRARLA. Guardar una contraseña cifrada es fácil; lo difícil, y lo
 * único que hace que esta función no sea peligrosa, es que la pantalla no siga enseñando una
 * contraseña que el cliente ya cambió y que por lo tanto no abre nada. Por eso las pruebas centrales
 * son las dos de `changed`: que el estado cambia, y que la copia se borra de verdad.
 *
 * EL NEGOCIO DE PRUEBA ES ADOPTADO, Y ESO ES DELIBERADO
 *
 * Su fila se siembra con `db_user` vacío, que es exactamente como está Casaletto en producción: sin
 * usuario de MySQL propio, cayendo a las credenciales compartidas. Es el camino que un código
 * escrito suponiendo usuario dedicado rompe -- y lo rompería justo en el negocio que está vendiendo.
 * Probar por el camino cómodo y desplegar por el otro es cómo se llega ahí.
 *
 * Y encaja con el entorno de pruebas sin forzar nada: el grupo `platform` apunta al mismo esquema
 * que el grupo `tests` (ver phpunit.xml.dist), así que el «negocio» al que se conecta es la propia
 * base de pruebas, con su prefijo `ospos_`.
 *
 * @internal
 */
final class TenantProvisionerCredentialTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const SLUG     = 'prueba-credencial';
    private const USERNAME = 'admin_prueba_credencial';

    private TenantProvisioner $provisioner;
    private int $personId;

    /** La contraseña con la que nace el empleado del negocio de prueba. */
    private string $password;

    protected function setUp(): void
    {
        parent::setUp();

        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();

        $this->provisioner = new TenantProvisioner();
        $this->password    = bin2hex(random_bytes(8));

        $this->createRegistry();
        $this->personId = $this->createEmployee($this->password);
        $this->seedTenant();
    }

    protected function tearDown(): void
    {
        db_connect('platform')->query('DROP TABLE IF EXISTS `tenants`');
        db_connect('platform')->resetDataCache();

        db_connect()->table('employees')->where('person_id', $this->personId)->delete();
        db_connect()->table('people')->where('person_id', $this->personId)->delete();

        parent::tearDown();
    }

    /**
     * El registro de negocios, con las columnas de la Entrega 3 ya puestas. Se construye a mano y no
     * con el runner de migraciones por la misma razón que en el resto de la casa: el grupo
     * `platform` comparte esquema con el de pruebas y correr el namespace entero chocaría con las
     * tablas que otros archivos levantan para sí.
     */
    private function createRegistry(): void
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
                updated_at DATETIME NULL,
                admin_username VARCHAR(255) NULL,
                admin_password_hash VARCHAR(255) NULL,
                admin_password_cipher TEXT NULL,
                admin_password_set_at DATETIME NULL
            )',
        );
        $platform->resetDataCache();
    }

    /**
     * `db_user` vacío: un negocio ADOPTADO, como Casaletto. Y `db_name` es la base de pruebas, que
     * es a la que se va a conectar con las credenciales compartidas.
     */
    private function seedTenant(): void
    {
        db_connect('platform')->table('tenants')->insert([
            'slug'                  => self::SLUG,
            'company_name'          => 'Negocio de prueba',
            'db_name'               => db_connect()->getDatabase(),
            'db_user'               => '',
            'status'                => 'active',
            'admin_username'        => self::USERNAME,
            'admin_password_hash'   => password_hash($this->password, PASSWORD_DEFAULT),
            'admin_password_cipher' => service('encrypter')->encrypt($this->password),
            'admin_password_set_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function createEmployee(string $password): int
    {
        db_connect()->table('people')->insert([
            'first_name'   => 'Administrador',
            'last_name'    => 'Negocio de prueba',
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
            'username'     => self::USERNAME,
            'password'     => password_hash($password, PASSWORD_DEFAULT),
            'person_id'    => $personId,
            'deleted'      => 0,
            'hash_version' => 2,
        ]);

        return $personId;
    }

    private function tenantRow(): ?object
    {
        return db_connect('platform')->table('tenants')->where('slug', self::SLUG)->get()->getRow();
    }

    private function employeeHash(): string
    {
        $row = db_connect()->table('employees')->where('person_id', $this->personId)->get()->getRow();

        return (string) $row->password;
    }

    /**
     * Simula lo único que esta consola no puede ver ocurrir: que el cliente cambie su contraseña
     * desde su propio punto de venta, sin avisar a nadie.
     */
    private function clientChangesTheirPassword(): void
    {
        db_connect()->table('employees')->where('person_id', $this->personId)->update([
            'password' => password_hash('la-que-eligio-el-cliente', PASSWORD_DEFAULT),
        ]);
    }

    // ========== Consultarla ==========

    public function testThePasswordIsShownWhileTheBusinessStillHasOurHash(): void
    {
        $credential = $this->provisioner->adminCredential(self::SLUG);

        $this->assertSame(TenantProvisioner::CREDENTIAL_AVAILABLE, $credential['state']);
        $this->assertSame($this->password, $credential['password']);
        $this->assertSame(self::USERNAME, $credential['username']);
    }

    /**
     * LA PRUEBA CENTRAL. Una consola que siga enseñando la contraseña vieja manda a alguien a
     * intentar entrar con una llave que ya no abre, y a buscar el problema donde no está.
     */
    public function testItStopsBeingShownTheMomentTheClientChangesIt(): void
    {
        $this->clientChangesTheirPassword();

        $credential = $this->provisioner->adminCredential(self::SLUG);

        $this->assertSame(TenantProvisioner::CREDENTIAL_CHANGED, $credential['state']);
        $this->assertNull($credential['password'], 'No puede devolver ninguna contraseña.');
    }

    /**
     * Y no solo deja de mostrarla: la borra. Si solo dejara de enseñarla, la contraseña antigua de
     * un cliente se quedaría cifrada en nuestra base para siempre, y bastaría con revertir una línea
     * de la vista para volver a exponerla.
     */
    public function testTheStoredCopyIsErasedAndNotJustHidden(): void
    {
        $this->clientChangesTheirPassword();
        $this->provisioner->adminCredential(self::SLUG);

        $row = $this->tenantRow();

        $this->assertNull($row->admin_password_cipher, 'La copia cifrada tiene que desaparecer.');
        $this->assertNull($row->admin_password_hash);
        $this->assertSame(
            self::USERNAME,
            $row->admin_username,
            'El usuario se conserva: la ficha sigue teniendo que decir de quién era.',
        );
    }

    /**
     * Y una vez borrada, la siguiente consulta ya no dice «la cambió» sino «no hay copia»: el estado
     * `changed` se ve una sola vez, que es cuando se descubre.
     */
    public function testAfterTheCopyIsErasedTheStateIsSimplyThatThereIsNone(): void
    {
        $this->clientChangesTheirPassword();
        $this->provisioner->adminCredential(self::SLUG);

        $this->assertSame(
            TenantProvisioner::CREDENTIAL_NONE,
            $this->provisioner->adminCredential(self::SLUG)['state'],
        );
    }

    /**
     * Casaletto y Paraíso: dados de alta antes de que la consola guardara nada. No es un error, es
     * un estado normal, y no hace falta abrir ninguna conexión al negocio para saberlo.
     */
    public function testABusinessRegisteredBeforeAnyCopyExistedReportsThatThereIsNone(): void
    {
        db_connect('platform')->table('tenants')->where('slug', self::SLUG)->update([
            'admin_password_hash'   => null,
            'admin_password_cipher' => null,
        ]);

        $credential = $this->provisioner->adminCredential(self::SLUG);

        $this->assertSame(TenantProvisioner::CREDENTIAL_NONE, $credential['state']);
        $this->assertNull($credential['password']);
    }

    /**
     * El usuario guardado ya no existe en el negocio -- lo renombraron o lo dieron de baja. La
     * pareja usuario/contraseña que la ficha promete ya no abre nada, así que vale lo mismo que si
     * la hubieran cambiado.
     */
    public function testAUserThatNoLongerExistsCountsAsChanged(): void
    {
        db_connect()->table('employees')->where('person_id', $this->personId)->update([
            'username' => 'otro_nombre_' . $this->personId,
        ]);

        $this->assertSame(
            TenantProvisioner::CREDENTIAL_CHANGED,
            $this->provisioner->adminCredential(self::SLUG)['state'],
        );
    }

    public function testAnUnknownBusinessIsAnError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/i');

        $this->provisioner->adminCredential('no-existe');
    }

    // ========== Restablecerla ==========

    /**
     * La otra mitad de D5, y la que hasta ahora obligaba a recrear el negocio entero: la lógica
     * estaba dentro de create() y no había forma de invocarla sola.
     */
    public function testResettingWritesAPasswordThatActuallyOpensTheBusiness(): void
    {
        $result = $this->provisioner->resetAdminPassword(self::SLUG);

        $this->assertTrue(
            password_verify($result['password'], $this->employeeHash()),
            'La contraseña devuelta tiene que ser la que abre el negocio.',
        );
        $this->assertNotSame($this->password, $result['password'], 'Tiene que ser una nueva.');
    }

    /**
     * Y queda consultable otra vez, que es lo que convierte el restablecimiento en algo que se
     * puede hacer al teléfono: se restablece, se lee, se dicta.
     */
    public function testAfterResettingItCanBeLookedUpAgain(): void
    {
        $nueva = $this->provisioner->resetAdminPassword(self::SLUG)['password'];

        $credential = $this->provisioner->adminCredential(self::SLUG);

        $this->assertSame(TenantProvisioner::CREDENTIAL_AVAILABLE, $credential['state']);
        $this->assertSame($nueva, $credential['password']);
    }

    /**
     * Incluso partiendo de un negocio cuya copia ya se había perdido, que es el caso real por el que
     * esto existe: el cliente cambió la contraseña, la olvidó, y no hay nada que consultar.
     */
    public function testItRecoversABusinessWhoseCopyWasAlreadyLost(): void
    {
        $this->clientChangesTheirPassword();
        $this->provisioner->adminCredential(self::SLUG);

        $nueva = $this->provisioner->resetAdminPassword(self::SLUG)['password'];

        $this->assertTrue(password_verify($nueva, $this->employeeHash()));
        $this->assertSame(
            TenantProvisioner::CREDENTIAL_AVAILABLE,
            $this->provisioner->adminCredential(self::SLUG)['state'],
        );
    }

    /**
     * El usuario NO se inventa. En Casaletto el administrador no se llama `admin` y sus empleados
     * son personas reales: adivinar aquí significaría, en el mejor caso, no encontrar a nadie, y en
     * el peor cambiarle la contraseña a quien no era.
     */
    public function testResettingAUserThatDoesNotExistIsRefusedAndChangesNothing(): void
    {
        $antes = $this->employeeHash();

        try {
            $this->provisioner->resetAdminPassword(self::SLUG, 'nadie_se_llama_asi');
            $this->fail('Restablecer a un usuario inexistente tiene que negarse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('nadie_se_llama_asi', $e->getMessage());
        }

        $this->assertSame($antes, $this->employeeHash(), 'No se puede haber tocado ninguna contraseña.');
    }

    /**
     * Este negocio de prueba es adoptado -- `db_user` vacío -- y todo lo de arriba funcionó, lo que
     * significa que la conexión cayó a las credenciales compartidas en vez de buscar un usuario
     * dedicado que no existe. Se afirma explícitamente para que quien rompa esa bifurcación vea por
     * qué falla, en vez de encontrarse doce pruebas rojas sin relación aparente.
     */
    public function testTheWholeFileRunsAgainstAnAdoptedBusiness(): void
    {
        $this->assertTrue(
            $this->provisioner->isAdopted($this->tenantRow()),
            'Si esta fila dejara de ser adoptada, este archivo dejaría de cubrir el caso de Casaletto.',
        );
    }
}
