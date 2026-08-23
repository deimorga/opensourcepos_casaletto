<?php

namespace Tests\Models\Reports;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Summary_discounts interpolates the configured currency symbol straight into a SELECT, so whatever
 * escaping it relies on has to hold for a symbol somebody typed into the settings screen.
 *
 * This used to assert that an escaped symbol no longer contained the word "SLEEP" or "DROP". That
 * is not what escaping does. Escaping keeps the text and neutralises the quoting, so a symbol
 * reading '" + SLEEP(5) + "' comes back with its quotes escaped and the word intact -- inert, and
 * failing the old assertion. Demanding the word disappear demands deletion, which would also mangle
 * a legitimate symbol.
 *
 * It also escaped with its own addslashes() helper rather than the escape the model actually calls,
 * so it could pass while the application was broken and fail while it was fine. It now exercises
 * the real one.
 */
class SummaryDiscountsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $namespace   = 'App';

    /**
     * A complete, well-formed SQL single-quoted literal: opens and closes with a quote, and every
     * quote inside is either backslash-escaped or doubled. Nothing matching this can end the
     * literal early, which is the whole security property.
     */
    private const WELL_FORMED_LITERAL = "/^'(?:[^'\\\\]|\\\\.|'')*'$/s";

    public function testMaliciousCurrencySymbolCannotEscapeItsStringLiteral(): void
    {
        $malicious_symbols = [
            '"',
            "'",
            '" + SLEEP(5) + "',
            '", SLEEP(5), "',
            "' + (SELECT * FROM (SELECT(SLEEP(5)))a) + '",
            '"; DROP TABLE ospos_sales_items; --',
            '" OR 1=1 --',
            "\\",
            "\\'",
        ];

        foreach ($malicious_symbols as $symbol) {
            $escaped = db_connect()->escape($symbol);

            $this->assertMatchesRegularExpression(
                self::WELL_FORMED_LITERAL,
                $escaped,
                "A symbol must come back as one closed string literal, so it cannot break out: $symbol"
            );
        }
    }

    public function testOrdinaryCurrencySymbolSurvivesEscaping(): void
    {
        $normal_symbols = ['$', '€', '£', '¥', '₹', '₩', '₽', 'kr', 'CHF'];

        foreach ($normal_symbols as $symbol) {
            $escaped = db_connect()->escape($symbol);

            $this->assertMatchesRegularExpression(self::WELL_FORMED_LITERAL, $escaped);
            $this->assertSame(
                $symbol,
                stripslashes(substr($escaped, 1, -1)),
                "Escaping must not alter a legitimate symbol: $symbol"
            );
        }
    }
}
