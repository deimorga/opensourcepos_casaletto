<?php

declare(strict_types=1);

namespace Tests\Database;

use CodeIgniter\Test\CIUnitTestCase;
use Platform\Database\Migrations\AddAdminCredentialToTenants;
use Platform\Database\Migrations\AddCompanyNameToTenants;
use Platform\Database\Migrations\AddDbPasswordToTenants;
use Platform\Database\Migrations\CreatePlatformTenants;

/**
 * Las dos migraciones aditivas de la Entrega 3 sobre `platform_control.tenants`: el nombre real del
 * negocio (§4.5) y la contraseña consultable (D5).
 *
 * Son dos y no una a propósito: cada `down()` deshace un solo asunto, y el nombre del negocio puede
 * desplegarse aunque la contraseña consultable se retrase. Correrlas aquí en orden es además la
 * única forma de descubrir que la segunda da por hecho algo que añade la primera.
 *
 * LA ASERCIÓN QUE JUSTIFICA EL ARCHIVO ENTERO: EL CIFRADO CABE.
 *
 * Y no se comprueba mirando la definición de la columna, sino metiendo un texto cifrado de verdad y
 * volviéndolo a leer. Es la única forma de probarlo, porque el defecto que esto previene no da
 * ningún error: MySQL fuera de modo estricto corta el valor, el INSERT dice que fue bien, y el
 * descifrado falla semanas después con «authentication failed» sin que nada apunte al truncamiento.
 * Ya pasó en este proyecto con `tenants.db_password` en VARCHAR(255).
 *
 * @internal
 */
final class TenantRegistryColumnsMigrationTest extends CIUnitTestCase
{
    private const ADDED = [
        'company_name',
        'admin_username',
        'admin_password_hash',
        'admin_password_cipher',
        'admin_password_set_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Los archivos de migración llevan su versión delante, así que no cumplen PSR-4 y ningún
        // autoloader los encuentra: el runner los incluye por ruta y aquí se hace igual. El runner
        // no se usa porque aplicaría además el resto del namespace Platform sobre el esquema de
        // pruebas, que es compartido (ver phpunit.xml.dist).
        require_once APPPATH . 'Platform/Database/Migrations/20260730000000_CreatePlatformTenants.php';
        require_once APPPATH . 'Platform/Database/Migrations/20260731000000_AddDbPasswordToTenants.php';
        require_once APPPATH . 'Platform/Database/Migrations/20260903000000_AddCompanyNameToTenants.php';
        require_once APPPATH . 'Platform/Database/Migrations/20260903000001_AddAdminCredentialToTenants.php';

        $this->dropTable();

        (new CreatePlatformTenants())->up();
        (new AddDbPasswordToTenants())->up();
        (new AddCompanyNameToTenants())->up();
        (new AddAdminCredentialToTenants())->up();

        db_connect('platform')->resetDataCache();
    }

    protected function tearDown(): void
    {
        $this->dropTable();

        parent::tearDown();
    }

    private function dropTable(): void
    {
        db_connect('platform')->query('DROP TABLE IF EXISTS `tenants`');
        db_connect('platform')->resetDataCache();
    }

    /**
     * @return array<string, object>
     */
    private function fields(): array
    {
        $fields = [];

        foreach (db_connect('platform')->getFieldData('tenants') as $field) {
            $fields[$field->name] = $field;
        }

        return $fields;
    }

    public function testEveryNewColumnExists(): void
    {
        $names = array_keys($this->fields());

        foreach (self::ADDED as $column) {
            $this->assertContains($column, $names, "La migración no añadió `{$column}`.");
        }
    }

    /**
     * Casaletto y Paraíso se dieron de alta antes de que la columna existiera y se quedan en NULL.
     * Una columna obligatoria habría exigido inventarles un nombre desde una migración, es decir,
     * escribir en producción un dato que nadie confirmó.
     */
    public function testTheCompanyNameIsNullableBecauseTheTwoExistingBusinessesHaveNone(): void
    {
        $this->assertTrue($this->fields()['company_name']->nullable);
        $this->assertSame(255, (int) $this->fields()['company_name']->max_length);
    }

    /**
     * Las cuatro de la credencial también: un negocio del que la plataforma no guarda contraseña es
     * un estado normal (D5), no una fila a medio escribir.
     */
    public function testTheWholeCredentialIsNullableBecauseNotHavingOneIsANormalState(): void
    {
        foreach (['admin_username', 'admin_password_hash', 'admin_password_cipher', 'admin_password_set_at'] as $column) {
            $this->assertTrue($this->fields()[$column]->nullable, "`{$column}` tiene que admitir NULL.");
        }
    }

    /**
     * TEXT y no VARCHAR(255). La columna hermana `db_password` se dimensionó así y se desbordó.
     */
    public function testTheCipherColumnIsNotAVarcharThatCouldOverflow(): void
    {
        $this->assertSame('text', $this->fields()['admin_password_cipher']->type);
    }

    /**
     * LA ASERCIÓN DEL ARCHIVO. Un texto cifrado de verdad, escrito y releído por la base.
     *
     * Se cifra una contraseña generada igual que las de create() -- `bin2hex(random_bytes(8))` --
     * porque el largo del texto cifrado depende del largo del original, y probarlo con otra cosa no
     * probaría nada sobre lo que la aplicación guarda de verdad.
     */
    public function testARealCiphertextSurvivesTheRoundTripThroughTheColumn(): void
    {
        $password = bin2hex(random_bytes(8));
        $cipher   = service('encrypter')->encrypt($password);

        db_connect('platform')->table('tenants')->insert([
            'slug'                  => 'prueba-cifrado',
            'db_name'               => 'tenant_prueba_cifrado',
            'db_user'               => 'tenant_prueba_cifrado',
            'admin_username'        => 'admin',
            'admin_password_hash'   => password_hash($password, PASSWORD_DEFAULT),
            'admin_password_cipher' => $cipher,
        ]);

        $stored = db_connect('platform')->table('tenants')
            ->where('slug', 'prueba-cifrado')
            ->get()
            ->getRow();

        $this->assertSame(
            $cipher,
            $stored->admin_password_cipher,
            'La base devolvió algo distinto de lo que se guardó: la columna lo cortó en silencio.',
        );

        // Y lo que importa de verdad: que después de ese viaje siga descifrándose. Un truncamiento
        // de un solo carácter pasaría la comparación de longitudes de otra prueba y rompería aquí.
        $this->assertSame($password, service('encrypter')->decrypt($stored->admin_password_cipher));
    }

    /**
     * El hash sí es VARCHAR(255), y eso es correcto: `password_hash()` con el algoritmo por defecto
     * produce 60 caracteres, y es el mismo ancho que la columna `password` de `ospos_employees` con
     * la que se compara. Lo que nunca puede ser VARCHAR(255) es el cifrado, que es otra cosa.
     */
    public function testTheHashColumnMatchesTheWidthOfTheColumnItIsComparedAgainst(): void
    {
        $hash = $this->fields()['admin_password_hash'];

        $this->assertSame('varchar', $hash->type);
        $this->assertGreaterThanOrEqual(
            strlen(password_hash('cualquier-cosa', PASSWORD_DEFAULT)),
            (int) $hash->max_length,
        );
    }

    /**
     * Las dos migraciones se pueden deshacer. Una migración aditiva sin `down()` reversible
     * convierte cualquier vuelta atrás del despliegue en una operación manual.
     */
    public function testBothMigrationsAreReversible(): void
    {
        (new AddAdminCredentialToTenants())->down();
        (new AddCompanyNameToTenants())->down();
        db_connect('platform')->resetDataCache();

        $names = array_keys($this->fields());

        foreach (self::ADDED as $column) {
            $this->assertNotContains($column, $names, "`{$column}` sigue ahí después de down().");
        }

        // Y lo que estaba antes sigue estando: un down() que se lleve por delante `slug` o
        // `db_password` deja el registro de negocios inservible.
        $this->assertContains('slug', $names);
        $this->assertContains('db_password', $names);
    }
}
