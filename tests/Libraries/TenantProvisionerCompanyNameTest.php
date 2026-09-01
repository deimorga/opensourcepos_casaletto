<?php

declare(strict_types=1);

namespace Tests\Libraries;

use App\Libraries\TenantProvisioner;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\OSPOS;

/**
 * Copiar al registro el nombre que el negocio ya tiene en su propia configuración.
 *
 * LO QUE HAY QUE DEMOSTRAR NO ES QUE COPIE, ES QUE NO PISE
 *
 * Un negocio que ya tenga nombre en el registro puede tenerlo porque una persona se lo puso a
 * propósito. Una copia automática que lo machacara borraría esa decisión sin avisar, y nadie se
 * enteraría hasta que alguien buscara un negocio por un nombre que ya no está.
 *
 * @internal
 */
final class TenantProvisionerCompanyNameTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const SLUG = 'prueba-nombre';

    private TenantProvisioner $provisioner;
    private ?string $companyOriginal = null;

    protected function setUp(): void
    {
        parent::setUp();

        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();

        $this->provisioner = new TenantProvisioner();

        // La suite comparte una sola base: se guarda el `company` que había para devolverlo después.
        $fila = db_connect()->table('app_config')->where('key', 'company')->get()->getRow();
        $this->companyOriginal = $fila === null ? null : (string) $fila->value;

        $this->crearRegistro();
    }

    protected function tearDown(): void
    {
        db_connect('platform')->query('DROP TABLE IF EXISTS `tenants`');
        db_connect('platform')->resetDataCache();

        if ($this->companyOriginal !== null) {
            db_connect()->table('app_config')->where('key', 'company')->update(['value' => $this->companyOriginal]);
        }

        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();

        parent::tearDown();
    }

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
    }

    private function sembrar(?string $nombreEnElRegistro): void
    {
        db_connect('platform')->table('tenants')->insert([
            'slug'         => self::SLUG,
            'company_name' => $nombreEnElRegistro,
            'db_name'      => db_connect()->getDatabase(),
            'db_user'      => '',
            'status'       => 'active',
        ]);
    }

    private function nombreDelNegocio(string $nombre): void
    {
        db_connect()->table('app_config')->where('key', 'company')->update(['value' => $nombre]);
        db_connect()->resetDataCache();
    }

    private function enElRegistro(): ?string
    {
        $fila = db_connect('platform')->table('tenants')->where('slug', self::SLUG)->get()->getRow();

        return $fila->company_name;
    }

    // ========== Rellenar el hueco ==========

    public function testItCopiesTheNameTheBusinessAlreadyKnows(): void
    {
        $this->sembrar(null);
        $this->nombreDelNegocio('Casaletto Anapoima');

        $resultado = $this->provisioner->fillCompanyNameFromBusiness(self::SLUG);

        $this->assertTrue($resultado['filled']);
        $this->assertSame('Casaletto Anapoima', $resultado['name']);
        $this->assertSame('Casaletto Anapoima', $this->enElRegistro());
    }

    public function testAnEmptyStringInTheRegistryCountsAsAHole(): void
    {
        $this->sembrar('   ');
        $this->nombreDelNegocio('Paraíso de la Canasta');

        $this->provisioner->fillCompanyNameFromBusiness(self::SLUG);

        $this->assertSame('Paraíso de la Canasta', $this->enElRegistro());
    }

    // ========== Y sobre todo, no pisar ==========

    /**
     * LA PRUEBA CENTRAL. Ese nombre puede haberlo puesto una persona para distinguir dos negocios
     * cuyo `company` es el mismo.
     */
    public function testItNeverOverwritesANameSomebodyAlreadyPut(): void
    {
        $this->sembrar('El que puso una persona');
        $this->nombreDelNegocio('Otro nombre distinto');

        $resultado = $this->provisioner->fillCompanyNameFromBusiness(self::SLUG);

        $this->assertFalse($resultado['filled']);
        $this->assertSame('El que puso una persona', $this->enElRegistro());
    }

    public function testRunningItTwiceChangesNothingTheSecondTime(): void
    {
        $this->sembrar(null);
        $this->nombreDelNegocio('Panadería La Espiga');

        $primera = $this->provisioner->fillCompanyNameFromBusiness(self::SLUG);
        $segunda = $this->provisioner->fillCompanyNameFromBusiness(self::SLUG);

        $this->assertTrue($primera['filled']);
        $this->assertFalse($segunda['filled'], 'La segunda corrida no vuelve a escribir.');
        $this->assertSame('Panadería La Espiga', $this->enElRegistro());
    }

    // ========== Cuando no hay nada que copiar ==========

    public function testABusinessWithNoNameOfItsOwnIsLeftAlone(): void
    {
        $this->sembrar(null);
        $this->nombreDelNegocio('');

        $resultado = $this->provisioner->fillCompanyNameFromBusiness(self::SLUG);

        $this->assertFalse($resultado['filled']);
        $this->assertNull($resultado['name'], 'No se inventa un nombre.');
        $this->assertNull($this->enElRegistro());
    }

    public function testAnUnknownBusinessIsAnError(): void
    {
        $this->expectExceptionMessage(lang('Platform.error_tenant_not_found', ['no-existe']));

        $this->provisioner->fillCompanyNameFromBusiness('no-existe');
    }

    /**
     * El nombre entra al registro por la misma puerta que en el alta, no por una de servicio que
     * acepte lo que la otra rechaza: las tildes se conservan tal cual.
     */
    public function testAccentsSurviveTheCopy(): void
    {
        $this->sembrar(null);
        $this->nombreDelNegocio('Panadería La Espiga · Anapoima');

        $this->provisioner->fillCompanyNameFromBusiness(self::SLUG);

        $this->assertSame('Panadería La Espiga · Anapoima', $this->enElRegistro());
    }
}
