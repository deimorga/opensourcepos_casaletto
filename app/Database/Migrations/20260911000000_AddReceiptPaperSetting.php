<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;
use Config\OSPOS;
use Throwable;

/**
 * Seeds the setting that says what paper the receipt is printed on.
 *
 * Until now nothing in this application said anything about paper. There is not a single @page rule
 * in the project, so the page size came entirely from the printer driver and the browser added its
 * own default margins on top. On a 58 mm roll -- 48 mm of it actually printable -- those margins eat
 * more than half the usable width, and the receipt comes out squeezed into a column.
 *
 * The shipped value is the empty string, which means "whatever the system says" and is exactly the
 * behaviour every tenant has today. A business only gets the roll layout when somebody chooses it,
 * so no existing till changes on the day this migrates.
 *
 * The value describes the BUSINESS's paper, not the till's hardware -- which is why it belongs here
 * and not in the local program's configuration, unlike the COM port of the scale.
 */
class Migration_AddReceiptPaperSetting extends Migration
{
    private const TABLE = 'app_config';
    private const KEY = 'receipt_paper';

    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        if ($this->exists()) {
            CLI::write('AddReceiptPaperSetting: already present; left alone.');

            return;
        }

        $this->db->table(self::TABLE)->insert(['key' => self::KEY, 'value' => '']);
        CLI::write('AddReceiptPaperSetting: seeded with the system default (no change in behaviour).');

        $this->refreshSettingsCache();
    }

    /**
     * Revert a migration step.
     *
     * Only the untouched default is removed. A shop that picked its paper size made a statement
     * about its own hardware, and rolling the code back is no reason to forget it.
     */
    public function down(): void
    {
        $this->db->table(self::TABLE)
            ->where('key', self::KEY)
            ->where('value', '')
            ->delete();

        $this->refreshSettingsCache();
    }

    private function exists(): bool
    {
        return $this->db->table(self::TABLE)->where('key', self::KEY)->countAllResults() > 0;
    }

    /**
     * The settings map is cached, so a tenant can keep serving the old map after this runs.
     */
    private function refreshSettingsCache(): void
    {
        try {
            config(OSPOS::class)->update_settings();
        } catch (Throwable $e) {
            CLI::write('AddReceiptPaperSetting: could not refresh the settings cache: ' . $e->getMessage());
        }
    }
}
