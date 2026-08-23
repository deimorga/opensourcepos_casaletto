<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;

/**
 * Adds a stable, language-independent code alongside the translated payment label.
 *
 * Filters used to compare the stored label against lang(), which is why the expenses "A Crédito"
 * filter never matched a single row: expenses save the label from the Sales.* keys and filter
 * against the Expenses.* keys, and in es-MX those two say "Adeudo" and "A Crédito". The code
 * removes the comparison from the equation entirely.
 *
 * payment_type is kept as it is. It holds the human label and, for gift cards, the card number
 * appended after a colon, which the receipt views split on.
 *
 * See app/Helpers/payment_type_helper.php and
 * docs/Tecnico/errores-produccion-upstream.md section 5.
 */
class Migration_AddPaymentTypeCode extends Migration
{
    private const TARGETS = [
        'sales_payments' => 'payment_id',
        'expenses'       => 'expense_id',
    ];

    /**
     * Language keys that have ever held a payment label, as code => key suffix.
     */
    private const CODE_KEYS = [
        'cash'            => 'cash',
        'debit'           => 'debit',
        'credit'          => 'credit',
        'due'             => 'due',
        'check'           => 'check',
        'bank_transfer'   => 'bank_transfer',
        'wallet'          => 'wallet',
        'upi'             => 'upi',
        'giftcard'        => 'giftcard',
        'rewards'         => 'rewards',
        'cash_adjustment' => 'cash_adjustment',
        'cash_deposit'    => 'cash_deposit',
        'credit_deposit'  => 'credit_deposit',
    ];

    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        foreach (array_keys(self::TARGETS) as $table) {
            if (!$this->db->fieldExists('payment_type_code', $table)) {
                $this->forge->addColumn($table, [
                    'payment_type_code' => [
                        'type'       => 'VARCHAR',
                        'constraint' => 20,
                        'null'       => true,
                        'after'      => 'payment_type',
                    ],
                ]);
                $this->db->query('CREATE INDEX idx_payment_type_code ON ' . $this->db->prefixTable($table) . ' (payment_type_code)');
            }
        }

        $labelMap = $this->buildLabelMap();
        CLI::write('AddPaymentTypeCode: ' . count($labelMap) . ' distinct labels known across all installed languages.');

        foreach (self::TARGETS as $table => $primaryKey) {
            [$mapped, $unmapped] = $this->backfill($table, $primaryKey, $labelMap);

            CLI::write("  $table: $mapped row(s) coded, $unmapped left null.");

            if ($unmapped > 0) {
                $this->reportUnmapped($table);
            }
        }
    }

    /**
     * Revert a migration step.
     */
    public function down(): void
    {
        foreach (array_keys(self::TARGETS) as $table) {
            if ($this->db->fieldExists('payment_type_code', $table)) {
                $this->forge->dropColumn($table, 'payment_type_code');
            }
        }
    }

    /**
     * Builds lowercased label => code from every installed language, not just the active one.
     *
     * The history was written under whatever language was configured at the time, and the same code
     * runs for tenants that may be configured differently. Reading the files directly rather than
     * calling lang() is what makes that possible: lang() only ever answers for the active locale.
     *
     * @return array<string, string>
     */
    private function buildLabelMap(): array
    {
        $map = [];

        foreach (glob(APPPATH . 'Language/*', GLOB_ONLYDIR) as $localeDir) {
            foreach (['Sales.php', 'Expenses.php'] as $file) {
                $path = $localeDir . DIRECTORY_SEPARATOR . $file;

                if (!is_file($path)) {
                    continue;
                }

                $strings = include $path;

                if (!is_array($strings)) {
                    continue;
                }

                foreach (self::CODE_KEYS as $code => $key) {
                    if (!isset($strings[$key]) || !is_string($strings[$key]) || $strings[$key] === '') {
                        continue;
                    }

                    $label = mb_strtolower(trim($strings[$key]));

                    // First writer wins. The order of CODE_KEYS puts the real payment methods
                    // before the derived ones, so a language where two keys happen to share a
                    // wording cannot steal a code from the method that actually uses it.
                    if (!isset($map[$label])) {
                        $map[$label] = $code;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * @return int[] [rows coded, rows left null]
     */
    private function backfill(string $table, string $primaryKey, array $labelMap): array
    {
        $rows = $this->db->table($table)
            ->select("$primaryKey, payment_type")
            ->where('payment_type_code IS NULL')
            ->get()
            ->getResultArray();

        $mapped = 0;
        $unmapped = 0;

        foreach ($rows as $row) {
            $code = $this->resolve((string) $row['payment_type'], $labelMap);

            if ($code === null) {
                $unmapped++;

                continue;
            }

            $this->db->table($table)
                ->where($primaryKey, $row[$primaryKey])
                ->update(['payment_type_code' => $code]);

            $mapped++;
        }

        return [$mapped, $unmapped];
    }

    /**
     * Resolves one stored label, falling back to the part before the colon for gift cards, which
     * are stored as "<label>:<card number>".
     */
    private function resolve(string $label, array $labelMap): ?string
    {
        $needle = mb_strtolower(trim($label));

        if (isset($labelMap[$needle])) {
            return $labelMap[$needle];
        }

        if (str_contains($needle, ':')) {
            $prefix = trim(strstr($needle, ':', true));

            return $labelMap[$prefix] ?? null;
        }

        return null;
    }

    /**
     * Names what could not be resolved, with counts, instead of leaving the operator to guess.
     * Nothing is assigned a default code: an unrecognised value has to be visible.
     */
    private function reportUnmapped(string $table): void
    {
        $rows = $this->db->table($table)
            ->select('payment_type, COUNT(*) AS total')
            ->where('payment_type_code IS NULL')
            ->groupBy('payment_type')
            ->get()
            ->getResultArray();

        foreach ($rows as $row) {
            $message = "AddPaymentTypeCode: $table has {$row['total']} row(s) with an unrecognised payment type: \"{$row['payment_type']}\". Left uncoded on purpose -- review before relying on the filters.";

            CLI::write('  ! ' . $message);

            // Critical so it survives the production log threshold of 4, and only ever fires on
            // data nobody could interpret.
            log_message('critical', $message);
        }
    }
}
