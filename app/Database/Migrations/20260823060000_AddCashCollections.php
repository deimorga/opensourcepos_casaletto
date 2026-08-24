<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Creates the register of cash taken out of the drawer to the bank or to a reserve.
 *
 * A collection is not an expense: the money was not spent, it moved between pockets. It therefore
 * never belongs in any expense total nor in the income-vs-expenses report. Its only effect is on
 * the cash expected in the drawer:
 *
 *     expected = opening + cash sales - expenses paid from the drawer - collections
 *
 * Until now the movement was recorded nowhere, so the drawer simply closed short and nobody could
 * say why: 11 of the 40 shifts in production differ by more than $10.000, adding up to -$2.460.845.
 *
 * collected_at is the real time the money left, not the time somebody typed it in. That is what
 * lets a collection land in the shift it actually happened in even when it is entered hours later.
 *
 * The table deliberately carries no cashup_id. A collection belongs to whatever shift was open at
 * collected_at, and that shift is resolved from the time. Storing the shift as well would record
 * the same fact twice and leave room for the two copies to disagree.
 *
 * See docs/Tecnico/cuadre-de-caja-y-origen-del-efectivo.md section 4.
 */
class Migration_AddCashCollections extends Migration
{
    public const TABLE = 'cash_collections';

    /**
     * Every column of the table except the auto-increment primary key.
     *
     * Exposed so the model's $allowedFields can be checked against it: CodeIgniter drops any field
     * missing from $allowedFields without raising anything, and this project has already lost data
     * to that twice.
     */
    public const WRITABLE_COLUMNS = [
        'amount',
        'collected_at',
        'collected_by',
        'registered_by',
        'note',
        'deleted',
    ];

    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        if ($this->db->tableExists(self::TABLE)) {
            $this->report('AddCashCollections: ' . self::TABLE . ' already exists, nothing to do.');

            return;
        }

        $this->forge->addField([
            'collection_id' => [
                'type'           => 'INT',
                'constraint'     => 10,
                'null'           => false,
                'auto_increment' => true,
            ],
            'amount' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
                'null'       => false,
            ],
            // The explicit default is what keeps this column trustworthy. Where
            // explicit_defaults_for_timestamp is off -- still the case on older MySQL and MariaDB
            // servers -- the first TIMESTAMP column of a table, declared with neither a default nor
            // an ON UPDATE clause, silently gets "DEFAULT CURRENT_TIMESTAMP ON UPDATE
            // CURRENT_TIMESTAMP". Editing a note would then rewrite the time the money left, which
            // is the single value the whole shift attribution rests on. Naming a default suppresses
            // that rule.
            'collected_at' => [
                'type'    => 'TIMESTAMP',
                'null'    => false,
                'default' => new RawSql('CURRENT_TIMESTAMP'),
            ],
            // Who walked away with the money, and who wrote the movement down. They are different
            // people on purpose: a cashier records that an administrator took the cash.
            'collected_by' => [
                'type'       => 'INT',
                'constraint' => 10,
                'null'       => false,
            ],
            'registered_by' => [
                'type'       => 'INT',
                'constraint' => 10,
                'null'       => false,
            ],
            'note' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
                'default'    => '',
            ],
            'deleted' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 0,
            ],
        ]);

        $this->forge->addKey('collection_id', true);

        // Every read is "what was collected inside this shift's window, ignoring what was undone",
        // so those are the two columns worth indexing.
        $this->forge->addKey('collected_at', false, false, 'idx_collected_at');
        $this->forge->addKey('deleted', false, false, 'idx_deleted');

        $this->forge->createTable(self::TABLE, true);

        $this->report('AddCashCollections: created ' . $this->db->prefixTable(self::TABLE) . ' with indexes on collected_at and deleted.');
    }

    /**
     * Revert a migration step.
     */
    public function down(): void
    {
        $this->forge->dropTable(self::TABLE, true);
    }

    /**
     * Migrations are also run from the web installer, where CLI output has nowhere to go.
     *
     * Reporting goes through CLI::write and not log_message on purpose: the production log
     * threshold is 4, which throws away anything below "critical", and this is progress rather
     * than an error.
     */
    private function report(string $message): void
    {
        if (is_cli()) {
            CLI::write($message);
        }
    }
}
