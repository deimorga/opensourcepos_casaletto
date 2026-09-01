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

            // EL HASH DEL EMPLEADO, NO OTRO HASH DE LA MISMA CONTRASEÑA
            //
            // `adminCredential()` compara las dos cadenas con `hash_equals()`, que es lo correcto:
            // lo que dice si el cliente cambió su contraseña no es que siga siendo la misma palabra,
            // sino que la fila del negocio siga teniendo EL MISMO hash que guardamos. bcrypt sala
            // cada llamada, así que dos `password_hash()` de la misma contraseña dan cadenas
            // distintas -- sembrar una segunda hacía que el estado fuera `changed` desde el primer
            // instante, y que las pruebas de «deja de mostrarse» pasaran sin comprobar nada.
            'admin_password_hash'   => $this->employeeHash(),
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
        $this->expectExceptionMessage(lang('Platform.error_tenant_not_found', ['no-existe']));

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
    /**
     * EL ORDEN DE LAS COMPROBACIONES, QUE ES LO QUE SE PRUEBA ACÁ
     *
     * `tenants.db_password` está cifrada con la MISMA clave que esta copia. Si la clave se pierde o
     * se regenera, las dos cosas fallan a la vez: no se puede descifrar la copia Y no se puede
     * abrir la conexión al negocio. Cuál de los dos fallos se encuentra primero decide qué mensaje
     * ve la persona -- y son mensajes con acciones opuestas.
     *
     * Comprobando antes la conexión, una clave rota se anunciaba como «negocio inalcanzable»: un
     * aviso amarillo que invita a esperar y volver más tarde. Nadie iría a restaurar la clave.
     *
     * Se reproducen los dos fallos a la vez: copia ilegible y negocio inalcanzable.
     */
    public function testABrokenKeyIsReportedAsUnreadableAndNotAsAnUnreachableBusiness(): void
    {
        db_connect('platform')->table('tenants')->where('slug', self::SLUG)->update([
            'admin_password_cipher' => 'esto-no-lo-descifra-ninguna-clave',
            'db_name'               => 'esquema_que_no_existe_' . bin2hex(random_bytes(4)),
        ]);

        $credential = $this->provisioner->adminCredential(self::SLUG);

        $this->assertSame(
            TenantProvisioner::CREDENTIAL_UNREADABLE,
            $credential['state'],
            'Una clave rota tiene que mandar a restaurar la clave, no a esperar a que el negocio vuelva.',
        );
        $this->assertNull($credential['password']);
    }

    /**
     * Y la copia SIGUE AHÍ. Borrarla ante un fallo de descifrado convertiría un problema reparable
     * -- restaurar la clave -- en una pérdida definitiva.
     */
    public function testAnUnreadableCopyIsNotErased(): void
    {
        db_connect('platform')->table('tenants')->where('slug', self::SLUG)->update([
            'admin_password_cipher' => 'esto-no-lo-descifra-ninguna-clave',
        ]);

        $this->provisioner->adminCredential(self::SLUG);

        $this->assertSame('esto-no-lo-descifra-ninguna-clave', $this->tenantRow()->admin_password_cipher);
    }

    /**
     * Un negocio de verdad inalcanzable -- suspendido, base caída -- con la copia perfectamente
     * legible sigue diciendo `unreachable`. El arreglo de arriba no puede haber convertido todo en
     * «ilegible».
     */
    public function testAnUnreachableBusinessWithAReadableCopyStillSaysUnreachable(): void
    {
        db_connect('platform')->table('tenants')->where('slug', self::SLUG)->update([
            'db_name' => 'esquema_que_no_existe_' . bin2hex(random_bytes(4)),
        ]);

        $credential = $this->provisioner->adminCredential(self::SLUG);

        $this->assertSame(TenantProvisioner::CREDENTIAL_UNREACHABLE, $credential['state']);
        $this->assertNull($credential['password'], 'Tampoco acá se enseña: no se comprobó que siga valiendo.');
    }

    /**
     * LO QUE PASA CUANDO LA COPIA NO SE PUEDE GUARDAR
     *
     * La contraseña del negocio YA cambió: el cliente está fuera desde ese instante. Si esto lanzara
     * una excepción, se tiraría al suelo lo único que lo salva, que es enseñar la contraseña -- y en
     * producción el operador vería la pantalla de error genérica, sin ella, mientras el texto de la
     * excepción quedaría escrito en claro en `writable/logs/` para siempre.
     *
     * Así que devuelve normalmente, con `copy_saved` en falso, y es la consola la que la enseña una
     * vez. Se simula tirando la tabla del registro entre medias.
     */
    public function testWhenTheCopyCannotBeSavedThePasswordStillComesBack(): void
    {
        db_connect('platform')->query('DROP TABLE IF EXISTS `tenants_respaldo`');
        db_connect('platform')->query('CREATE TABLE `tenants_respaldo` AS SELECT * FROM `tenants`');

        $tenant = $this->tenantRow();

        // El registro desaparece DESPUÉS de que resetAdminPassword() lo haya leído, así que se
        // simula al revés: se le quita la columna donde tiene que escribir la copia.
        db_connect('platform')->query('ALTER TABLE `tenants` DROP COLUMN `admin_password_cipher`');
        db_connect('platform')->resetDataCache();

        $result = $this->provisioner->resetAdminPassword(self::SLUG);

        $this->assertFalse($result['copy_saved'], 'La copia no se pudo guardar y hay que decirlo.');
        $this->assertNotSame('', $result['password'], 'La contraseña tiene que volver: es la única vez que se ve.');
        $this->assertTrue(
            password_verify($result['password'], $this->employeeHash()),
            'Y tiene que ser la que de verdad quedó escrita en el negocio.',
        );

        $this->createRegistry();
        db_connect('platform')->table('tenants')->insert((array) $tenant);
    }

    public function testWhenTheCopyIsSavedItSaysSo(): void
    {
        $result = $this->provisioner->resetAdminPassword(self::SLUG);

        $this->assertTrue($result['copy_saved']);
    }

    public function testTheWholeFileRunsAgainstAnAdoptedBusiness(): void
    {
        $this->assertTrue(
            $this->provisioner->isAdopted($this->tenantRow()),
            'Si esta fila dejara de ser adoptada, este archivo dejaría de cubrir el caso de Casaletto.',
        );
    }
}
