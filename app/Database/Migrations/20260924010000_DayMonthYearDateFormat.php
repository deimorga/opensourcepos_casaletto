<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;
use Config\OSPOS;
use Throwable;

/**
 * Dates as the businesses read them: day/month/year. Decided by the owner on 2026-09-24.
 *
 * Every business shipped with upstream's US default, m/d/Y. In Colombia "10/09/2026" reads as the
 * 10th of September; the system meant the 9th of October -- and since quotes print "Válida hasta",
 * a customer could read a valid quote as expired.
 *
 * WHAT CHANGES AND WHAT DOES NOT
 *
 * `dateformat` is one setting used both to SHOW dates and to READ the dates typed into forms
 * (Cashups, Expenses, Sales, Receivings, Customers, Writeoffs, date attributes all parse with
 * date_create_from_format($config['dateformat'] ...)). Changing it is therefore consistent in both
 * directions. Stored dates are DATETIME and are not touched. Tables sort server-side on the real
 * column; the range picker sends YYYY-MM-DD. See docs/Tecnico/formato-de-fecha.md.
 *
 * ONLY WHAT NOBODY CHOSE
 *
 * m/d/Y -> d/m/Y and m/d/y -> d/m/y, and only for a business working in Spanish (language_code es*).
 * Any other format was chosen by somebody and is left alone; a business in English keeps the US order.
 * down() reverts only a value this migration could have written.
 */
class Migration_DayMonthYearDateFormat extends Migration
{
    private const TABLE = 'app_config';

    /**
     * US order => day-first order.
     */
    public const SWAPS = [
        'm/d/Y' => 'd/m/Y',
        'm/d/y' => 'd/m/y',
    ];

    public function up(): void
    {
        $changed = 0;

        if ($this->works_in_spanish()) {
            foreach (self::SWAPS as $us => $day_first) {
                // BINARY: app_config's collation ignores case, and 'm/d/Y' would also match 'm/d/y' --
                // turning a two-digit-year business into a four-digit one. Caught by
                // DayMonthYearMigrationTest::testTheTwoDigitYearVariantMovesToo.
                $this->db->table(self::TABLE)->where('key', 'dateformat')->where('BINARY value = ' . $this->db->escape($us), null, false)->update(['value' => $day_first]);
                $changed += $this->db->affectedRows();
            }
        }

        CLI::write('DayMonthYearDateFormat: ' . ($changed > 0 ? 'date format set to day/month/year.' : 'left as it was.'));

        $this->refreshSettingsCache();
    }

    public function down(): void
    {
        if ($this->works_in_spanish()) {
            foreach (self::SWAPS as $us => $day_first) {
                $this->db->table(self::TABLE)->where('key', 'dateformat')->where('BINARY value = ' . $this->db->escape($day_first), null, false)->update(['value' => $us]);
            }
        }

        $this->refreshSettingsCache();
    }

    private function works_in_spanish(): bool
    {
        $row = $this->db->table(self::TABLE)->where('key', 'language_code')->get()->getRow();

        return $row !== null && str_starts_with(strtolower((string) $row->value), 'es');
    }

    /**
     * Same reach as QuoteValidityAndSpanishDefaults::refreshSettingsCache(): in CLI only the plain
     * `settings` key; a tenant's cache starts empty on every container recreation.
     */
    private function refreshSettingsCache(): void
    {
        try {
            config(OSPOS::class)->update_settings();
        } catch (Throwable $e) {
            CLI::write('  ! DayMonthYearDateFormat: could not refresh the settings cache (' . $e->getMessage() . ').');
            log_message('warning', 'DayMonthYearDateFormat: settings cache not refreshed: ' . $e->getMessage());
        }
    }
}
