<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;
use Config\OSPOS;
use Throwable;

/**
 * The outbox of the customer's presale e-mails, and the three settings that govern them
 * (docs/Tecnico/preventas-cartera-y-avisos.md §4.2 and §4.3).
 *
 * WHY AN OUTBOX AND NOT A SEND INSIDE THE REQUEST
 *
 * Apache with mod_php cannot answer the till and keep working: an SMTP server that takes its ten
 * seconds to time out would freeze the cashier for ten seconds (K3). The till writes a row here, in
 * the same transaction as the fact it reports (K7), and `php spark presales:notify`, run every minute
 * by the host's cron, does the sending.
 *
 * WHY UNIQUE (presale_id, kind, ref_id)
 *
 * It is what makes a reminder impossible to send twice, however many times the command runs or
 * overlaps (K6). "Resend" reuses the same row instead of adding one.
 *
 * Same conventions as 20261007000000_AddPresales: no foreign keys, DATETIME, status as a text code.
 *
 * THE SETTINGS, SEEDED OFF
 *
 *   presales_notify_email          the switch. Off in every business, like the whole module.
 *   presales_reminder_days_before  days before an instalment falls due to remind (S1). 0 = never.
 *   presales_reminder_late         '1' = one reminder the day after an instalment is left unpaid.
 */
class Migration_AddPresaleNotifications extends Migration
{
    public const TABLE    = 'presale_notifications';
    public const DEFAULTS = [
        'presales_notify_email'         => '0',
        'presales_reminder_days_before' => '2',
        'presales_reminder_late'        => '1',
    ];

    /**
     * Every column but the primary key, so Presale_notification::$allowedFields can be compared with
     * it: CodeIgniter drops a field missing from $allowedFields without a word.
     */
    public const WRITABLE_COLUMNS = [
        'presale_id',
        'kind',
        'ref_id',
        'to_email',
        'status',
        'attempts',
        'last_error',
        'next_attempt_at',
        'created_at',
        'sent_at',
        'employee_id',
    ];

    public function up(): void
    {
        // Before tableExists(): the driver answers from a schema list built when the process started.
        $this->db->resetDataCache();

        if ($this->db->tableExists(self::TABLE)) {
            $this->report('AddPresaleNotifications: ' . self::TABLE . ' already exists, nothing to do.');
        } else {
            $this->forge->addField([
                'notification_id' => ['type' => 'INT', 'constraint' => 10, 'null' => false, 'auto_increment' => true],
                'presale_id'      => ['type' => 'INT', 'constraint' => 10, 'null' => false],
                // created | payment | canceled | delivered | reminder_before | reminder_late
                'kind' => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => false],
                // payment_id, sale_id or installment_id; 0 for created and canceled.
                'ref_id'   => ['type' => 'INT', 'constraint' => 10, 'null' => false, 'default' => 0],
                'to_email' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                // pending | sending | sent | failed | skipped_no_email | skipped_no_transport
                'status'          => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => false],
                'attempts'        => ['type' => 'TINYINT', 'constraint' => 3, 'null' => false, 'default' => 0],
                'last_error'      => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'next_attempt_at' => ['type' => 'DATETIME', 'null' => false],
                'created_at'      => ['type' => 'DATETIME', 'null' => false],
                'sent_at'         => ['type' => 'DATETIME', 'null' => true],
                // Who caused it or asked for the resend; NULL for the reminders.
                'employee_id' => ['type' => 'INT', 'constraint' => 10, 'null' => true],
            ]);

            $this->forge->addKey('notification_id', true);
            $this->forge->addUniqueKey(['presale_id', 'kind', 'ref_id'], 'idx_presale_kind_ref');
            $this->forge->addKey(['status', 'next_attempt_at'], false, false, 'idx_status_next');

            $this->forge->createTable(self::TABLE, true);

            $this->report('AddPresaleNotifications: created ' . $this->db->prefixTable(self::TABLE) . '.');
        }

        $seeded = 0;

        foreach (self::DEFAULTS as $key => $value) {
            // Never overwrite a value a business has already set.
            if ($this->db->table('app_config')->where('key', $key)->countAllResults() > 0) {
                continue;
            }

            $this->db->table('app_config')->insert(['key' => $key, 'value' => $value]);
            $seeded++;
        }

        $this->report('AddPresaleNotifications: ' . $seeded . ' of ' . count(self::DEFAULTS) . ' setting(s) seeded; existing values left alone.');

        $this->refreshSettingsCache();
    }

    /**
     * Drops the outbox and only the untouched defaults: a switch somebody turned on is data.
     */
    public function down(): void
    {
        $this->forge->dropTable(self::TABLE, true);

        foreach (self::DEFAULTS as $key => $value) {
            $this->db->table('app_config')->where('key', $key)->where('value', $value)->delete();
        }

        $this->refreshSettingsCache();
    }

    /**
     * Same reach as AddPresalesConfigKeys::refreshSettingsCache(): in CLI the tenant is never resolved,
     * and every container recreation starts with an empty cache anyway. Code reads every key with
     * `?? default`.
     */
    private function refreshSettingsCache(): void
    {
        try {
            config(OSPOS::class)->update_settings();
        } catch (Throwable $e) {
            $this->report('  ! AddPresaleNotifications: could not refresh the settings cache (' . $e->getMessage() . ').');
        }
    }

    /**
     * Migrations are also run from the web installer, where CLI output has nowhere to go.
     */
    private function report(string $message): void
    {
        if (is_cli()) {
            CLI::write($message);
        }
    }
}
