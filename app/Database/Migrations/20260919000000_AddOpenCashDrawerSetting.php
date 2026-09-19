<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;
use Config\OSPOS;
use Throwable;

/**
 * Seeds the setting that opens the cash drawer when a sale is completed.
 *
 * The drawer hangs off the receipt printer by RJ11, so opening it means sending the printer a
 * control sequence -- ESC p, five bytes. That is NOT a print: no paper comes out. Until now the
 * only way to open it was to print something, which is why the shop was burning a receipt per sale
 * just to reach the cash.
 *
 * Shipped OFF. A business without a drawer must not notice this exists, and one that sells mostly
 * by delivery has no reason to pop a drawer at all.
 */
class Migration_AddOpenCashDrawerSetting extends Migration
{
    private const TABLE = 'app_config';
    private const KEY = 'open_cash_drawer_on_sale';

    public function up(): void
    {
        if ($this->exists()) {
            CLI::write('AddOpenCashDrawerSetting: already present; left alone.');

            return;
        }

        $this->db->table(self::TABLE)->insert(['key' => self::KEY, 'value' => '0']);
        CLI::write('AddOpenCashDrawerSetting: seeded OFF (no change in behaviour).');

        $this->refreshSettingsCache();
    }

    /**
     * Only the untouched default is removed. A shop that turned the drawer on made a statement
     * about its own counter, and rolling the code back is no reason to forget it.
     */
    public function down(): void
    {
        $this->db->table(self::TABLE)->where('key', self::KEY)->where('value', '0')->delete();
        $this->refreshSettingsCache();
    }

    private function exists(): bool
    {
        return $this->db->table(self::TABLE)->where('key', self::KEY)->countAllResults() > 0;
    }

    private function refreshSettingsCache(): void
    {
        try {
            config(OSPOS::class)->update_settings();
        } catch (Throwable $e) {
            CLI::write('AddOpenCashDrawerSetting: could not refresh the settings cache: ' . $e->getMessage());
        }
    }
}
