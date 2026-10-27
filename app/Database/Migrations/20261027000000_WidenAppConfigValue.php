<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;

/**
 * app_config.value goes from VARCHAR(500) to TEXT.
 *
 * Found certifying the presale e-mails on 2026-10-27: the presale conditions (presales_terms) were cut
 * at character 500 on every receipt, mid-word ("…con un peso aproximado. E"). The configuration screen
 * accepts up to 4,000 characters and the suggested wording has about 1,050, but the column held 500,
 * and the application's connection does not run in strict mode, so MariaDB truncated without a word.
 * Every business that saved the suggested conditions has them cut since the first presale.
 *
 * Widening the column does not bring back what was cut: the business saves its conditions again
 * (Configuración → Preventas, "Usar texto sugerido"). See docs/Tecnico/venta-anticipada.md §5.
 *
 * TEXT and not a longer VARCHAR: app_config holds free text written by people (conditions, footers,
 * messages), and a VARCHAR long enough for one of them would put the row near InnoDB's limit for no
 * gain. The column is never indexed or searched.
 */
class Migration_WidenAppConfigValue extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE ' . $this->db->prefixTable('app_config') . ' MODIFY `value` TEXT NOT NULL');

        if (is_cli()) {
            CLI::write('WidenAppConfigValue: app_config.value is TEXT.');
        }
    }

    /**
     * Back to VARCHAR(500) only if nothing longer is stored: going down must not cut anybody's text.
     */
    public function down(): void
    {
        $longer = $this->db->table('app_config')->where('CHAR_LENGTH(`value`) >', 500, false)->countAllResults();

        if ($longer > 0) {
            if (is_cli()) {
                CLI::write("WidenAppConfigValue: {$longer} value(s) longer than 500 characters; app_config.value stays TEXT.");
            }

            return;
        }

        $this->db->query('ALTER TABLE ' . $this->db->prefixTable('app_config') . ' MODIFY `value` VARCHAR(500) NOT NULL');
    }
}
