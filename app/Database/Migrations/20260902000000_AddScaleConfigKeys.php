<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;
use Config\OSPOS;
use Throwable;

/**
 * Seeds the four settings that describe a scale, with defaults that read no scale at all.
 *
 * The interpreter that uses them is configuration-driven on purpose: the client's scale is
 * multi-protocol, the format it is speaking is still unknown, and the manufacturer's support closed
 * on 2026-08-28. What the next client's scale emits is going to be a screen to fill in.
 *
 * Nothing here touches existing rows. The shipped state is scale_format = '', and an empty format
 * is what Token_lib::parse_scale() reads as "there is no scale on this till" -- so a tenant that
 * migrates and never opens the new screen is in exactly the state it was in before.
 *
 * The keys are seeded rather than left absent so the configuration screen has something to show and
 * an operator can see the defaults. Code still reads every one of them with ?? regardless: the
 * settings map is cached (see below) and a tenant can be running the new code against a cache that
 * predates this migration for as long as that entry lives.
 *
 *   scale_format     Pattern with a {W:n} token, e.g. 'N{W:6}' or 'ST,GS,\+\s+{W:5}kg'
 *   scale_divisor    1 when the frame is in kilograms, 1000 when it is in grams
 *   scale_port       Identifier of the port the local program has to open
 *   scale_transport  'keys' | 'agent'
 *
 * See docs/Tecnico/venta-por-peso-y-hardware-de-caja.md sections 4.3, 5.10b and 7c.3.
 */
class Migration_AddScaleConfigKeys extends Migration
{
    private const TABLE = 'app_config';

    /**
     * Defaults chosen so that the scale is off: no pattern, no port, and the transport that needs
     * no program installed on the till.
     */
    private const DEFAULTS = [
        'scale_format'    => '',
        'scale_divisor'   => '1',
        'scale_port'      => '',
        'scale_transport' => 'keys'
    ];

    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        $seeded = 0;

        foreach (self::DEFAULTS as $key => $value) {
            // Never overwrite: a tenant may already have been configured by hand, and re-running a
            // migration must not undo that.
            if ($this->exists($key)) {
                continue;
            }

            $this->db->table(self::TABLE)->insert(['key' => $key, 'value' => $value]);
            $seeded++;
        }

        CLI::write('AddScaleConfigKeys: ' . $seeded . ' of ' . count(self::DEFAULTS) . ' scale setting(s) seeded; existing values left alone.');

        $this->refreshSettingsCache();
    }

    /**
     * Revert a migration step.
     *
     * Only the untouched defaults are removed. A pattern somebody worked out standing in front of a
     * till is data, not schema, and rolling the code back is no reason to throw it away -- it will
     * still be there, and still correct, when the code rolls forward again.
     */
    public function down(): void
    {
        $removed = 0;
        $kept = 0;

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

        CLI::write('AddScaleConfigKeys: ' . $removed . ' default(s) removed, ' . $kept . ' configured value(s) kept.');

        $this->refreshSettingsCache();
    }

    private function exists(string $key): bool
    {
        return $this->db->table(self::TABLE)->where('key', $key)->countAllResults() > 0;
    }

    /**
     * Config\OSPOS caches the whole settings map per tenant and a migration does not invalidate it,
     * so without this the new keys stay invisible until the entry expires (section 7c.3).
     *
     * A cache that will not clear is not a reason to fail a migration that already succeeded; it is
     * a reason to tell whoever is running it what is left to do by hand.
     */
    /**
     * Refresh the cached settings map so the new keys are visible without waiting for the entry to
     * expire.
     *
     * Honest about its reach: OSPOS::settingsCacheKey() suffixes the key with the tenant slug only
     * when TenantContext is resolved, and TenantContext is populated exclusively by the HTTP filter
     * (app/Filters/TenantResolver.php). A migration runs in a CLI process, where it is never
     * resolved, so this clears the plain `settings` key.
     *
     * That is correct for a single-tenant install and a no-op for a tenant schema -- deliberately
     * left as a no-op rather than made tenant-aware, because on the deploy path it is not needed:
     * the cache is a file handler under writable/cache, which is not a mounted volume, so every
     * container recreation starts with it empty. See docs/Tecnico/venta-por-peso-y-hardware-de-caja.md
     * section 7c.3. The day the cache moves to Redis this has to be revisited.
     */
    private function refreshSettingsCache(): void
    {
        try {
            config(OSPOS::class)->update_settings();
        } catch (Throwable $e) {
            CLI::write('  ! AddScaleConfigKeys: could not refresh the settings cache (' . $e->getMessage() . '). Clear it by hand for this tenant.');
            log_message('warning', 'AddScaleConfigKeys: settings cache not refreshed: ' . $e->getMessage());
        }
    }
}
