<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;
use Config\OSPOS;
use Throwable;

/**
 * Seeds presales_weight_refund_limit (owner's decision of 2026-10-07).
 *
 *   presales_weight_refund_limit  the percentage of a presale's total that a lighter weight at
 *                                 delivery may hand back in cash without someone holding the
 *                                 presales_manage permission. '15' by default; '0' means no limit.
 *
 * Same pattern as AddPresalesConfigKeys: never overwrites a value a business already has, and code
 * reads the key with `?? '15'` anyway (a tenant can run the new code against a settings cache that
 * predates this migration).
 *
 * See docs/Tecnico/venta-anticipada.md §7.6.
 */
class Migration_AddPresalesWeightRefundLimit extends Migration
{
    private const TABLE   = 'app_config';
    public const DEFAULTS = [
        'presales_weight_refund_limit' => '15',
    ];

    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        $seeded = 0;

        foreach (self::DEFAULTS as $key => $value) {
            if ($this->exists($key)) {
                continue;
            }

            $this->db->table(self::TABLE)->insert(['key' => $key, 'value' => $value]);
            $seeded++;
        }

        $this->report('AddPresalesWeightRefundLimit: ' . $seeded . ' of ' . count(self::DEFAULTS) . ' setting(s) seeded; existing values left alone.');

        $this->refreshSettingsCache();
    }

    /**
     * Revert a migration step. Only the untouched default is removed: a limit somebody set is data.
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
     * See AddPresalesConfigKeys::refreshSettingsCache().
     */
    private function refreshSettingsCache(): void
    {
        try {
            config(OSPOS::class)->update_settings();
        } catch (Throwable $e) {
            $this->report('  ! AddPresalesWeightRefundLimit: could not refresh the settings cache (' . $e->getMessage() . '). Clear it by hand for this tenant.');
            log_message('warning', 'AddPresalesWeightRefundLimit: settings cache not refreshed: ' . $e->getMessage());
        }
    }

    private function report(string $message): void
    {
        if (is_cli()) {
            CLI::write($message);
        }
    }
}
