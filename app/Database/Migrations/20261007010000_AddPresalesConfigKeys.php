<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;
use Config\OSPOS;
use Throwable;

/**
 * Seeds the settings of the presales module, with the module off.
 *
 *   presales_enable  the whole module. Off, the application behaves exactly as before: no menu
 *                    entry, nothing in the register, nothing in the cash-up.
 *   presales_prefix  what goes in front of the presale number printed for the customer (PV-000123).
 *   presales_terms   the conditions printed at the foot of every presale document. Seeded EMPTY:
 *                    the suggested wording lives in the language files (Presales.terms_template) and
 *                    the configuration screen copies it on request. Seeding it here would put one
 *                    language's text into every business on the platform.
 *
 * The module belongs to the platform, not to a business (D2): every tenant gets these rows, and none
 * of them notices anything until its own administrator turns the switch on.
 *
 * Code reads every key with `?? default` anyway: Config\OSPOS caches the whole settings map per
 * tenant, and a tenant can run the new code against a cache that predates this migration.
 *
 * See docs/Tecnico/venta-anticipada.md section 5.
 */
class Migration_AddPresalesConfigKeys extends Migration
{
    private const TABLE   = 'app_config';
    public const DEFAULTS = [
        'presales_enable' => '0',
        'presales_prefix' => 'PV-',
        'presales_terms'  => '',
    ];

    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        $seeded = 0;

        foreach (self::DEFAULTS as $key => $value) {
            // Never overwrite: a business may already have configured it, and re-running a migration
            // must not undo that.
            if ($this->exists($key)) {
                continue;
            }

            $this->db->table(self::TABLE)->insert(['key' => $key, 'value' => $value]);
            $seeded++;
        }

        $this->report('AddPresalesConfigKeys: ' . $seeded . ' of ' . count(self::DEFAULTS) . ' setting(s) seeded; existing values left alone.');

        $this->refreshSettingsCache();
    }

    /**
     * Revert a migration step.
     *
     * Only the untouched defaults are removed. A switch somebody turned on, or conditions somebody
     * wrote, are data and not schema.
     */
    public function down(): void
    {
        foreach (self::DEFAULTS as $key => $value) {
            $this->db->table(self::TABLE)
                ->where('key', $key)
                ->where('value', $value)
                ->delete();
        }

        $this->refreshSettingsCache();
    }

    private function exists(string $key): bool
    {
        return $this->db->table(self::TABLE)->where('key', $key)->countAllResults() > 0;
    }

    /**
     * Same reach, and the same honesty about it, as AddOrderTicketsConfigKeys::refreshSettingsCache():
     * in CLI the tenant is never resolved, so this clears the plain `settings` key, and every
     * container recreation starts with an empty cache anyway.
     */
    private function refreshSettingsCache(): void
    {
        try {
            config(OSPOS::class)->update_settings();
        } catch (Throwable $e) {
            $this->report('  ! AddPresalesConfigKeys: could not refresh the settings cache (' . $e->getMessage() . '). Clear it by hand for this tenant.');
            log_message('warning', 'AddPresalesConfigKeys: settings cache not refreshed: ' . $e->getMessage());
        }
    }

    private function report(string $message): void
    {
        if (is_cli()) {
            CLI::write($message);
        }
    }
}
