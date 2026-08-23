<?php

namespace Tests\Database;

use App\Database\Migrations\Migration_AddCashupIdToSales;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Covers the decision the cashup_id backfill makes for every sale recorded before sales carried a
 * shift: which shift windows a given sale_time falls inside, and what counts as a window at all.
 *
 * These run without a database on purpose. The rule decides how much cash each shift is supposed to
 * have taken, and it has to be provable on the exact shapes production holds -- overlapping shifts,
 * shifts that run past midnight, two shifts in one day -- rather than only on whatever a seeded
 * database happens to contain. The migration keeps the rule in static methods for the same reason.
 *
 * Composer excludes app/Database/Migrations from the classmap, so the file is required by hand.
 */
class SalesCashupBackfillTest extends CIUnitTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        require_once APPPATH . 'Database/Migrations/20260823050000_AddCashupIdToSales.php';
    }

    /**
     * A shift as Cashups::postSave() records it: opening writes close_date = open_date, closing
     * overwrites close_date and flips the status.
     */
    private function shift(int $cashup_id, string $open_date, ?string $close_date, string $status): array
    {
        return [
            'cashup_id'  => $cashup_id,
            'status'     => $status,
            'open_date'  => $open_date,
            'close_date' => $close_date ?? $open_date,
        ];
    }

    private function closed(int $cashup_id, string $open_date, string $close_date): array
    {
        return $this->shift($cashup_id, $open_date, $close_date, 'closed');
    }

    private function running(int $cashup_id, string $open_date): array
    {
        return $this->shift($cashup_id, $open_date, null, 'open');
    }

    public function testSaleInsideOneShiftWindowBelongsToThatShift(): void
    {
        [$windows] = Migration_AddCashupIdToSales::build_windows([
            $this->closed(30, '2026-08-12 09:00:00', '2026-08-12 22:00:00'),
        ]);

        $covering = Migration_AddCashupIdToSales::shifts_covering('2026-08-12 13:45:00', $windows);

        $this->assertSame([30], $covering);
    }

    /**
     * The case that makes matching on dates unusable, taken from production: shift 31 opened on the
     * 13th and did not close until 16:21 on the 14th, while shift 32 opened at 13:08 that same 14th.
     * A sale rung up in between sits inside both, and no rule can say which drawer took the money.
     */
    public function testSaleInsideTwoOverlappingShiftsIsAmbiguous(): void
    {
        [$windows] = Migration_AddCashupIdToSales::build_windows([
            $this->closed(31, '2026-08-13 10:00:00', '2026-08-14 16:21:00'),
            $this->closed(32, '2026-08-14 13:08:00', '2026-08-15 01:30:00'),
        ]);

        $covering = Migration_AddCashupIdToSales::shifts_covering('2026-08-14 14:00:00', $windows);

        $this->assertCount(2, $covering, 'A sale in the overlap must report both shifts so the migration leaves it null.');
        $this->assertSame([31, 32], $covering);
    }

    public function testSaleOutsideTheOverlapStillBelongsToOneShift(): void
    {
        [$windows] = Migration_AddCashupIdToSales::build_windows([
            $this->closed(31, '2026-08-13 10:00:00', '2026-08-14 16:21:00'),
            $this->closed(32, '2026-08-14 13:08:00', '2026-08-15 01:30:00'),
        ]);

        $this->assertSame([31], Migration_AddCashupIdToSales::shifts_covering('2026-08-13 20:00:00', $windows));
        $this->assertSame([32], Migration_AddCashupIdToSales::shifts_covering('2026-08-14 18:00:00', $windows));
    }

    public function testSaleNoShiftCoversMatchesNothing(): void
    {
        [$windows] = Migration_AddCashupIdToSales::build_windows([
            $this->closed(30, '2026-08-12 09:00:00', '2026-08-12 22:00:00'),
        ]);

        $this->assertSame([], Migration_AddCashupIdToSales::shifts_covering('2026-08-12 23:30:00', $windows));
    }

    /**
     * Three of the recorded shifts run past midnight, which is why the day a sale falls on says
     * nothing about the shift it belongs to.
     */
    public function testShiftRunningPastMidnightCoversTheNextMorning(): void
    {
        [$windows] = Migration_AddCashupIdToSales::build_windows([
            $this->closed(25, '2026-07-31 17:00:00', '2026-08-01 02:30:00'),
        ]);

        $this->assertSame([25], Migration_AddCashupIdToSales::shifts_covering('2026-08-01 01:15:00', $windows));
    }

    public function testTwoShiftsInOneDayEachKeepTheirOwnSales(): void
    {
        [$windows] = Migration_AddCashupIdToSales::build_windows([
            $this->closed(24, '2026-07-31 08:00:00', '2026-07-31 15:00:00'),
            $this->closed(25, '2026-07-31 17:00:00', '2026-08-01 02:30:00'),
        ]);

        $this->assertSame([24], Migration_AddCashupIdToSales::shifts_covering('2026-07-31 12:00:00', $windows));
        $this->assertSame([25], Migration_AddCashupIdToSales::shifts_covering('2026-07-31 19:00:00', $windows));
        $this->assertSame([], Migration_AddCashupIdToSales::shifts_covering('2026-07-31 16:00:00', $windows));
    }

    public function testBothEndsOfAWindowAreInclusive(): void
    {
        [$windows] = Migration_AddCashupIdToSales::build_windows([
            $this->closed(30, '2026-08-12 09:00:00', '2026-08-12 22:00:00'),
        ]);

        $this->assertSame([30], Migration_AddCashupIdToSales::shifts_covering('2026-08-12 09:00:00', $windows));
        $this->assertSame([30], Migration_AddCashupIdToSales::shifts_covering('2026-08-12 22:00:00', $windows));
    }

    /**
     * Opening a shift writes close_date = open_date, so the shift that is still running has no
     * recorded close. It has to reach forward to now, or every sale of the current shift would come
     * out as covered by nothing.
     */
    public function testTheRunningShiftReachesForward(): void
    {
        [$windows, $unusable] = Migration_AddCashupIdToSales::build_windows([
            $this->closed(31, '2026-08-13 10:00:00', '2026-08-13 22:00:00'),
            $this->running(32, '2026-08-14 08:00:00'),
        ]);

        $this->assertSame([], $unusable);
        $this->assertNull($windows[32]['end'], 'The shift that never closed must have no upper bound.');
        $this->assertSame([32], Migration_AddCashupIdToSales::shifts_covering('2026-08-14 23:59:00', $windows));
    }

    /**
     * add_cashup_status.sql only marked a shift closed when it had a non-zero closing amount, so old
     * shifts closed at zero are still sitting there marked open. Handing each of them an open-ended
     * window would put every later sale inside several shifts at once and report the entire history
     * as ambiguous. Only the shift that can actually be running now gets that treatment; the rest
     * are named and left out.
     */
    public function testStaleOpenShiftIsReportedInsteadOfSwallowingEverythingAfterIt(): void
    {
        [$windows, $unusable] = Migration_AddCashupIdToSales::build_windows([
            $this->running(12, '2026-05-02 09:00:00'),
            $this->closed(31, '2026-08-13 10:00:00', '2026-08-13 22:00:00'),
            $this->running(32, '2026-08-14 08:00:00'),
        ]);

        $this->assertArrayNotHasKey(12, $windows);
        $this->assertArrayHasKey(12, $unusable);
        $this->assertSame([31], Migration_AddCashupIdToSales::shifts_covering('2026-08-13 15:00:00', $windows));
    }

    /**
     * Reopening a shift (Cashup::reopen_list()) flips the status back to open but leaves the real
     * close_date alone, and that recorded close is still the truth about when the drawer was
     * counted.
     */
    public function testReopenedShiftKeepsTheCloseItRecorded(): void
    {
        [$windows, $unusable] = Migration_AddCashupIdToSales::build_windows([
            $this->shift(31, '2026-08-13 10:00:00', '2026-08-13 22:00:00', 'open'),
        ]);

        $this->assertSame([], $unusable);
        $this->assertNotNull($windows[31]['end']);
        $this->assertSame([], Migration_AddCashupIdToSales::shifts_covering('2026-08-13 23:00:00', $windows));
    }

    public function testShiftWithNoOpenDateContributesNothing(): void
    {
        [$windows, $unusable] = Migration_AddCashupIdToSales::build_windows([
            ['cashup_id' => 7, 'status' => 'closed', 'open_date' => null, 'close_date' => '2026-08-12 22:00:00'],
        ]);

        $this->assertSame([], $windows);
        $this->assertArrayHasKey(7, $unusable);
    }

    public function testAllZeroTimestampIsNotAMoment(): void
    {
        [$windows, $unusable] = Migration_AddCashupIdToSales::build_windows([
            ['cashup_id' => 8, 'status' => 'closed', 'open_date' => '0000-00-00 00:00:00', 'close_date' => '0000-00-00 00:00:00'],
        ]);

        $this->assertSame([], $windows);
        $this->assertArrayHasKey(8, $unusable);
    }

    public function testSaleWithAnUnreadableTimeMatchesNothing(): void
    {
        [$windows] = Migration_AddCashupIdToSales::build_windows([
            $this->closed(30, '2026-08-12 09:00:00', '2026-08-12 22:00:00'),
        ]);

        $this->assertSame([], Migration_AddCashupIdToSales::shifts_covering('0000-00-00 00:00:00', $windows));
    }

    /**
     * A shift closed at the same second it opened never recorded a close either, and unless it is
     * the one still running it covers nothing rather than covering a single second.
     */
    public function testShiftClosedAtItsOwnOpeningInstantContributesNothing(): void
    {
        [$windows, $unusable] = Migration_AddCashupIdToSales::build_windows([
            $this->closed(9, '2026-08-12 09:00:00', '2026-08-12 09:00:00'),
            $this->running(10, '2026-08-13 09:00:00'),
        ]);

        $this->assertArrayNotHasKey(9, $windows);
        $this->assertArrayHasKey(9, $unusable);
    }
}
