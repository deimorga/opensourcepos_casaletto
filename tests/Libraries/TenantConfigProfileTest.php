<?php

declare(strict_types=1);

namespace Tests\Libraries;

use App\Libraries\TenantConfigProfile;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\OSPOS;

/**
 * El perfil «Colombia · comercio al detal» (D12), aplicado contra un esquema OSPOS de verdad.
 *
 * POR QUÉ CONTRA LA BASE Y NO CONTRA UN DOBLE
 *
 * Lo que este perfil tiene que demostrar no es que sus constantes valen lo que valen -- eso lo dice
 * el propio archivo -- sino que ESCRIBIR esas constantes en un OSPOS deja las claves donde el punto
 * de venta las va a buscar. Las dos veces que este proyecto perdió dinero por configuración fueron
 * porque un valor no llegó a la tabla: `quantity_decimals` en 0 perdiendo el peso, y
 * `barcode_content` en `id` vendiendo el producto equivocado. Un doble en memoria habría pasado las
 * dos veces.
 *
 * EL ESQUEMA DE PRUEBAS ES COMPARTIDO, ASÍ QUE ESTE ARCHIVO LO DEVUELVE COMO ESTABA
 *
 * `$refresh` es false en toda la casa (migrar OSPOS entero por prueba tardaría minutos), de modo que
 * lo que este archivo escriba en `ospos_app_config` se lo encuentra el siguiente. setUp() fotografía
 * las claves que va a tocar y tearDown() las repone una por una, distinguiendo «tenía otro valor» de
 * «no existía»: repostar con una cadena vacía una clave que no estaba dejaría el esquema peor que si
 * no se hubiera tocado.
 *
 * El empleado, en cambio, es nuevo y se borra: modificar el `person_id` 1 del esquema de pruebas
 * sería tocar la fila que otras pruebas dan por sentada.
 *
 * @internal
 */
final class TenantConfigProfileTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private TenantConfigProfile $profile;

    /** @var array<string, string|null> valor previo de cada clave, o null si no existía */
    private array $before = [];

    private int $personId;

    protected function setUp(): void
    {
        parent::setUp();

        // La conexión agrupada cachea la lista de tablas de antes de migrar, y eso deja a
        // Config\OSPOS con valores por defecto incompletos.
        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();

        $this->profile = new TenantConfigProfile();

        $this->rememberSettings();
        $this->personId = $this->createEmployee();
    }

    protected function tearDown(): void
    {
        $this->restoreSettings();
        $this->removeEmployee();

        parent::tearDown();
    }

    /**
     * Todas las claves que el perfil escribe, más `tax_included`, que es la que tiene que quedarse
     * exactamente donde estaba.
     *
     * @return list<string>
     */
    private function touchedKeys(): array
    {
        return array_merge(array_keys(TenantConfigProfile::appConfig('')), ['tax_included']);
    }

    private function rememberSettings(): void
    {
        foreach ($this->touchedKeys() as $key) {
            $row = db_connect()->table('app_config')->where('key', $key)->get()->getRow();

            $this->before[$key] = $row === null ? null : (string) $row->value;
        }
    }

    /**
     * Repone, y repone de verdad: una de las pruebas BORRA una clave para comprobar que el perfil la
     * inserta, así que aquí no basta con un UPDATE -- si la fila ya no está, no escribiría nada y el
     * esquema de pruebas se quedaría sin esa clave para todo lo que corra después.
     */
    private function restoreSettings(): void
    {
        foreach ($this->before as $key => $value) {
            if ($value === null) {
                db_connect()->table('app_config')->where('key', $key)->delete();

                continue;
            }

            $exists = db_connect()->table('app_config')->where('key', $key)->countAllResults() > 0;

            if ($exists) {
                db_connect()->table('app_config')->where('key', $key)->update(['value' => $value]);

                continue;
            }

            db_connect()->table('app_config')->insert(['key' => $key, 'value' => $value]);
        }
    }

    /**
     * Un empleado propio, con su fila de `people` porque `employees.person_id` tiene una clave
     * foránea contra ella. Nace con el idioma VACÍO, que es como nacen de verdad: es el estado desde
     * el que el perfil tiene que llevarlo a es-MX.
     */
    private function createEmployee(): int
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

        db_connect()->table('employees')->insert([
            'username'      => 'perfil_prueba_' . $personId,
            'password'      => password_hash('irrelevante', PASSWORD_DEFAULT),
            'person_id'     => $personId,
            'deleted'       => 0,
            'hash_version'  => 2,
            'language'      => null,
            'language_code' => null,
        ]);

        return $personId;
    }

    private function removeEmployee(): void
    {
        db_connect()->table('employees')->where('person_id', $this->personId)->delete();
        db_connect()->table('people')->where('person_id', $this->personId)->delete();
    }

    private function setting(string $key): ?string
    {
        $row = db_connect()->table('app_config')->where('key', $key)->get()->getRow();

        return $row === null ? null : (string) $row->value;
    }

    private function employee(): object
    {
        return db_connect()->table('employees')->where('person_id', $this->personId)->get()->getRow();
    }

    // ========== Las tres claves de cableado ==========

    /**
     * En 0 el peso se pierde en silencio: la venta cuadra en plata y el inventario queda mal.
     */
    public function testTheQuantityDecimalsAllowSellingByWeight(): void
    {
        $this->profile->applyTo(db_connect(), 'Negocio de prueba', $this->personId);

        $this->assertSame('3', $this->setting('quantity_decimals'));
    }

    /**
     * En `id`, teclear un código vende otro producto. Pasó en Paraíso: 212 de 1.184 referencias
     * colisionaban.
     */
    public function testTheBarcodeReadsTheItemNumberAndNotTheInternalId(): void
    {
        $this->profile->applyTo(db_connect(), 'Negocio de prueba', $this->personId);

        $this->assertSame('item_number', $this->setting('barcode_content'));
    }

    /**
     * es-MX y no es-ES. Una cadena escrita solo en es-ES es invisible: la pantalla sale en inglés y
     * no da ningún error.
     */
    public function testTheLanguageIsTheOneTheApplicationActuallyRunsIn(): void
    {
        $this->profile->applyTo(db_connect(), 'Negocio de prueba', $this->personId);

        $this->assertSame('es-MX', $this->setting('language_code'));
        $this->assertSame('spanish', $this->setting('language'));
    }

    // ========== El resto del perfil ==========

    public function testTheNumberAndCurrencyFormatsAreColombian(): void
    {
        $this->profile->applyTo(db_connect(), 'Negocio de prueba', $this->personId);

        $this->assertSame('es_CO', $this->setting('number_locale'));
        $this->assertSame('0', $this->setting('currency_decimals'), 'El peso colombiano no tiene centavos.');
        $this->assertSame('America/Bogota', $this->setting('timezone'));
    }

    /**
     * Decidido en esta entrega: hoy los dos negocios de producción están en `us`, que es
     * sencillamente incorrecto para Colombia.
     */
    public function testTheCountryIsColombiaAndNotTheSeedsUnitedStates(): void
    {
        $this->profile->applyTo(db_connect(), 'Negocio de prueba', $this->personId);

        $this->assertSame('co', $this->setting('country_codes'));
    }

    public function testTheCompanyNameIsTheOneGiven(): void
    {
        $this->profile->applyTo(db_connect(), 'Panadería El Trigal', $this->personId);

        $this->assertSame('Panadería El Trigal', $this->setting('company'));
    }

    /**
     * `tax_included` NO lo decide este perfil. Los dos negocios de producción corren con 0 y el
     * documento de venta por peso recomendaba 1: la contradicción la resuelve el dueño, y hasta
     * entonces el perfil tiene que dejar el valor exactamente donde lo encontró.
     */
    public function testTheProfileDoesNotDecideWhetherTaxIsIncludedInThePrice(): void
    {
        $antes = $this->setting('tax_included');

        $this->profile->applyTo(db_connect(), 'Negocio de prueba', $this->personId);

        $this->assertSame($antes, $this->setting('tax_included'));
    }

    // ========== El idioma vive en dos sitios ==========

    /**
     * LA PRUEBA QUE JUSTIFICA QUE EL PERFIL NO SEA UN SIMPLE UPDATE DE app_config.
     *
     * `ospos_employees` tiene sus propias columnas de idioma y GANAN sobre la configuración del
     * negocio (app/Helpers/locale_helper.php). Un perfil que escriba solo `app_config` deja al
     * empleado inicial con el idioma vacío hoy -- que funciona por casualidad, porque cae al global
     * -- y roto el día que alguien cambie el global.
     */
    public function testTheInitialEmployeeIsBornSpeakingTheProfilesLanguage(): void
    {
        $this->profile->applyTo(db_connect(), 'Negocio de prueba', $this->personId);

        $employee = $this->employee();

        $this->assertSame('es-MX', $employee->language_code, 'El idioma del empleado gana sobre el del negocio.');
        $this->assertSame('spanish', $employee->language);
    }

    /**
     * Las dos columnas juntas y nunca una sola: con `language_code` escrita y `language` vacía, la
     * aplicación hablaría un idioma y su pantalla de perfil diría otro.
     */
    public function testTheEmployeeLanguagePairIsWrittenWhole(): void
    {
        $this->assertSame(
            ['language' => 'spanish', 'language_code' => 'es-MX'],
            TenantConfigProfile::employeeLanguage(),
        );
    }

    // ========== La clave que no estaba ==========

    /**
     * `app_config` es una tabla clave/valor cuyo contenido depende de por qué migraciones haya
     * pasado cada esquema. Un UPDATE a secas sobre una clave ausente no escribe nada y no falla: el
     * negocio se queda con lo que el código asuma por defecto y nadie se entera.
     */
    public function testAKeyThatIsNotInTheTableYetGetsInserted(): void
    {
        db_connect()->table('app_config')->where('key', 'country_codes')->delete();

        $this->profile->applyTo(db_connect(), 'Negocio de prueba', $this->personId);

        $this->assertSame('co', $this->setting('country_codes'));
    }

    /**
     * Aplicarlo dos veces tiene que dar lo mismo que aplicarlo una. Es lo que permite volver a
     * pasarlo por un negocio existente sin tener que averiguar antes qué le falta.
     */
    public function testApplyingItTwiceChangesNothingTheSecondTime(): void
    {
        $this->profile->applyTo(db_connect(), 'Negocio de prueba', $this->personId);
        $primera = $this->setting('quantity_decimals');

        $this->profile->applyTo(db_connect(), 'Negocio de prueba', $this->personId);

        $this->assertSame($primera, $this->setting('quantity_decimals'));
        $this->assertSame('es-MX', $this->employee()->language_code);
    }
}
