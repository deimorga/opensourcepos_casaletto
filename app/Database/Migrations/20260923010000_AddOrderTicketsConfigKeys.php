<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;
use Config\OSPOS;
use Throwable;

/**
 * Seeds the two switches that govern order tickets, both off.
 *
 * TWO SWITCHES AND NOT ONE
 *
 * D4 lets each business turn order tickets on or off. D15 makes the kitchen screen a **separate**
 * decision, because a business can take orders at the table and want nothing at all in the kitchen,
 * and the owner said so explicitly: it depends on the shop and on its size.
 *
 *   order_tickets_enable          the whole module. Off, the application behaves exactly as before:
 *                                 no menu entry, no useful routes, nothing in the register.
 *   order_tickets_kitchen_enable  only the kitchen screen (delivery 3). No effect while the first
 *                                 one is off.
 *
 * WHY THEY ARE SEEDED RATHER THAN LEFT ABSENT
 *
 * So the configuration screen has something to show and an operator can see the defaults. Code still
 * reads both with `?? '0'` regardless: Config\OSPOS caches the whole settings map per tenant, and a
 * tenant can be running the new code against a cache that predates this migration for as long as
 * that entry lives. An absent switch has to read as "off", which is also the right answer for a
 * feature D3 defines as never mandatory.
 *
 * See docs/Tecnico/comandas-y-cuenta-abierta.md sections 3.14 and 5.
 */
class Migration_AddOrderTicketsConfigKeys extends Migration
{
    private const TABLE = 'app_config';

    /**
     * Shipped off. A tenant that migrates and never opens the new tab is in exactly the state it was
     * in before.
     */
    private const DEFAULTS = [
        'order_tickets_enable'         => '0',
        'order_tickets_kitchen_enable' => '0',
    ];

    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        $seeded = 0;

        foreach (self::DEFAULTS as $key => $value) {
            // Never overwrite: a business may already have been configured by hand, and re-running a
            // migration must not undo that.
            if ($this->exists($key)) {
                continue;
            }

            $this->db->table(self::TABLE)->insert(['key' => $key, 'value' => $value]);
            $seeded++;
        }

        CLI::write('AddOrderTicketsConfigKeys: ' . $seeded . ' of ' . count(self::DEFAULTS) . ' setting(s) seeded; existing values left alone.');

        $this->refreshSettingsCache();
    }

    /**
     * Revert a migration step.
     *
     * Only the untouched defaults are removed. A switch somebody turned on for a real shop is data,
     * not schema, and rolling the code back is no reason to throw it away -- it will still be there,
     * and still correct, when the code rolls forward again.
     */
    public function down(): void
    {
        $removed = 0;
        $kept    = 0;

        foreach (self::DEFAULTS as $key => $value) {
            $this->db->table(self::TABLE)
                ->where('key', $key)
                ->where('value', $value)
                ->delete();

            if ($this->db->affectedRows() > 0) {
                $removed++;
            } elseif ($this->exists($key)) {
                $kept++;
            }
        }

        CLI::write('AddOrderTicketsConfigKeys: ' . $removed . ' default(s) removed, ' . $kept . ' configured value(s) kept.');

        $this->refreshSettingsCache();
    }

    private function exists(string $key): bool
    {
        return $this->db->table(self::TABLE)->where('key', $key)->countAllResults() > 0;
    }

    /**
     * Refresh the cached settings map so the new keys are visible without waiting for the entry to
     * expire.
     *
     * Honest about its reach, exactly as AddScaleConfigKeys documents: OSPOS::settingsCacheKey()
     * suffixes the key with the tenant slug only when TenantContext is resolved, and TenantContext
     * is populated exclusively by the HTTP filter. A migration runs in CLI, where it never is, so
     * this clears the plain `settings` key -- correct for a single-tenant install and a no-op for a
     * tenant schema. On the deploy path that is fine: the cache is a file handler under
     * writable/cache, which is not a mounted volume, so every container recreation starts empty.
     *
     * A cache that will not clear is not a reason to fail a migration that already succeeded; it is
     * a reason to tell whoever is running it what is left to do by hand.
     */
    private function refreshSettingsCache(): void
    {
        try {
            config(OSPOS::class)->update_settings();
        } catch (Throwable $e) {
            CLI::write('  ! AddOrderTicketsConfigKeys: could not refresh the settings cache (' . $e->getMessage() . '). Clear it by hand for this tenant.');
            log_message('warning', 'AddOrderTicketsConfigKeys: settings cache not refreshed: ' . $e->getMessage());
        }
    }
}
