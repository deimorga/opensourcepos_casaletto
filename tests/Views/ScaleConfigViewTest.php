<?php

namespace Tests\Views;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The configuration screens are one page shared by every tenant on the platform.
 *
 * That is what makes this worth a test with no database in it: the tenant that matters here is the
 * one that has been selling every day since long before a scale was ever discussed, whose app_config
 * has no scale_* row at all and whose settings cache may not have it for a while after the migration
 * either. A direct $config['scale_format'] in this view would not break the scale tab -- it would
 * take that tenant's whole configuration page down, tax rates and receipts included.
 */
class ScaleConfigViewTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper(['form', 'url']);
    }

    public function testRendersForATenantWithNoScaleSettingsAtAll(): void
    {
        $html = view('configs/scale_config', ['config' => []]);

        $this->assertStringContainsString('scale_config_form', $html);
        $this->assertStringContainsString('name="scale_format"', $html);
        $this->assertStringContainsString('name="scale_divisor"', $html);
        $this->assertStringContainsString('name="scale_port"', $html);
        $this->assertStringContainsString('name="scale_transport"', $html);
    }

    public function testDefaultsToNoScaleWhenNothingIsConfigured(): void
    {
        $html = view('configs/scale_config', ['config' => []]);

        $this->assertMatchesRegularExpression('/name="scale_format"[^>]*value=""/', $html);
        $this->assertMatchesRegularExpression('/name="scale_divisor"[^>]*value="1"/', $html);
    }

    public function testShowsTheConfiguredValues(): void
    {
        $html = view('configs/scale_config', [
            'config' => [
                'scale_format'    => 'N{W:6}',
                'scale_divisor'   => '1',
                'scale_port'      => 'COM3',
                'scale_transport' => 'agent'
            ]
        ]);

        $this->assertStringContainsString('value="N{W:6}"', $html);
        $this->assertStringContainsString('value="COM3"', $html);
        $this->assertMatchesRegularExpression('/<option value="agent" selected/', $html);
    }

    public function testEveryLabelOnTheScreenIsTranslated(): void
    {
        $html = view('configs/scale_config', ['config' => []]);

        // A key with no translation renders as its own name, so this catches all of them at once.
        $this->assertStringNotContainsString('Config.scale', $html);

        // The two the tab strip in manage.php uses, which this view does not render.
        $this->assertNotSame('Config.scale', lang('Config.scale'));
        $this->assertNotSame('Config.scale_configuration', lang('Config.scale_configuration'));
    }

    public function testEscapesAPatternThatCarriesMarkup(): void
    {
        // The pattern is free text typed by an administrator and it is a regular expression, so it
        // is stored unfiltered on purpose. Which means the escaping has to happen here.
        $html = view('configs/scale_config', ['config' => ['scale_format' => '"><script>alert(1)</script>']]);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }
}
