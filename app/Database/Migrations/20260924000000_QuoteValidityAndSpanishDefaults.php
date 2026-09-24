<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;
use Config\OSPOS;
use Throwable;

/**
 * Quotes, 2026-09-24: a validity period, and no English boilerplate on a Spanish-speaking business's
 * documents.
 *
 * 1. quote_validity_days, seeded at 15. Printed on every quote as "Válida hasta <fecha>"; 0 prints
 *    nothing. Never overwritten if it already exists.
 *
 * 2. Three settings still hold upstream's English sample text, which prints on real documents:
 *
 *      quote_default_comments   "This is a default quote comment"   -> printed on every quote
 *      invoice_default_comments "This is a default comment"         -> printed on every invoice;
 *                                                                      Casaletto had issued 5 with it
 *      invoice_email_message    "Dear {CU}, In attachment the ..."   -> the body of every emailed
 *                                                                      quote and invoice
 *
 *    Each is replaced ONLY when it still holds exactly that sample text -- anything a business wrote
 *    is left alone -- and ONLY when the business works in Spanish (language_code es*). A business in
 *    English keeps upstream's text, which is at least in its language.
 *
 * down() removes the validity key if it still holds the default, and puts the English samples back
 * only where the Spanish replacement is still untouched.
 *
 * See docs/Tecnico/cotizaciones.md.
 */
class Migration_QuoteValidityAndSpanishDefaults extends Migration
{
    private const TABLE            = 'app_config';
    private const VALIDITY_KEY     = 'quote_validity_days';
    private const VALIDITY_DEFAULT = '15';

    /**
     * key => [upstream English sample, Spanish replacement].
     */
    public const SPANISH_REPLACEMENTS = [
        'quote_default_comments'   => ['This is a default quote comment', ''],
        'invoice_default_comments' => ['This is a default comment', ''],
        'invoice_email_message'    => ['Dear {CU}, In attachment the receipt for sale {ISEQ}', 'Estimado(a) {CU}: adjuntamos el documento {ISEQ}.'],
    ];

    public function up(): void
    {
        $seeded = 0;

        if (! $this->exists(self::VALIDITY_KEY)) {
            $this->db->table(self::TABLE)->insert(['key' => self::VALIDITY_KEY, 'value' => self::VALIDITY_DEFAULT]);
            $seeded = 1;
        }

        $replaced = 0;

        if ($this->works_in_spanish()) {
            foreach (self::SPANISH_REPLACEMENTS as $key => [$english, $spanish]) {
                $this->db->table(self::TABLE)->where('key', $key)->where('value', $english)->update(['value' => $spanish]);
                $replaced += $this->db->affectedRows();
            }
        }

        CLI::write('QuoteValidityAndSpanishDefaults: validity ' . ($seeded ? 'seeded' : 'already set') . '; ' . $replaced . ' English sample text(s) replaced.');

        $this->refreshSettingsCache();
    }

    public function down(): void
    {
        $this->db->table(self::TABLE)->where('key', self::VALIDITY_KEY)->where('value', self::VALIDITY_DEFAULT)->delete();

        if ($this->works_in_spanish()) {
            foreach (self::SPANISH_REPLACEMENTS as $key => [$english, $spanish]) {
                $this->db->table(self::TABLE)->where('key', $key)->where('value', $spanish)->update(['value' => $english]);
            }
        }

        $this->refreshSettingsCache();
    }

    private function works_in_spanish(): bool
    {
        $row = $this->db->table(self::TABLE)->where('key', 'language_code')->get()->getRow();

        return $row !== null && str_starts_with(strtolower((string) $row->value), 'es');
    }

    private function exists(string $key): bool
    {
        return $this->db->table(self::TABLE)->where('key', $key)->countAllResults() > 0;
    }

    /**
     * Same reach and same honesty as AddOrderTicketsConfigKeys::refreshSettingsCache(): in CLI this
     * clears only the plain `settings` key; a tenant's cache starts empty on every container
     * recreation because writable/cache is not a volume.
     */
    private function refreshSettingsCache(): void
    {
        try {
            config(OSPOS::class)->update_settings();
        } catch (Throwable $e) {
            CLI::write('  ! QuoteValidityAndSpanishDefaults: could not refresh the settings cache (' . $e->getMessage() . ').');
            log_message('warning', 'QuoteValidityAndSpanishDefaults: settings cache not refreshed: ' . $e->getMessage());
        }
    }
}
