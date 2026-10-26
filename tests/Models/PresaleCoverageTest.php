<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\Presale;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * How the payments of a presale cover its instalments, one by one (Presale::coverage()).
 *
 * Payments are not tied to an instalment: they fill the oldest one first. coverage() is the
 * accumulation Presale::derive() uses for the next instalment, exposed per instalment so the
 * portfolio can say what is still missing of the instalments due in the next days
 * (docs/Tecnico/preventas-cartera-y-avisos.md §3.1). Pure: no database.
 *
 * @internal
 */
final class PresaleCoverageTest extends CIUnitTestCase
{
    /**
     * @return list<array{installment_id: int, due_date: string, amount: string}>
     */
    private function plan(): array
    {
        // Out of order on purpose: coverage() sorts by date, like derive().
        return [
            ['installment_id' => 3, 'due_date' => '2026-12-01', 'amount' => '35000.00'],
            ['installment_id' => 1, 'due_date' => '2026-11-01', 'amount' => '30000.00'],
            ['installment_id' => 2, 'due_date' => '2026-11-15', 'amount' => '35000.00'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, array{0: string, 1: string}> due_date => [covered, missing]
     */
    private function byDate(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[$row['due_date']] = [$row['covered'], $row['missing']];
        }

        return $out;
    }

    public function testNothingPaidLeavesEveryInstalmentMissing(): void
    {
        $this->assertSame([
            '2026-11-01' => ['0.00', '30000.00'],
            '2026-11-15' => ['0.00', '35000.00'],
            '2026-12-01' => ['0.00', '35000.00'],
        ], $this->byDate(Presale::coverage('0.00', $this->plan())));
    }

    public function testAnExactPaymentCoversTheOldestInstalmentAndNothingElse(): void
    {
        $this->assertSame([
            '2026-11-01' => ['30000.00', '0.00'],
            '2026-11-15' => ['0.00', '35000.00'],
            '2026-12-01' => ['0.00', '35000.00'],
        ], $this->byDate(Presale::coverage('30000.00', $this->plan())));
    }

    /**
     * 50.000 pays the first instalment and 20.000 of the second: 15.000 is still missing of it.
     */
    public function testAPartialPaymentIsSharedBetweenTwoInstalments(): void
    {
        $this->assertSame([
            '2026-11-01' => ['30000.00', '0.00'],
            '2026-11-15' => ['20000.00', '15000.00'],
            '2026-12-01' => ['0.00', '35000.00'],
        ], $this->byDate(Presale::coverage('50000.00', $this->plan())));
    }

    public function testPaidInFullCoversEverything(): void
    {
        foreach (Presale::coverage('100000.00', $this->plan()) as $row) {
            $this->assertSame('0.00', $row['missing']);
            $this->assertSame($row['amount'], $row['covered']);
        }
    }

    public function testTheRowsKeepTheirOwnDataInDateOrder(): void
    {
        $rows = Presale::coverage('0.00', $this->plan());

        $this->assertSame([1, 2, 3], array_column($rows, 'installment_id'));
        $this->assertSame(['30000.00', '65000.00', '100000.00'], array_column($rows, 'cumulative'));
    }

    /**
     * The instalment due today is not late (derive()), and coverage() says what is missing of it.
     * Yesterday's uncovered instalment is late by one day. The same accumulation answers both.
     */
    public function testCoverageAgreesWithDeriveOnTheInstalmentDueTodayAndYesterday(): void
    {
        $today = Presale::derive(Presale::STATUS_OPEN, '100000.00', '30000.00', $this->plan(), '2026-11-15');
        $rows  = $this->byDate(Presale::coverage('30000.00', $this->plan()));

        $this->assertSame(Presale::STATE_UP_TO_DATE, $today['state']);
        $this->assertSame($rows[$today['next_due_date']][1], $today['next_due_amount']);

        $yesterday = Presale::derive(Presale::STATUS_OPEN, '100000.00', '30000.00', $this->plan(), '2026-11-16');

        $this->assertSame(Presale::STATE_LATE, $yesterday['state']);
        $this->assertSame(1, $yesterday['days_late']);
        $this->assertSame('35000.00', $rows['2026-11-15'][1]);
    }

    public function testDeriveStillReadsTheSameAfterSharingTheAccumulation(): void
    {
        $state = Presale::derive(Presale::STATUS_OPEN, '100000.00', '50000.00', $this->plan(), '2026-11-20');

        $this->assertSame(Presale::STATE_LATE, $state['state']);
        $this->assertSame(5, $state['days_late']);
        $this->assertSame('2026-11-15', $state['next_due_date']);
        $this->assertSame('15000.00', $state['next_due_amount']);
        $this->assertSame('65000.00', $state['due_to_date']);
    }
}
