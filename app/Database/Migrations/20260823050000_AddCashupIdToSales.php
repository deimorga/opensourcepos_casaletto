<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;

/**
 * Ties every sale to the cash-up shift it belongs to, so the drawer can be reconciled.
 *
 * Until now nothing linked ospos_sales to ospos_cash_up, and the shift a sale belonged to could
 * only be guessed by comparing sale_time against the shift's open_date/close_date. That guess does
 * not work here: the shifts overlap. Shift 31 opened on the 13th and did not close until 16:21 on
 * the 14th, while shift 32 opened at 13:08 on the 14th, three hours before. Three more shifts run
 * past midnight and 31/07 holds two of them, so "the shift is whichever one covers that day" is
 * wrong as well.
 *
 * From here on Sale::save_value() stamps the sale with the shift that is open at the moment it is
 * completed, and the stamp never moves again -- not even if someone edits sale_time afterwards.
 *
 * This migration adds the column and fills in the sales that were recorded before the stamp
 * existed. Where a sale falls inside exactly one shift window it is assigned. Where it falls inside
 * more than one, or inside none, the column is left null and the row is reported: an invented
 * attribution would be indistinguishable from a real one afterwards, and this data decides how much
 * cash a shift is supposed to have taken.
 *
 * The backfill runs once. Once any sale carries a stamp, a null means "no shift was open when this
 * sale was completed", which is a real answer the reports show as a sale with no shift -- not a gap
 * for a later run to fill in from dates that may have been edited since.
 *
 * See docs/Tecnico/cuadre-de-caja-y-origen-del-efectivo.md section 5.
 */
class Migration_AddCashupIdToSales extends Migration
{
    private const INDEX_NAME = 'idx_sales_cashup_id';

    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        if (!$this->db->fieldExists('cashup_id', 'sales')) {
            $this->forge->addColumn('sales', [
                'cashup_id' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'null'       => true,
                ],
            ]);

            CLI::write('AddCashupIdToSales: sales.cashup_id added.');
        }

        if (!$this->indexExists(self::INDEX_NAME)) {
            $this->db->query('CREATE INDEX ' . self::INDEX_NAME . ' ON ' . $this->db->prefixTable('sales') . ' (cashup_id)');

            CLI::write('AddCashupIdToSales: ' . self::INDEX_NAME . ' created.');
        }

        $stamped = $this->db->table('sales')->where('cashup_id IS NOT NULL')->countAllResults();

        if ($stamped > 0) {
            CLI::write("AddCashupIdToSales: $stamped sale(s) already carry a shift, so the backfill has already run. Leaving the column alone -- a null now means no shift was open, which is an answer and not a gap.");

            return;
        }

        $this->backfill();
    }

    /**
     * Revert a migration step.
     */
    public function down(): void
    {
        if ($this->indexExists(self::INDEX_NAME)) {
            $this->db->query('DROP INDEX ' . self::INDEX_NAME . ' ON ' . $this->db->prefixTable('sales'));
        }

        if ($this->db->fieldExists('cashup_id', 'sales')) {
            $this->forge->dropColumn('sales', 'cashup_id');
        }

        CLI::write('AddCashupIdToSales: sales.cashup_id dropped. Every stamp goes with it. Running up() again recomputes the backfill from the shift windows, which cannot bring back the stamps that were written at sale time.');
    }

    /**
     * Turns the recorded shifts into the time windows the backfill matches sales against.
     *
     * Cashups::postSave() writes close_date = open_date when a shift is opened, so a shift that
     * never closed carries a placeholder rather than a null. Only the shift that is genuinely still
     * running -- the most recently opened one still marked open -- gets an open-ended window for
     * that reason. Any other shift with no close recorded after its open_date is a data problem, not
     * a window: giving it an open-ended one would drag every later sale into it and turn the whole
     * history ambiguous, so it is reported and left out of the matching instead.
     *
     * @param array<int, array<string, mixed>> $shifts rows of cash_up: cashup_id, status, open_date, close_date
     *
     * @return array{0: array<int, array{start: int, end: int|null}>, 1: array<int, string>} [windows by cashup_id, reason by unusable cashup_id]
     */
    public static function build_windows(array $shifts): array
    {
        $live_id  = self::live_shift_id($shifts);
        $windows  = [];
        $unusable = [];

        foreach ($shifts as $shift) {
            $cashup_id = (int) $shift['cashup_id'];
            $start     = self::to_timestamp($shift['open_date'] ?? null);

            if ($start === null) {
                $unusable[$cashup_id] = 'it has no open_date';

                continue;
            }

            $end = self::to_timestamp($shift['close_date'] ?? null);

            if ($end === null || $end <= $start) {
                if ($cashup_id === $live_id) {
                    $windows[$cashup_id] = ['start' => $start, 'end' => null];
                } else {
                    $status = (string) ($shift['status'] ?? '');
                    $unusable[$cashup_id] = "it is marked '$status' and never recorded a close after its open_date";
                }

                continue;
            }

            $windows[$cashup_id] = ['start' => $start, 'end' => $end];
        }

        return [$windows, $unusable];
    }

    /**
     * Every shift whose window contains the given moment. Both ends are inclusive: a sale rung up on
     * the second a shift closed belongs to that shift, and if that same second opened another one
     * the sale is ambiguous, which is exactly what the caller needs to be told.
     *
     * @param array<int, array{start: int, end: int|null}> $windows
     *
     * @return int[] cashup ids, in window order
     */
    public static function shifts_covering(string $sale_time, array $windows): array
    {
        $moment = self::to_timestamp($sale_time);

        if ($moment === null) {
            return [];
        }

        $covering = [];

        foreach ($windows as $cashup_id => $window) {
            if ($moment < $window['start']) {
                continue;
            }

            if ($window['end'] !== null && $moment > $window['end']) {
                continue;
            }

            $covering[] = (int) $cashup_id;
        }

        return $covering;
    }

    /**
     * Assigns what can be assigned and names what cannot.
     */
    private function backfill(): void
    {
        $shifts = $this->db->table('cash_up')
            ->select('cashup_id, status, open_date, close_date')
            ->where('deleted', 0)
            ->orderBy('open_date', 'asc')
            ->get()
            ->getResultArray();

        [$windows, $unusable] = self::build_windows($shifts);

        CLI::write('AddCashupIdToSales: ' . count($windows) . ' usable window(s) out of ' . count($shifts) . ' non-deleted shift(s).');

        foreach ($unusable as $cashup_id => $reason) {
            CLI::write("  ! shift $cashup_id contributes no window: $reason");
        }

        $sales = $this->db->table('sales')
            ->select('sale_id, sale_time')
            ->where('cashup_id IS NULL')
            ->orderBy('sale_time', 'asc')
            ->get()
            ->getResultArray();

        $assigned  = 0;
        $ambiguous = [];
        $orphans   = [];

        foreach ($sales as $sale) {
            $covering = self::shifts_covering((string) $sale['sale_time'], $windows);

            if (count($covering) === 1) {
                $this->db->table('sales')
                    ->where('sale_id', $sale['sale_id'])
                    ->update(['cashup_id' => $covering[0]]);

                $assigned++;

                continue;
            }

            if ($covering === []) {
                $orphans[] = $sale;
            } else {
                $sale['candidates'] = $covering;
                $ambiguous[]        = $sale;
            }
        }

        $total = count($sales);

        CLI::write("AddCashupIdToSales: $total sale(s) examined, $assigned assigned, " . count($ambiguous) . ' left null as ambiguous, ' . count($orphans) . ' left null with no shift covering them.');

        $this->reportAmbiguous($ambiguous);
        $this->reportOrphans($orphans);

        if ($ambiguous !== [] || $orphans !== []) {
            // Critical so it survives the production log threshold of 4, which throws away info and
            // warning. A sale with no shift is money nobody can attribute to a drawer.
            log_message('critical', 'AddCashupIdToSales: ' . count($ambiguous) . ' sale(s) fall inside more than one shift window and ' . count($orphans) . ' fall inside none. All were left null on purpose -- assign them by hand before trusting the cash-up totals.');
        }
    }

    /**
     * @param array<int, array<string, mixed>> $ambiguous
     */
    private function reportAmbiguous(array $ambiguous): void
    {
        if ($ambiguous === []) {
            return;
        }

        CLI::write('  Ambiguous sales -- each one falls inside every shift listed, so none of them can be the answer:');

        foreach ($ambiguous as $sale) {
            CLI::write("  ! sale {$sale['sale_id']} at {$sale['sale_time']} sits in shifts " . implode(', ', $sale['candidates']));
        }
    }

    /**
     * Grouped by day: a sale outside every window usually means the shift for that whole day was
     * never opened or never closed, and the day is the useful unit to go and look at.
     *
     * @param array<int, array<string, mixed>> $orphans
     */
    private function reportOrphans(array $orphans): void
    {
        if ($orphans === []) {
            return;
        }

        $by_day = [];

        foreach ($orphans as $sale) {
            $day            = substr((string) $sale['sale_time'], 0, 10);
            $by_day[$day][] = (int) $sale['sale_id'];
        }

        CLI::write('  Sales no shift window covers, by day:');

        foreach ($by_day as $day => $sale_ids) {
            $shown = implode(', ', array_slice($sale_ids, 0, 15));
            $more  = count($sale_ids) > 15 ? ' and ' . (count($sale_ids) - 15) . ' more' : '';

            CLI::write("  ! $day: " . count($sale_ids) . " sale(s) -- $shown$more");
        }
    }

    /**
     * The most recently opened shift that is still marked open, which is the only one that can be
     * running right now. Ties break on the higher id, the same way Sale::save_value() picks one.
     *
     * @param array<int, array<string, mixed>> $shifts
     */
    private static function live_shift_id(array $shifts): ?int
    {
        $live_id = null;
        $latest  = null;

        foreach ($shifts as $shift) {
            if (($shift['status'] ?? '') !== 'open') {
                continue;
            }

            $start = self::to_timestamp($shift['open_date'] ?? null);

            if ($start === null) {
                continue;
            }

            $cashup_id = (int) $shift['cashup_id'];

            if ($latest === null || $start > $latest || ($start === $latest && $cashup_id > $live_id)) {
                $latest  = $start;
                $live_id = $cashup_id;
            }
        }

        return $live_id;
    }

    /**
     * Null for anything that is not a real moment, including MySQL's all-zero timestamp.
     */
    private static function to_timestamp(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * getIndexData() rather than a SHOW INDEX query so the check works the same on every driver the
     * install supports.
     */
    private function indexExists(string $index_name): bool
    {
        foreach ($this->db->getIndexData('sales') as $index) {
            if ($index->name === $index_name) {
                return true;
            }
        }

        return false;
    }
}
