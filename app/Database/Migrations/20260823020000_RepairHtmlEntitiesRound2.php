<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;

/**
 * Extends the entity repair to the columns the first pass missed.
 *
 * 20260823000000_RepairHtmlEntities covered sales_payments.payment_type, expenses.payment_type and
 * items.description. It did not cover the places those values get copied to or the ones written by
 * controllers outside the payment flow. In production that left 1297 rows of
 * sales_items.description still reading "Unidad: n&uacute;mero de unidades internacionales" -- the
 * item description is copied onto each sale line when the sale is completed, so repairing the item
 * did not repair the seventeen products' worth of history already recorded against it.
 *
 * The entity map is repeated here rather than shared with the first migration on purpose: a
 * migration is a historical record of what ran, and it should not change behaviour because a
 * constant somewhere else was edited later.
 *
 * See docs/Tecnico/errores-produccion-upstream.md section 5.
 */
class Migration_RepairHtmlEntitiesRound2 extends Migration
{
    private const BACKUP_TABLE = 'html_entity_repair_backup';

    /** table => [primary key, column] */
    private const TARGETS = [
        'sales_items'      => ['sale_id', 'description'],
        'receivings'       => ['receiving_id', 'payment_type'],
        'receivings_items' => ['receiving_id', 'description'],
    ];

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
        if (!$this->db->tableExists(self::BACKUP_TABLE)) {
            CLI::write('RepairHtmlEntitiesRound2: the backup table is missing, so the first repair never ran here. Nothing to do.');

            return;
        }

        $repaired = 0;
        $residual = 0;

        foreach (self::TARGETS as $table => [$primaryKey, $column]) {
            if (!$this->db->tableExists($table)) {
                continue;
            }

            [$tableRepaired, $tableResidual] = $this->repairColumn($table, $primaryKey, $column);

            $repaired += $tableRepaired;
            $residual += $tableResidual;

            CLI::write("  $table.$column: $tableRepaired repaired, $tableResidual unresolved");
        }

        CLI::write("RepairHtmlEntitiesRound2: $repaired row(s) repaired, $residual unresolved.");

        if ($residual > 0) {
            $message = "RepairHtmlEntitiesRound2: $residual row(s) still hold an unrecognised '&...;' sequence. Left untouched on purpose -- review them.";
            CLI::write('  ! ' . $message);
            log_message('critical', $message);
        }
    }

    /**
     * Revert a migration step.
     */
    public function down(): void
    {
        if (!$this->db->tableExists(self::BACKUP_TABLE)) {
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

            $this->db->table(self::BACKUP_TABLE)
                ->where('source_table', $table)
                ->where('source_column', $column)
                ->delete();
        }

        CLI::write("RepairHtmlEntitiesRound2: $restored row(s) restored.");
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

                // sales_items is keyed by (sale_id, line), so the update is scoped by the whole
                // stored value as well. Rewriting every line of a sale that shares the same broken
                // description is correct -- they are all the same wrong string.
                $this->db->table($table)
                    ->where($primaryKey, $candidate[$primaryKey])
                    ->where($column, $original)
                    ->update([$column => $decoded]);

                $repaired++;
            }

            if (preg_match('/&[A-Za-z][A-Za-z0-9]{1,8};|&#[0-9]{1,5};/', $decoded) === 1) {
                CLI::write("  ! $table.$primaryKey={$candidate[$primaryKey]} holds an unrecognised entity: $decoded");
                $residual++;
            }
        }

        return [$repaired, $residual];
    }
}
