<?php

namespace Tests\Views;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The presales tab of the configuration screen, rendered on its own.
 *
 * Rendered directly and not through /config: the full configuration page lists barcode fonts from a
 * directory the CI runner does not have, which is a fact about the runner and not about this tab. Same
 * approach as OrderTicketsConfigViewTest.
 *
 * @internal
 */
final class PresalesConfigViewTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper(['form', 'url']);
    }

    /**
     * A tenant whose settings cache predates the migration has none of the keys. The configuration
     * screen is shared by every tenant, so this tab must render anyway, with the module off.
     */
    public function testRendersForATenantWithNoPresalesSettingsAtAll(): void
    {
        $html = view('configs/presales_config', ['config' => [], 'presales_open' => 0]);

        $this->assertStringContainsString('id="presales_config_form"', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="presales_enable"[^>]*checked/', $html);
        $this->assertStringContainsString('value="PV-"', $html);
    }

    public function testTheSwitchShowsOnWhenTheBusinessTurnedItOn(): void
    {
        $html = view('configs/presales_config', ['config' => ['presales_enable' => '1'], 'presales_open' => 0]);

        $this->assertMatchesRegularExpression('/<input[^>]*name="presales_enable"[^>]*checked/', $html);
    }

    /**
     * The conditions are written by the business and printed back; whatever they contain is escaped.
     */
    public function testTheSavedConditionsAreEscaped(): void
    {
        $html = view('configs/presales_config', ['config' => ['presales_terms' => 'Señor José <script>alert(1)</script>'], 'presales_open' => 0]);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('Señor José', $html, 'Accents are kept as they are, not turned into entities.');
    }

    /**
     * The suggested text reaches JavaScript intact, line breaks and accents included, and cannot close
     * the script tag it sits in.
     */
    public function testTheSuggestedTextIsAvailableToTheButton(): void
    {
        $html = view('configs/presales_config', ['config' => [], 'presales_open' => 0]);

        $this->assertStringContainsString('id="presales_use_suggested"', $html);
        $this->assertStringContainsString(json_encode(lang('Presales.terms_template'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), $html);
    }

    public function testTheOpenCountIsHandedToTheWarning(): void
    {
        $html = view('configs/presales_config', ['config' => ['presales_enable' => '1'], 'presales_open' => 7]);

        $this->assertStringContainsString('var presales_open = 7;', $html);
    }

    public function testTheTabIsOnTheConfigurationScreen(): void
    {
        $manage = file_get_contents(APPPATH . 'Views/configs/manage.php');

        $this->assertStringContainsString('href="#presales_tab"', $manage);
        $this->assertStringContainsString('id="presales_tab"', $manage);
        $this->assertStringContainsString("view('configs/presales_config')", $manage);
    }
}
