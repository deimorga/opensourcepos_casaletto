<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;

/**
 * Repairs text that was stored with HTML entities instead of the characters themselves.
 *
 * Cause: FILTER_SANITIZE_FULL_SPECIAL_CHARS, which PHP documents as "equivalent to
 * htmlspecialchars() with ENT_QUOTES" but which actually calls php_escape_html_entities_ex()
 * with all=1 -- htmlentities() behaviour. Accented vowels come back as named entities, so a
 * payment type saved as "Tarjeta de débito" is stored as "Tarjeta de d&eacute;bito" and no
 * longer matches the label the grid filters compare against.
 *
 * See docs/Tecnico/errores-produccion-upstream.md section 5.
 *
 * Every original value is copied into a backup table first, so down() restores exactly what
 * was there rather than trying to re-encode (which would also hit rows this never touched).
 */
class Migration_RepairHtmlEntities extends Migration
{
    private const BACKUP_TABLE = 'html_entity_repair_backup';

    /**
     * Columns to repair, as table => [primary key, column].
     * Kept explicit: this migration rewrites money-adjacent data and must never be pointed at
     * a column nobody looked at.
     */
    private const TARGETS = [
        'sales_payments' => ['payment_id', 'payment_type'],
        'expenses'       => ['expense_id', 'payment_type'],
        'items'          => ['item_id', 'description'],
    ];

    /**
     * Named entities for Latin accented characters.
     *
     * Deliberately excludes &amp; &lt; &gt; &quot; and &#039;. Those are the markup-escaping
     * entities: decoding them changes what the value means rather than restoring a character,
     * and no row in production contains them. Anything left holding a "&...;" after this runs
     * is reported instead of guessed at.
     */
    private const ENTITIES = [
        '&aacute;' => 'á', '&eacute;' => 'é', '&iacute;' => 'í', '&oacute;' => 'ó', '&uacute;' => 'ú',
        '&Aacute;' => 'Á', '&Eacute;' => 'É', '&Iacute;' => 'Í', '&Oacute;' => 'Ó', '&Uacute;' => 'Ú',
        '&agrave;' => 'à', '&egrave;' => 'è', '&igrave;' => 'ì', '&ograve;' => 'ò', '&ugrave;' => 'ù',
        '&Agrave;' => 'À', '&Egrave;' => 'È', '&Igrave;' => 'Ì', '&Ograve;' => 'Ò', '&Ugrave;' => 'Ù',
        '&acirc;'  => 'â', '&ecirc;'  => 'ê', '&icirc;'  => 'î', '&ocirc;'  => 'ô', '&ucirc;'  => 'û',
        '&Acirc;'  => 'Â', '&Ecirc;'  => 'Ê', '&Icirc;'  => 'Î', '&Ocirc;'  => 'Ô', '&Ucirc;'  => 'Û',
        '&ntilde;' => 'ñ', '&Ntilde;' => 'Ñ', '&ccedil;' => 'ç', '&Ccedil;' => 'Ç',
        '&uuml;'   => 'ü', '&Uuml;'   => 'Ü', '&iuml;'   => 'ï', '&Iuml;'   => 'Ï',
        '&iquest;' => '¿', '&iexcl;'  => '¡', '&ordm;'   => 'º', '&ordf;'   => 'ª',
        '&deg;'    => '°', '&laquo;'  => '«', '&raquo;'  => '»', '&nbsp;'   => ' ',
    ];

    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        $this->createBackupTable();

        $repaired = 0;
        $residual = 0;

        foreach (self::TARGETS as $table => [$primaryKey, $column]) {
            [$tableRepaired, $tableResidual] = $this->repairColumn($table, $primaryKey, $column);

            $repaired += $tableRepaired;
            $residual += $tableResidual;

            $this->report("  $table.$column: $tableRepaired repaired, $tableResidual unresolved");
        }

        $this->report("RepairHtmlEntities: $repaired row(s) repaired, $residual unresolved.");

        if ($residual > 0) {
            $message = "RepairHtmlEntities: $residual row(s) still hold a '&...;' sequence that is not in the known entity list. They were left untouched on purpose -- review them before treating this repair as complete.";

            $this->report($message);

            // Logged at critical because the log threshold is 4 in production (Config\Logger),
            // which discards info, notice and warning. A repair that could not fully interpret
            // the data it rewrites has to survive that threshold. It fires only when a value
            // was not understood, so it cannot become the routine noise that section 1 of
            // docs/Tecnico/errores-produccion-upstream.md is about.
            log_message('critical', $message);
        }
    }

    /**
     * Revert a migration step.
     */
    public function down(): void
    {
        if (!$this->db->tableExists(self::BACKUP_TABLE)) {
            $this->report('RepairHtmlEntities: nothing to revert, the backup table is gone.');

            return;
        }

        $restored = 0;

        foreach (self::TARGETS as $table => [$primaryKey, $column]) {
            $rows = $this->db->table(self::BACKUP_TABLE)
                ->where('source_table', $table)
                ->where('source_column', $column)
                ->get()
                ->getResultArray();

            foreach ($rows as $row) {
                $this->db->table($table)
                    ->where($primaryKey, $row['row_id'])
                    ->update([$column => $row['old_value']]);

                $restored++;
            }
        }

        $this->report("RepairHtmlEntities: $restored row(s) restored to their stored-with-entities form.");

        $this->forge->dropTable(self::BACKUP_TABLE, true);
    }

    /**
     * Migrations are run from the command line, so the operator standing there is the audience
     * that matters. log_message() would not reach them: Config\Logger sets the threshold to 4 in
     * production, which drops info, notice and warning -- the counts would vanish precisely in the
     * environment where they are worth having.
     */
    private function report(string $message): void
    {
        if (is_cli()) {
            CLI::write($message);
        }
    }

    /**
     * Holds the pre-repair value of every row this migration rewrites, so down() restores the
     * exact original instead of re-encoding and catching rows that were always fine.
     */
    private function createBackupTable(): void
    {
        if ($this->db->tableExists(self::BACKUP_TABLE)) {
            return;
        }

        $this->forge->addField([
            'backup_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'source_table'  => ['type' => 'VARCHAR', 'constraint' => 64],
            'source_column' => ['type' => 'VARCHAR', 'constraint' => 64],
            'row_id'        => ['type' => 'INT', 'constraint' => 11],
            'old_value'     => ['type' => 'TEXT'],
        ]);
        $this->forge->addKey('backup_id', true);
        $this->forge->addKey(['source_table', 'source_column']);
        $this->forge->createTable(self::BACKUP_TABLE);
    }

    /**
     * @return int[] [rows repaired, rows left holding an entity we do not recognise]
     */
    private function repairColumn(string $table, string $primaryKey, string $column): array
    {
        $candidates = $this->db->table($table)
            ->select("$primaryKey, $column")
            ->like($column, '&', 'both')
            ->like($column, ';', 'both')
            ->get()
            ->getResultArray();

        $repaired = 0;
        $residual = 0;

        foreach ($candidates as $candidate) {
            $original = (string) $candidate[$column];
            $decoded  = strtr($original, self::ENTITIES);

            if ($decoded !== $original) {
                $this->db->table(self::BACKUP_TABLE)->insert([
                    'source_table'  => $table,
                    'source_column' => $column,
                    'row_id'        => $candidate[$primaryKey],
                    'old_value'     => $original,
                ]);

                $this->db->table($table)
                    ->where($primaryKey, $candidate[$primaryKey])
                    ->update([$column => $decoded]);

                $repaired++;
            }

            if (preg_match('/&[A-Za-z][A-Za-z0-9]{1,8};|&#[0-9]{1,5};/', $decoded) === 1) {
                $this->report("  ! $table.$primaryKey={$candidate[$primaryKey]} holds an unrecognised entity: $decoded");
                $residual++;
            }
        }

        return [$repaired, $residual];
    }
}
