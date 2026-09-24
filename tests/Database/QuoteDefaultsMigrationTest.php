<?php

namespace Tests\Database;

use App\Database\Migrations\Migration_QuoteValidityAndSpanishDefaults;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\OSPOS;

/**
 * 20260924000000_QuoteValidityAndSpanishDefaults: a validity period for quotes, and no English sample
 * text on a Spanish-speaking business's quotes, invoices and emails.
 *
 * The rule under test is the careful one: replace a setting ONLY when it still holds upstream's exact
 * sample text AND the business works in Spanish. What a business wrote is never touched.
 *
 * The test database is shared between test files, so every key this file changes is put back in
 * tearDown.
 *
 * @internal
 */
final class QuoteDefaultsMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const KEYS = ['language_code', 'quote_validity_days', 'quote_default_comments', 'invoice_default_comments', 'invoice_email_message'];

    /** @var array<string, string|null> */
    private array $before = [];

    protected function setUp(): void
    {
        parent::setUp();

        Database::connect()->resetDataCache();

        foreach (self::KEYS as $key) {
            $row                = Database::connect()->table('app_config')->where('key', $key)->get()->getRowArray();
            $this->before[$key] = $row === null ? null : (string) $row['value'];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->before as $key => $value) {
            $builder = Database::connect()->table('app_config');

            if ($value === null) {
                $builder->where('key', $key)->delete();
            } else {
                $builder->replace(['key' => $key, 'value' => $value]);
            }
        }

        // up() refreshes the cached settings map; leave it describing the restored rows.
        config(OSPOS::class)->update_settings();

        parent::tearDown();
    }

    public function testASpanishBusinessLosesTheUntouchedEnglishSamples(): void
    {
        $this->set('language_code', 'es-MX');
        $this->setEnglishSamples();

        $this->runMigration();

        $this->assertSame('', $this->value('quote_default_comments'));
        $this->assertSame('', $this->value('invoice_default_comments'));
        $this->assertSame('Estimado(a) {CU}: adjuntamos el documento {ISEQ}.', $this->value('invoice_email_message'));
    }

    public function testWhatTheBusinessWroteIsNeverTouched(): void
    {
        $this->set('language_code', 'es-MX');
        $this->setEnglishSamples();
        $this->set('quote_default_comments', 'Precios sujetos a disponibilidad.');

        $this->runMigration();

        $this->assertSame('Precios sujetos a disponibilidad.', $this->value('quote_default_comments'));
    }

    public function testABusinessInEnglishKeepsUpstreamsText(): void
    {
        $this->set('language_code', 'en');
        $this->setEnglishSamples();

        $this->runMigration();

        $this->assertSame('This is a default quote comment', $this->value('quote_default_comments'));
    }

    public function testTheValidityIsSeededAndNeverOverwritten(): void
    {
        Database::connect()->table('app_config')->where('key', 'quote_validity_days')->delete();
        $this->runMigration();
        $this->assertSame('15', $this->value('quote_validity_days'));

        $this->set('quote_validity_days', '30');
        $this->runMigration();
        $this->assertSame('30', $this->value('quote_validity_days'));
    }

    private function setEnglishSamples(): void
    {
        foreach (Migration_QuoteValidityAndSpanishDefaults::SPANISH_REPLACEMENTS as $key => [$english]) {
            $this->set($key, $english);
        }
    }

    private function runMigration(): void
    {
        ob_start();

        try {
            (new Migration_QuoteValidityAndSpanishDefaults())->up();
        } finally {
            ob_end_clean();
        }
    }

    private function set(string $key, string $value): void
    {
        Database::connect()->table('app_config')->replace(['key' => $key, 'value' => $value]);
    }

    private function value(string $key): ?string
    {
        $row = Database::connect()->table('app_config')->where('key', $key)->get()->getRowArray();

        return $row === null ? null : (string) $row['value'];
    }
}
