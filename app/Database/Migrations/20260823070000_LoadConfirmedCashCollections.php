<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;

/**
 * Loads the two cash collections the owner confirmed against his team's chat.
 *
 * They are the two shifts that made the whole problem visible: 35 was short by $1.002.900 and 39 by
 * $800.370, and Rodrigo Tovar had collected $1.000.000 on 17/08 and $800.000 on 21/08. Once these
 * are on record the two shifts fall back to residues of $2.900 and $370, which is the same counting
 * noise as the shifts that already balance.
 *
 * It is a separate migration from the one that creates the table because the two answer to
 * different things. The table belongs in every environment; these two rows are facts about one
 * business, and they only exist where those people and those shifts do. Kept apart, reverting the
 * data does not drop the table, and a fresh database gets the register without two phantom money
 * records sitting in it.
 *
 * Nothing here is pinned to an id. The collector is looked up by name and the shifts by the day
 * they opened, because this code also runs on staging and on other tenants where the ids differ or
 * the people do not exist at all. When any of it cannot be resolved the collection is not loaded
 * and the reason is printed: a money record that had to be guessed at is worse than no record.
 *
 * See docs/Funcional/cuadre-de-caja-y-origen-del-efectivo.md section 6.
 */
class Migration_LoadConfirmedCashCollections extends Migration
{
    private const TABLE = 'cash_collections';

    /**
     * Whoever collected the money, matched by name against the employees of this installation.
     */
    private const COLLECTOR_NAME = 'Rodrigo Tovar';

    /**
     * The confirmed movements, as the day they happened and the amount that left the drawer.
     */
    private const CONFIRMED = [
        ['date' => '2026-08-17', 'amount' => 1000000.00],
        ['date' => '2026-08-21', 'amount' => 800000.00],
    ];

    /**
     * The exact time of day was never recorded, so it is not invented here either: the collection
     * is placed at the midpoint of the shift it belongs to, and the note says so. The midpoint is
     * what makes that safe whatever the shift looks like -- shift 39 lasted 44 seconds, from
     * 21:58:10 to 21:58:54, so any "reasonable" hour of the day would have fallen outside it and
     * landed the collection in the wrong shift, or in none.
     */
    private const NOTE = 'Cargada retroactivamente el 2026-08-23, confirmada con el equipo. La hora exacta se desconoce: se ubica en el punto medio del turno para atribuirla al turno correcto. La anotó una migración, no la persona que figura como quien la registró.';

    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        if (!$this->db->tableExists(self::TABLE)) {
            $this->warn('LoadConfirmedCashCollections: ' . self::TABLE . ' does not exist. Nothing was loaded.');

            return;
        }

        $collector = $this->findCollector();

        if ($collector === null) {
            return;
        }

        $loaded = 0;
        $skipped = 0;

        foreach (self::CONFIRMED as $collection) {
            $shift = $this->shiftWindowOn($collection['date']);

            if ($shift === null) {
                $skipped++;

                continue;
            }

            if ($this->alreadyLoaded($collection['amount'], $shift)) {
                $this->report("  {$collection['date']}: a collection of {$collection['amount']} is already on record inside that shift, left alone.");
                $skipped++;

                continue;
            }

            $this->db->table(self::TABLE)->insert([
                'amount'        => $collection['amount'],
                'collected_at'  => $shift['midpoint'],
                'collected_by'  => $collector,
                // Nobody wrote this movement down at the time, so there is no honest registrar to
                // name. Pointing it at the collector says "this is Rodrigo's collection" and the
                // note says a migration entered it; putting some arbitrary administrator's name on
                // an entry they never made would have been the worse lie.
                'registered_by' => $collector,
                'note'          => self::NOTE,
                'deleted'       => 0,
            ]);

            $this->report("  {$collection['date']}: loaded {$collection['amount']} collected at {$shift['midpoint']} (shift {$shift['cashup_id']}).");
            $loaded++;
        }

        $this->report("LoadConfirmedCashCollections: $loaded collection(s) loaded, $skipped skipped.");
    }

    /**
     * Revert a migration step.
     *
     * The rows are found the same way they were written -- same collector, same amount, same
     * computed midpoint -- rather than by emptying the table. Anything a person has since added or
     * edited is left alone, which is the point: this only undoes its own work.
     */
    public function down(): void
    {
        if (!$this->db->tableExists(self::TABLE)) {
            return;
        }

        $collector = $this->findCollector();

        if ($collector === null) {
            return;
        }

        $removed = 0;

        foreach (self::CONFIRMED as $collection) {
            $shift = $this->shiftWindowOn($collection['date']);

            if ($shift === null) {
                continue;
            }

            $this->db->table(self::TABLE)
                ->where('amount', $collection['amount'])
                ->where('collected_at', $shift['midpoint'])
                ->where('collected_by', $collector)
                ->delete();

            $removed += $this->db->affectedRows();
        }

        $this->report("LoadConfirmedCashCollections: removed $removed loaded collection(s).");
    }

    /**
     * Resolves the collector's person_id by name, or null with a reason printed.
     *
     * The comparison asks that every whitespace-separated part of the name appear in "first last",
     * so a middle name or a compound surname stored on the person still matches. More than one
     * match is treated exactly like no match: two employees who both answer to the name is not
     * something to break the tie on by picking the lower id.
     */
    private function findCollector(): ?int
    {
        $employees = $this->db->table('people')
            ->select('people.person_id, people.first_name, people.last_name')
            ->join('employees', 'employees.person_id = people.person_id')
            ->where('employees.deleted', 0)
            ->get()
            ->getResultArray();

        $needles = preg_split('/\s+/', mb_strtolower(trim(self::COLLECTOR_NAME)));
        $matches = [];

        foreach ($employees as $employee) {
            $name = mb_strtolower(trim($employee['first_name'] . ' ' . $employee['last_name']));

            foreach ($needles as $needle) {
                if (!str_contains($name, $needle)) {
                    continue 2;
                }
            }

            $matches[] = (int)$employee['person_id'];
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        $found = count($matches) === 0
            ? 'no employee'
            : count($matches) . ' employees (person_id ' . implode(', ', $matches) . ')';

        $this->warn('LoadConfirmedCashCollections: ' . $found . ' matches "' . self::COLLECTOR_NAME . '", so the two confirmed collections were NOT loaded. Enter them by hand from the shift screen once the employee exists.');

        return null;
    }

    /**
     * The one shift that opened on $date, as its window and the midpoint of it, or null with a
     * reason printed.
     *
     * A shift is looked up by the day it opened rather than by id: it is only shift 35 and 39 on
     * production. Two shifts on the same day, or none, means the collection cannot be attributed
     * without guessing, and it is not loaded -- the same call the design makes for the sales of
     * 31/07, the one day with two shifts.
     *
     * Every reader of the window comes through here, including the check for whether the movement
     * is already recorded, so the window this migration writes into and the window it searches can
     * never come apart.
     *
     * @return array{cashup_id: int, open: string, close: string, midpoint: string}|null
     */
    private function shiftWindowOn(string $date): ?array
    {
        $shifts = $this->db->table('cash_up')
            ->select('cashup_id, open_date, close_date')
            ->where('deleted', 0)
            ->where('open_date >=', $date . ' 00:00:00')
            ->where('open_date <=', $date . ' 23:59:59')
            ->get()
            ->getResultArray();

        if (count($shifts) !== 1) {
            $this->warn("LoadConfirmedCashCollections: $date has " . count($shifts) . ' shift(s) instead of one, so its collection was NOT loaded.');

            return null;
        }

        $open = strtotime((string)$shifts[0]['open_date']);
        $close = strtotime((string)$shifts[0]['close_date']);

        if ($open === false || $close === false || $close < $open) {
            $this->warn("LoadConfirmedCashCollections: the shift of $date has no usable window (open {$shifts[0]['open_date']}, close {$shifts[0]['close_date']}), so its collection was NOT loaded.");

            return null;
        }

        return [
            'cashup_id' => (int)$shifts[0]['cashup_id'],
            'open'      => (string)$shifts[0]['open_date'],
            'close'     => (string)$shifts[0]['close_date'],
            'midpoint'  => date('Y-m-d H:i:s', intdiv($open + $close, 2)),
        ];
    }

    /**
     * Whether a live collection of that amount already sits inside that shift's window.
     *
     * That is the same question the shift reconciliation asks, which is what makes re-running this
     * migration safe: if the movement is already accounted for in that window, loading it again
     * would subtract a million pesos the drawer never lost twice.
     *
     * @param array{cashup_id: int, open: string, close: string, midpoint: string} $shift
     */
    private function alreadyLoaded(float $amount, array $shift): bool
    {
        return $this->db->table(self::TABLE)
            ->where('deleted', 0)
            ->where('amount', $amount)
            ->where('collected_at >=', $shift['open'])
            ->where('collected_at <=', $shift['close'])
            ->countAllResults() > 0;
    }

    /**
     * Migrations also run from the web installer, where CLI output has nowhere to go.
     *
     * Reporting goes through CLI::write rather than log_message because the production log
     * threshold is 4 and would throw away anything at info level.
     */
    private function report(string $message): void
    {
        if (is_cli()) {
            CLI::write($message);
        }
    }

    /**
     * Same, for the cases where confirmed money did not make it into the register.
     *
     * This one does log, at critical, because critical is what survives that same threshold of 4.
     * Without it a migration run from the web would fail to load real money in complete silence.
     * It only ever fires when something was not loaded.
     */
    private function warn(string $message): void
    {
        $this->report('  ! ' . $message);

        log_message('critical', $message);
    }
}
