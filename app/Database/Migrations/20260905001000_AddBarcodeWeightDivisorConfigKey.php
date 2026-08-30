<?php

namespace App\Database\Migrations;

use App\Libraries\Token_lib;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;
use Config\OSPOS;
use Throwable;

/**
 * Seeds the divisor that turns a {W:n} inside a barcode into a quantity.
 *
 * Token_lib::parse_barcode() divided by the literal 1000, which is a statement that every label
 * printer on earth prints grams. Plenty do -- and the ones that print whole units, or hundredths,
 * were simply unusable without editing PHP. The number is now a setting, seeded here at 1000 so
 * that no tenant's arithmetic moves: 1000 is exactly what the literal was.
 *
 * Nothing else changes. barcode_formats is '[]' on the tenant that is trading, so the branch that
 * reads this divisor has never executed there, and a tenant that never lists a format never will.
 *
 * The key is seeded rather than left absent so it is visible in app_config and can be set by hand.
 * parse_barcode() still reads it with ?? regardless -- the settings map is cached per tenant and a
 * register can be running new code against a cache that predates this migration (same reasoning as
 * 20260902000000_AddScaleConfigKeys, and see docs/Tecnico/venta-por-peso-y-hardware-de-caja.md
 * section 7c.3).
 *
 * No configuration screen exposes it: the barcode settings form writes a fixed list of keys
 * (Config::postSaveBarcode()) and app/Controllers/Config.php was out of scope for this change.
 * An extra row is therefore inert as far as that screen is concerned -- it neither shows it nor
 * overwrites it.
 */
class Migration_AddBarcodeWeightDivisorConfigKey extends Migration
{
    private const TABLE = 'app_config';
    private const KEY = 'barcode_weight_divisor';

    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        $default = (string) Token_lib::BARCODE_WEIGHT_DIVISOR_DEFAULT;

        if ($this->exists()) {
            CLI::write('AddBarcodeWeightDivisorConfigKey: ' . self::KEY . ' already set, left alone.');

            return;
        }

        $this->db->table(self::TABLE)->insert(['key' => self::KEY, 'value' => $default]);

        CLI::write('AddBarcodeWeightDivisorConfigKey: ' . self::KEY . " seeded at $default (grams), which is what the code did before.");

        $this->refreshSettingsCache();
    }

    /**
     * Revert a migration step.
     *
     * Removes the row only while it still holds the seeded default. A divisor somebody worked out
     * from a real label is data, and rolling the code back is no reason to throw it away.
     */
    public function down(): void
    {
        $this->db->table(self::TABLE)
            ->where('key', self::KEY)
            ->where('value', (string) Token_lib::BARCODE_WEIGHT_DIVISOR_DEFAULT)
            ->delete();

        CLI::write('AddBarcodeWeightDivisorConfigKey: ' . ($this->db->affectedRows() > 0
            ? 'default removed.'
            : 'a configured value was kept.'));

        $this->refreshSettingsCache();
    }

    private function exists(): bool
    {
        return $this->db->table(self::TABLE)->where('key', self::KEY)->countAllResults() > 0;
    }

    /**
     * See 20260902000000_AddScaleConfigKeys::refreshSettingsCache() for why this clears the plain
     * key and why that is the right reach on the deploy path. A cache that will not clear is not a
     * reason to fail a migration that already succeeded.
     */
    private function refreshSettingsCache(): void
    {
        try {
            config(OSPOS::class)->update_settings();
        } catch (Throwable $e) {
            CLI::write('  ! AddBarcodeWeightDivisorConfigKey: could not refresh the settings cache (' . $e->getMessage() . '). Clear it by hand for this tenant.');
            log_message('warning', 'AddBarcodeWeightDivisorConfigKey: settings cache not refreshed: ' . $e->getMessage());
        }
    }
}
