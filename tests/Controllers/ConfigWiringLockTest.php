<?php

declare(strict_types=1);

namespace Tests\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\OSPOS;

/**
 * The two endpoints a business could use to unwire itself.
 *
 * Marking the fields disabled in the markup is not the lock -- anyone who sends the POST by hand
 * walks straight past it. This is the lock, and it is the reason the whole thing is not decorative.
 *
 * Every one of the three keys tested here has already cost a real incident: the weight lost in
 * silence, the typed code that sold cherry jelly instead of avocado, the warning that came out in
 * English because it was written in the other Spanish. See D12 in
 * docs/Funcional/gestion-de-plataforma-y-negocios.md.
 *
 * WRITTEN, NOT RUN: this needs the database up, and certification happens against staging and is
 * not signed by whoever wrote the code.
 *
 * @internal
 */
final class ConfigWiringLockTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    /** Toda la tabla `app_config` tal y como estaba antes de esta prueba. */
    private array $configuracionOriginal = [];

    /**
     * ESTE ARCHIVO ESCRIBE EN UNA BASE QUE COMPARTE CON TODA LA SUITE
     *
     * `$refresh` está en falso -- como en el resto de la casa -- así que nada devuelve la base a su
     * sitio entre pruebas, y aquí se hacen POST de verdad contra `/config/saveLocale`, que escribe
     * `app_config`. Sin esto, el idioma, los decimales y el país que deja una prueba se los
     * encuentran puestos las que corren después, EN OTROS ARCHIVOS.
     *
     * Pasó: la Entrega 3 dejó rojas `CustomersCsvImportTest` y `ExpensesCashSourceTest`, que no
     * tienen nada que ver con esto, sin que nadie tocara su código. Un fallo así se busca durante
     * horas en el sitio equivocado, porque el archivo culpable pasa.
     *
     * Se guarda la tabla entera y se devuelve entera. Enumerar las claves que cada prueba toca sería
     * más barato y volvería a fallar en cuanto alguien añadiera una.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->configuracionOriginal = [];

        foreach ($this->db->table('app_config')->get()->getResultArray() as $fila) {
            $this->configuracionOriginal[$fila['key']] = $fila['value'];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->configuracionOriginal as $clave => $valor) {
            $this->db->table('app_config')->where('key', $clave)->update(['value' => $valor]);
        }

        // Y las que no existían antes: una prueba puede crear una clave nueva, y dejarla ahí cambia
        // lo que lee `config(OSPOS::class)` en todo lo que venga detrás.
        $sobrantes = array_diff(
            array_column($this->db->table('app_config')->get()->getResultArray(), 'key'),
            array_keys($this->configuracionOriginal),
        );

        if ($sobrantes !== []) {
            $this->db->table('app_config')->whereIn('key', $sobrantes)->delete();
        }

        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();

        parent::tearDown();
    }

    protected function resetSession(): void
    {
        $session = Services::session();
        $session->destroy();
        $session->set('person_id', 1);
        $session->set('menu_group', 'office');

        // FeatureTestTrait::call() overwrites $_SESSION with its own property before dispatching,
        // so the session above is not enough on its own. Without this every request runs anonymous
        // and Secure_Controller calls a real exit() that kills PHPUnit with no output at all.
        $this->withSession(['person_id' => 1, 'menu_group' => 'office']);
    }

    /**
     * Puts the tenant on a known configuration. The suite does not refresh the database between
     * tests, so every test states the state it starts from rather than inheriting one.
     *
     * @param array<string, string> $values
     */
    private function given(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->db->table('app_config')->where('key', $key)->update(['value' => $value]);
        }

        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();
    }

    /**
     * The locale screen as the browser sends it. The two wired fields are rendered disabled, and a
     * disabled field is not submitted, so by default they are absent here too -- exactly like an
     * honest save. Tests that try to change one add it back.
     *
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function localePost(array $overrides = []): array
    {
        return array_merge([
            'number_locale'         => 'es_CO',
            'save_number_locale'    => 'es_CO',
            'currency_symbol'       => '$',
            'currency_code'         => 'COP',
            'currency_decimals'     => '0',
            'tax_decimals'          => '2',
            'cash_decimals'         => '0',
            'cash_rounding_code'    => '0',
            'payment_options_order' => 'cashdebitcredit',
            'country_codes'         => 'co',
            'timezone'              => 'America/Bogota',
            'dateformat'            => 'd/m/Y',
            'timeformat'            => 'H:i:s',
            'financial_year'        => '1',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function barcodePost(array $overrides = []): array
    {
        return array_merge([
            'barcode_type'             => 'Code39',
            'barcode_width'            => '250',
            'barcode_height'           => '50',
            'barcode_font'             => 'Arial',
            'barcode_font_size'        => '10',
            'barcode_first_row'        => 'category',
            'barcode_second_row'       => 'name',
            'barcode_third_row'        => 'unit_price',
            'barcode_num_in_row'       => '2',
            'barcode_page_width'       => '100',
            'barcode_page_cellspacing' => '0',
            'barcode_formats'          => ['ean13'],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function save(string $endpoint, array $post): array
    {
        $this->resetSession();

        $response = $this->post($endpoint, $post);
        $response->assertStatus(200);

        return json_decode($response->getJSON(), true);
    }

    // ========== quantity_decimals: the weight that disappears without a sound ==========

    public function testRefusesDroppingTheDecimalsThatCarryTheWeight(): void
    {
        $this->given(['quantity_decimals' => '3']);

        $result = $this->save('/config/saveLocale', $this->localePost(['quantity_decimals' => '0']));

        $this->assertFalse($result['success']);
        $this->seeInDatabase('app_config', ['key' => 'quantity_decimals', 'value' => '3']);
    }

    public function testRefusesAnyOtherNumberOfDecimalsToo(): void
    {
        $this->given(['quantity_decimals' => '3']);

        $result = $this->save('/config/saveLocale', $this->localePost(['quantity_decimals' => '2']));

        $this->assertFalse($result['success']);
        $this->seeInDatabase('app_config', ['key' => 'quantity_decimals', 'value' => '3']);
    }

    public function testTheRefusalNamesTheSettingItRefused(): void
    {
        $this->given(['quantity_decimals' => '3']);

        $result = $this->save('/config/saveLocale', $this->localePost(['quantity_decimals' => '0']));

        $this->assertStringContainsString(lang('Config.quantity_decimals'), $result['message']);
    }

    // ========== language_code: derived from the select, not a field of its own ==========

    public function testRefusesTheOtherSpanishThroughTheLanguageSelect(): void
    {
        // The screen posts one "code:name" value. A lock that only looked for a field called
        // language_code would never see this coming.
        $this->given(['language_code' => 'es-MX', 'language' => 'spanish']);

        $result = $this->save('/config/saveLocale', $this->localePost(['language' => 'es-ES:spanish']));

        $this->assertFalse($result['success']);
        $this->seeInDatabase('app_config', ['key' => 'language_code', 'value' => 'es-MX']);
    }

    public function testTheLanguageNameIsNotChangedEitherWhenTheCodeIsRefused(): void
    {
        $this->given(['language_code' => 'es-MX', 'language' => 'spanish']);

        $this->save('/config/saveLocale', $this->localePost(['language' => 'en:english']));

        $this->seeInDatabase('app_config', ['key' => 'language', 'value' => 'spanish']);
    }

    public function testRefusesEnglish(): void
    {
        $this->given(['language_code' => 'es-MX', 'language' => 'spanish']);

        $result = $this->save('/config/saveLocale', $this->localePost(['language' => 'en:english']));

        $this->assertFalse($result['success']);
        $this->seeInDatabase('app_config', ['key' => 'language_code', 'value' => 'es-MX']);
    }

    public function testTheRefusalNamesTheLanguageByTheFieldTheBusinessSees(): void
    {
        $this->given(['language_code' => 'es-MX', 'language' => 'spanish']);

        $result = $this->save('/config/saveLocale', $this->localePost(['language' => 'es-ES:spanish']));

        $this->assertStringContainsString(lang('Config.language'), $result['message']);
    }

    // ========== barcode_content: the typed code that sells a different product ==========

    public function testRefusesPuttingTheInternalIdBackIntoTheBarcode(): void
    {
        $this->given(['barcode_content' => 'item_number']);

        $result = $this->save('/config/saveBarcode', $this->barcodePost(['barcode_content' => 'id']));

        $this->assertFalse($result['success']);
        $this->seeInDatabase('app_config', ['key' => 'barcode_content', 'value' => 'item_number']);
    }

    public function testTheBarcodeRefusalNamesItsSetting(): void
    {
        $this->given(['barcode_content' => 'item_number']);

        $result = $this->save('/config/saveBarcode', $this->barcodePost(['barcode_content' => 'id']));

        $this->assertStringContainsString(lang('Config.barcode_content'), $result['message']);
    }

    // ========== Everything else on those screens is still the business's own ==========

    public function testTheRestOfTheLocaleScreenStillSaves(): void
    {
        $this->given([
            'quantity_decimals' => '3',
            'language_code'     => 'es-MX',
            'currency_decimals' => '0',
            'tax_decimals'      => '2',
        ]);

        $result = $this->save('/config/saveLocale', $this->localePost([
            'currency_decimals' => '2',
            'tax_decimals'      => '4',
            'timezone'          => 'America/Mexico_City',
        ]));

        $this->assertTrue($result['success']);
        $this->seeInDatabase('app_config', ['key' => 'currency_decimals', 'value' => '2']);
        $this->seeInDatabase('app_config', ['key' => 'tax_decimals', 'value' => '4']);
        $this->seeInDatabase('app_config', ['key' => 'timezone', 'value' => 'America/Mexico_City']);
    }

    public function testSavingTheLocaleScreenLeavesTheWiredKeysExactlyWhereTheyWere(): void
    {
        $this->given(['quantity_decimals' => '3', 'language_code' => 'es-MX', 'language' => 'spanish']);

        $this->save('/config/saveLocale', $this->localePost(['currency_decimals' => '1']));

        // The two disabled fields send nothing at all. "Absent" must mean "not offered", never
        // "clear it": writing an empty language_code here would take every screen to English.
        $this->seeInDatabase('app_config', ['key' => 'quantity_decimals', 'value' => '3']);
        $this->seeInDatabase('app_config', ['key' => 'language_code', 'value' => 'es-MX']);
        $this->seeInDatabase('app_config', ['key' => 'language', 'value' => 'spanish']);
    }

    public function testTheRestOfTheBarcodeScreenStillSaves(): void
    {
        $this->given(['barcode_content' => 'item_number', 'barcode_width' => '250']);

        $result = $this->save('/config/saveBarcode', $this->barcodePost(['barcode_width' => '300']));

        $this->assertTrue($result['success']);
        $this->seeInDatabase('app_config', ['key' => 'barcode_width', 'value' => '300']);
        $this->seeInDatabase('app_config', ['key' => 'barcode_content', 'value' => 'item_number']);
    }

    // ========== A refusal saves nothing at all ==========

    public function testARefusedLocaleSaveChangesNothingElseEither(): void
    {
        // The alternative -- dropping the wired key and saving the other fourteen -- would put a
        // green "saved" on a screen that did not do what it says. That silent gap between what the
        // screen shows and what the database holds is the whole family of bugs D12 exists to stop.
        $this->given(['quantity_decimals' => '3', 'currency_decimals' => '0']);

        $result = $this->save('/config/saveLocale', $this->localePost([
            'quantity_decimals' => '0',
            'currency_decimals' => '2',
        ]));

        $this->assertFalse($result['success']);
        $this->seeInDatabase('app_config', ['key' => 'currency_decimals', 'value' => '0']);
    }

    public function testARefusedBarcodeSaveChangesNothingElseEither(): void
    {
        $this->given(['barcode_content' => 'item_number', 'barcode_width' => '250']);

        $result = $this->save('/config/saveBarcode', $this->barcodePost([
            'barcode_content' => 'id',
            'barcode_width'   => '300',
        ]));

        $this->assertFalse($result['success']);
        $this->seeInDatabase('app_config', ['key' => 'barcode_width', 'value' => '250']);
    }

    public function testBothWiredKeysOfTheLocaleScreenAreNamedAtOnce(): void
    {
        $this->given(['quantity_decimals' => '3', 'language_code' => 'es-MX']);

        $result = $this->save('/config/saveLocale', $this->localePost([
            'quantity_decimals' => '0',
            'language'          => 'es-ES:spanish',
        ]));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString(lang('Config.language'), $result['message']);
        $this->assertStringContainsString(lang('Config.quantity_decimals'), $result['message']);
    }

    // ========== Re-sending what is already there, and the way out for a miswired business ==========

    public function testSendingTheValuesThatAreAlreadyStoredIsAccepted(): void
    {
        // What a tampered-with page would send if somebody removed the disabled attribute and
        // pressed Save without touching either field. Nothing moves, so nothing is refused.
        $this->given(['quantity_decimals' => '3', 'language_code' => 'es-MX', 'language' => 'spanish']);

        $result = $this->save('/config/saveLocale', $this->localePost([
            'quantity_decimals' => '3',
            'language'          => 'es-MX:spanish',
        ]));

        $this->assertTrue($result['success']);
        $this->seeInDatabase('app_config', ['key' => 'quantity_decimals', 'value' => '3']);
    }

    public function testABusinessLeftOnTheSeedValueCanStillBeMovedOntoTheRequiredOne(): void
    {
        // A business provisioned before the profile existed sits on the seed's 0. If the lock
        // refused this move as well it would cement the exact value it exists to prevent.
        $this->given(['quantity_decimals' => '0']);

        $result = $this->save('/config/saveLocale', $this->localePost(['quantity_decimals' => '3']));

        $this->assertTrue($result['success']);
        $this->seeInDatabase('app_config', ['key' => 'quantity_decimals', 'value' => '3']);
    }

    public function testAMiswiredBusinessStillCannotPickAThirdValue(): void
    {
        $this->given(['quantity_decimals' => '0']);

        $result = $this->save('/config/saveLocale', $this->localePost(['quantity_decimals' => '2']));

        $this->assertFalse($result['success']);
        $this->seeInDatabase('app_config', ['key' => 'quantity_decimals', 'value' => '0']);
    }

    public function testABusinessLeftOnTheInternalIdCanStillBeMovedOntoTheItemNumber(): void
    {
        $this->given(['barcode_content' => 'id']);

        $result = $this->save('/config/saveBarcode', $this->barcodePost(['barcode_content' => 'item_number']));

        $this->assertTrue($result['success']);
        $this->seeInDatabase('app_config', ['key' => 'barcode_content', 'value' => 'item_number']);
    }
}
