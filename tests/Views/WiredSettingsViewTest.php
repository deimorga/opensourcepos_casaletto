<?php

declare(strict_types=1);

namespace Tests\Views;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * What the two configuration screens show for D12's three wired keys.
 *
 * The server-side refusal is what actually protects them (tests/Controllers/ConfigWiringLockTest),
 * but the mandate has a second half that is just as deliberate: the fields must be VISIBLE and
 * fixed, never hidden. A setting that disappears reads as "this system cannot do that", and the
 * business calls to ask why its point of sale is missing something.
 *
 * WRITTEN, NOT RUN: certification happens against staging, and not by whoever wrote the code.
 * These render the real views, so they want what `composer test` provides -- the OSPOS settings the
 * barcode screen reads directly for `barcode_formats` come from the migrated database.
 *
 * @internal
 */
final class WiredSettingsViewTest extends CIUnitTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function localeConfig(array $overrides = []): array
    {
        return array_merge([
            'number_locale'         => 'es_CO',
            'thousands_separator'   => '1',
            'currency_symbol'       => '$',
            'currency_decimals'     => '0',
            'tax_decimals'          => '2',
            'quantity_decimals'     => '3',
            'cash_decimals'         => '0',
            'cash_rounding_code'    => '0',
            'payment_options_order' => 'cashdebitcredit',
            'country_codes'         => 'co',
            'language'              => 'spanish',
            'language_code'         => 'es-MX',
            'timezone'              => 'America/Bogota',
            'dateformat'            => 'd/m/Y',
            'timeformat'            => 'H:i:s',
            'date_or_time_format'   => '0',
            'financial_year'        => '1',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function barcodeConfig(array $overrides = []): array
    {
        return array_merge([
            'barcode_type'              => 'Code39',
            'barcode_width'             => '250',
            'barcode_height'            => '50',
            'barcode_font'              => 'Arial',
            'barcode_font_size'         => '10',
            'barcode_first_row'         => 'category',
            'barcode_second_row'        => 'name',
            'barcode_third_row'         => 'unit_price',
            'barcode_num_in_row'        => '2',
            'barcode_page_width'        => '100',
            'barcode_page_cellspacing'  => '0',
            'barcode_generate_if_empty' => '1',
            'allow_duplicate_barcodes'  => '0',
            'barcode_content'           => 'item_number',
        ], $overrides);
    }

    private function locale(array $overrides = []): string
    {
        return view('configs/locale_config', [
            'config'           => $this->localeConfig($overrides),
            'currency_code'    => 'COP',
            'rounding_options' => ['0' => 'None'],
            'controller_name'  => 'config',
        ]);
    }

    private function barcode(array $overrides = []): string
    {
        return view('configs/barcode_config', [
            'config'          => $this->barcodeConfig($overrides),
            'support_barcode' => ['Code39' => 'Code39'],
            'barcode_fonts'   => ['Arial' => 'Arial'],
        ]);
    }

    // ========== The fields are still there ==========

    public function testTheDecimalsFieldIsStillOnTheScreen(): void
    {
        // Hiding it would make the business believe its point of sale has no such setting.
        $this->assertStringContainsString('name="quantity_decimals"', $this->locale());
    }

    public function testTheLanguageFieldIsStillOnTheScreen(): void
    {
        $this->assertStringContainsString('name="language"', $this->locale());
    }

    public function testTheBarcodeContentFieldIsStillOnTheScreen(): void
    {
        $this->assertStringContainsString('name="barcode_content"', $this->barcode());
    }

    // ========== And they are fixed ==========

    public function testTheDecimalsFieldCannotBeTouched(): void
    {
        $this->assertMatchesRegularExpression(
            '/<select [^>]*name="quantity_decimals"[^>]*disabled/',
            $this->locale()
        );
    }

    public function testTheLanguageFieldCannotBeTouched(): void
    {
        $this->assertMatchesRegularExpression(
            '/<select [^>]*name="language"[^>]*disabled/',
            $this->locale()
        );
    }

    public function testBothBarcodeContentChoicesCannotBeTouched(): void
    {
        $html = $this->barcode();

        $this->assertSame(
            2,
            preg_match_all('/<input [^>]*name="barcode_content"[^>]*disabled/', $html),
            'both radios have to be fixed, not just the one that is not selected'
        );
    }

    public function testNothingElseOnTheLocaleScreenIsFixed(): void
    {
        $html = $this->locale();

        foreach (['currency_decimals', 'tax_decimals', 'timezone', 'country_codes'] as $theirs) {
            $this->assertDoesNotMatchRegularExpression(
                '/<(select|input) [^>]*name="' . $theirs . '"[^>]*disabled/',
                $html,
                $theirs . ' is the business own setting'
            );
        }
    }

    public function testNothingElseOnTheBarcodeScreenIsFixed(): void
    {
        $html = $this->barcode();

        foreach (['barcode_type', 'barcode_width', 'barcode_generate_if_empty'] as $theirs) {
            $this->assertDoesNotMatchRegularExpression(
                '/<(select|input) [^>]*name="' . $theirs . '"[^>]*disabled/',
                $html,
                $theirs . ' is the business own setting'
            );
        }
    }

    // ========== With the reason in sight ==========

    public function testTheDecimalsFieldSaysWhyItIsFixed(): void
    {
        $html = $this->locale();

        $this->assertStringContainsString(lang('Config.wired_quantity_decimals_help'), $html);
        $this->assertStringContainsString('aria-describedby="quantity_decimals_wired"', $html);
        $this->assertStringContainsString('id="quantity_decimals_wired"', $html);
    }

    public function testTheLanguageFieldSaysWhyItIsFixed(): void
    {
        $html = $this->locale();

        $this->assertStringContainsString(lang('Config.wired_language_help'), $html);
        $this->assertStringContainsString('aria-describedby="language_wired"', $html);
        $this->assertStringContainsString('id="language_wired"', $html);
    }

    public function testTheBarcodeContentFieldSaysWhyItIsFixed(): void
    {
        $html = $this->barcode();

        $this->assertStringContainsString(lang('Config.wired_barcode_content_help'), $html);
        $this->assertStringContainsString('aria-describedby="barcode_content_wired"', $html);
        $this->assertStringContainsString('id="barcode_content_wired"', $html);
    }

    public function testEveryReasonIsTranslated(): void
    {
        // An untranslated key renders as its own name, which is how a missing string reaches
        // production without a single error anywhere.
        $this->assertStringNotContainsString('Config.wired', $this->locale());
        $this->assertStringNotContainsString('Config.wired', $this->barcode());
    }

    public function testTheReasonsExistInTheLanguageTheApplicationActuallyRunsIn(): void
    {
        // The application runs in es-MX. A string written only in `en` renders as "Config.whatever"
        // on every screen the business sees, and CodeIgniter never falls back to English.
        $spanish = require APPPATH . 'Language/es-MX/Config.php';

        foreach (
            [
                'wired_barcode_content_help',
                'wired_language_help',
                'wired_quantity_decimals_help',
                'wired_setting_mismatch',
                'wired_setting_refused',
            ] as $key
        ) {
            $this->assertArrayHasKey($key, $spanish, $key);
            $this->assertNotSame('', $spanish[$key], $key . ' is empty in es-MX');
        }
    }

    // ========== A business that is not on the required value is told so ==========

    public function testABusinessAlreadyWiredRightIsNotWarned(): void
    {
        $this->assertStringNotContainsString(
            lang('Config.wired_setting_mismatch', ['3']),
            $this->locale()
        );
    }

    public function testABusinessLeftOnTheSeedDecimalsIsWarned(): void
    {
        // What initial_schema.sql leaves behind. The lock keeps it there, so the screen has to say
        // so out loud instead of freezing the wrong value in silence.
        $this->assertStringContainsString(
            lang('Config.wired_setting_mismatch', ['3']),
            $this->locale(['quantity_decimals' => '0'])
        );
    }

    public function testABusinessLeftOnTheInternalIdIsWarned(): void
    {
        $this->assertStringContainsString(
            lang('Config.wired_setting_mismatch', ['item_number']),
            $this->barcode(['barcode_content' => 'id'])
        );
    }

    public function testABusinessOnTheOtherSpanishIsWarned(): void
    {
        $this->assertStringContainsString(
            lang('Config.wired_setting_mismatch', ['es-MX']),
            $this->locale(['language_code' => 'es-ES'])
        );
    }

    // ========== The barcode radios finally show the truth ==========

    /**
     * `number` es como se llamaba antes esta opción, y `Barcode_lib` la lee igual que `item_number`
     * (Barcode_lib.php:183: cualquier cosa que no sea `id`). Un negocio que la arrastre está en el
     * valor correcto, y avisarle de lo contrario es una alarma que además no tiene forma de quitar,
     * porque los radios están deshabilitados.
     */
    public function testTheLegacyBarcodeValueIsNotWarnedAbout(): void
    {
        $this->assertStringNotContainsString(
            lang('Config.wired_setting_mismatch', ['item_number']),
            $this->barcode(['barcode_content' => 'number'])
        );
    }

    public function testTheItemNumberRadioIsSelectedForABusinessWiredToItemNumber(): void
    {
        // It used to compare against 'number', so a business on 'item_number' -- the value D12
        // requires, and the one Paraiso has had since the collision incident -- saw neither radio
        // selected and no way to tell what its own point of sale was doing.
        $this->assertMatchesRegularExpression(
            '/<input [^>]*value="item_number"[^>]*checked/',
            $this->barcode(['barcode_content' => 'item_number'])
        );
    }

    public function testTheIdRadioIsSelectedForABusinessStillOnTheInternalId(): void
    {
        $html = $this->barcode(['barcode_content' => 'id']);

        $this->assertMatchesRegularExpression('/<input [^>]*value="id"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input [^>]*value="item_number"[^>]*checked/', $html);
    }

    public function testTheOldNumberValueStillReadsAsTheItemNumber(): void
    {
        // Barcode_lib reads anything that is not 'id' as the item number, so a business saved by
        // the old screen sits on 'number' and behaves exactly like 'item_number'.
        $this->assertMatchesRegularExpression(
            '/<input [^>]*value="item_number"[^>]*checked/',
            $this->barcode(['barcode_content' => 'number'])
        );
    }
}
