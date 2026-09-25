<?php

declare(strict_types=1);

namespace Tests\Helpers;

use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;

/**
 * Day/month/year (D27, 2026-09-24): what every screen relies on when the business format is d/m/Y.
 *
 * Every form in the system writes a date with date($config['dateformat'] ...) and reads it back with
 * date_create_from_format($config['dateformat'] ...), and the pickers get the same format translated
 * for moment.js and bootstrap-datetimepicker. The 30th of September is used on purpose: it does not
 * exist in month/day order, so a swap cannot pass unnoticed.
 *
 * @internal
 */
final class DateFormatDayFirstTest extends CIUnitTestCase
{
    private const FORMAT = 'd/m/Y';
    private const TIME   = 'H:i:s';

    public function testADateWrittenInTheFormatReadsBackAsTheSameMoment(): void
    {
        $moment  = mktime(14, 5, 0, 9, 30, 2026);
        $written = date(self::FORMAT . ' ' . self::TIME, $moment);

        $this->assertSame('30/09/2026 14:05:00', $written);

        $read = date_create_from_format(self::FORMAT . ' ' . self::TIME, $written);

        $this->assertNotFalse($read);
        $this->assertSame('2026-09-30 14:05:00', $read->format('Y-m-d H:i:s'));
    }

    public function testThePickersGetTheDayFirstFormat(): void
    {
        helper('locale');

        $this->assertSame('DD/MM/YYYY', dateformat_momentjs(self::FORMAT));
        $this->assertSame('dd/mm/yyyy', dateformat_bootstrap(self::FORMAT));
    }

    public function testDayFirstIsOneOfTheFormatsTheConfigurationOffers(): void
    {
        helper('locale');

        $this->assertArrayHasKey(self::FORMAT, get_dateformats());
    }

    // ---------------------------------------------------------------------------------------------
    // parse_typed_datetime(): what every form now reads a typed date with (D27).
    // ---------------------------------------------------------------------------------------------

    /**
     * @param callable(): void $test
     */
    private function withDayFirstSettings(callable $test): void
    {
        helper('locale');

        $settings = &config(OSPOS::class)->settings;
        $before   = [$settings['dateformat'] ?? null, $settings['timeformat'] ?? null];

        $settings['dateformat'] = self::FORMAT;
        $settings['timeformat'] = self::TIME;

        try {
            $test();
        } finally {
            [$settings['dateformat'], $settings['timeformat']] = $before;
        }
    }

    public function testADayFirstDateIsReadAsTyped(): void
    {
        $this->withDayFirstSettings(function (): void {
            $this->assertSame('2026-09-30 14:05:00', parse_typed_datetime('30/09/2026 14:05:00')->format('Y-m-d H:i:s'));
        });
    }

    /**
     * The old month-first habit. Before it became 2028-06-09 without a word; it can only mean the
     * 30th of September, and is read that way.
     */
    public function testAMonthFirstDateThatCanOnlyMeanOneThingIsReadThatWay(): void
    {
        $this->withDayFirstSettings(function (): void {
            $this->assertSame('2026-09-30 14:05:00', parse_typed_datetime('09/30/2026 14:05:00')->format('Y-m-d H:i:s'));
        });
    }

    /**
     * Valid both ways: the business's format wins. The screen spelled it out before saving.
     */
    public function testADateValidBothWaysFollowsTheBusinessFormat(): void
    {
        $this->withDayFirstSettings(function (): void {
            $this->assertSame('2026-09-05 08:00:00', parse_typed_datetime('05/09/2026 08:00:00')->format('Y-m-d H:i:s'));
        });
    }

    public function testImpossibleDatesAreRefusedInsteadOfRolledOver(): void
    {
        $this->withDayFirstSettings(function (): void {
            $this->assertFalse(parse_typed_datetime('31/31/2026 10:00:00'), 'No month 31 either way.');
            $this->assertFalse(parse_typed_datetime('30/02/2026 10:00:00'), 'February has no 30th; before, it became the 2nd of March.');
            $this->assertFalse(parse_typed_datetime('30/09/2026 25:00:00'), 'No hour 25.');
            $this->assertFalse(parse_typed_datetime('mañana'));
            $this->assertFalse(parse_typed_datetime(''));
            $this->assertFalse(parse_typed_datetime(null));
        });
    }

    public function testADateOnlyFieldIsReadWithoutTime(): void
    {
        $this->withDayFirstSettings(function (): void {
            $this->assertSame('2026-09-30', parse_typed_datetime('30/09/2026', false)->format('Y-m-d'));
            $this->assertSame('2026-09-30', parse_typed_datetime('09/30/2026', false)->format('Y-m-d'));
        });
    }

    public function testTheRefusalShowsWhatWasTypedAndAnExample(): void
    {
        $this->withDayFirstSettings(function (): void {
            $message = typed_date_error('31/31/2026');

            $this->assertStringContainsString('31/31/2026', $message);
            $this->assertStringContainsString(date(self::FORMAT), $message);
        });
    }

}
