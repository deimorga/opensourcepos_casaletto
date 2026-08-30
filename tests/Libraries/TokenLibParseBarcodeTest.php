<?php

namespace Tests\Libraries;

use App\Libraries\Token_lib;
use App\Models\Tokens\Token;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;

/**
 * The three defects of parse_barcode(), and the divisor that had no business being a literal.
 *
 * Safe to change because there is nothing to change: barcode_formats is '[]' on the tenant that is
 * trading, so the whole `if (!empty($barcode_formats))` branch has never executed in production.
 * Every test here therefore configures its own formats -- the shipped state is covered too, by
 * testATenantWithNoFormatsIsUntouched(), which is the only path a live register takes today.
 *
 * No database, no HTTP: parse_barcode() reads one setting and does arithmetic on a string.
 */
class TokenLibParseBarcodeTest extends CIUnitTestCase
{
    private Token_lib $tokenLib;
    private OSPOS $ospos;
    private array $originalSettings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokenLib = new Token_lib();
        $this->ospos = config(OSPOS::class);
        $this->originalSettings = $this->ospos->settings;
    }

    protected function tearDown(): void
    {
        // config() hands back a shared instance, so a setting left behind here would be read by
        // whatever test runs next in this process.
        $this->ospos->settings = $this->originalSettings;

        parent::tearDown();
    }

    /**
     * @param list<string> $formats
     */
    private function givenBarcodeFormats(array $formats, ?int $divisor = null): void
    {
        $this->ospos->settings['barcode_formats'] = json_encode($formats);

        if ($divisor !== null) {
            $this->ospos->settings['barcode_weight_divisor'] = (string) $divisor;
        } else {
            unset($this->ospos->settings['barcode_weight_divisor']);
        }
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} quantity, price, item
     */
    private function scan(string $barcode): array
    {
        $quantity = null;
        $price = null;
        $item = $barcode;

        $this->tokenLib->parse_barcode($quantity, $price, $item);

        return [$quantity, $price, $item];
    }

    // ========== The shipped state, which is what production actually runs ==========

    public function testATenantWithNoFormatsIsUntouched(): void
    {
        $this->givenBarcodeFormats([]);

        [$quantity, $price, $item] = $this->scan('7702001234567');

        $this->assertSame(1, $quantity, 'No formats means one of whatever was scanned.');
        $this->assertNull($price);
        $this->assertSame('7702001234567', $item, 'The code is handed on exactly as it was scanned.');
    }

    public function testAMissingBarcodeFormatsKeyIsReadAsNoFormats(): void
    {
        unset($this->ospos->settings['barcode_formats']);

        [$quantity, $price, $item] = $this->scan('7702001234567');

        $this->assertSame(1, $quantity);
        $this->assertNull($price);
        $this->assertSame('7702001234567', $item);
    }

    // ========== Defect 1: the loop had no break, so the last format overwrote the match ==========

    public function testTheFormatThatMatchesWins_EvenWhenALaterFormatDoesNot(): void
    {
        // The weight format is first and matches; the plain-EAN format is second and does not.
        // Without a break the second pass reset the quantity to 1 and the price to null, so a
        // weighed item was rung up as one unit -- the whole weight silently gone.
        $this->givenBarcodeFormats(['{I:4}{W:5}', '{I:13}']);

        [$quantity, $price, $item] = $this->scan('200100735');

        $this->assertSame(0.735, $quantity);
        $this->assertNull($price);
        $this->assertSame('2001', $item);
    }

    public function testAPriceEmbeddedInTheBarcodeSurvivesALaterNonMatchingFormat(): void
    {
        $this->givenBarcodeFormats(['{I:4}{P:5}', '{I:13}']);

        [$quantity, $price, $item] = $this->scan('200101250');

        $this->assertSame(1250.0, $price);
        $this->assertSame('2001', $item);
        $this->assertSame(1, $quantity, 'This format carries no weight token.');
    }

    public function testTheFirstMatchingFormatWins_NotTheLastOneListed(): void
    {
        // Both formats match the same twelve characters. Reading it as a weight is what the first
        // entry says, and the order in the configuration screen is the operator's statement of
        // precedence.
        $this->givenBarcodeFormats(['{I:7}{W:5}', '{I:7}{P:5}']);

        [$quantity, $price, $item] = $this->scan('770200100735');

        $this->assertSame(0.735, $quantity);
        $this->assertNull($price, 'The second format never ran, so it contributed no price.');
        $this->assertSame('7702001', $item);
    }

    public function testAFormatThatDoesNotMatchLeavesTheCodeAloneForALaterOneToClaim(): void
    {
        // The non-matching format is listed first here: skipping it must not consume the scan.
        $this->givenBarcodeFormats(['{I:13}', '{I:4}{W:5}']);

        [$quantity, , $item] = $this->scan('200100735');

        $this->assertSame(0.735, $quantity);
        $this->assertSame('2001', $item);
    }

    public function testACodeNoFormatRecognisesIsPassedThroughAsOneUnit(): void
    {
        $this->givenBarcodeFormats(['{I:4}{W:5}', '{I:13}']);

        [$quantity, $price, $item] = $this->scan('ABC');

        $this->assertSame(1, $quantity);
        $this->assertNull($price);
        $this->assertSame('ABC', $item, 'An unrecognised code is still a code; the item lookup decides.');
    }

    // ========== Defect 2: the weight divisor was the literal 1000 ==========

    public function testTheWeightDivisorDefaultsToAThousand(): void
    {
        // Every tenant that predates the setting. The number must not move under them.
        $this->givenBarcodeFormats(['{I:4}{W:5}']);

        [$quantity] = $this->scan('200100735');

        $this->assertSame(0.735, $quantity);
    }

    public function testAScannerThatEmitsWholeUnitsIsConfiguredWithADivisorOfOne(): void
    {
        $this->givenBarcodeFormats(['{I:4}{W:5}'], 1);

        [$quantity] = $this->scan('200100012');

        // 12, not 12.0: PHP's '/' hands back an int when the division comes out exact, and that has
        // always been true here (2000/1000 was an int too). The callers cast what they need.
        $this->assertEquals(12, $quantity, 'Twelve units, not twelve thousandths.');
    }

    public function testADivisorOfOneHundredReadsTheWeightInHundredths(): void
    {
        $this->givenBarcodeFormats(['{I:4}{W:5}'], 100);

        [$quantity] = $this->scan('200100735');

        $this->assertSame(7.35, $quantity);
    }

    public function testANonsenseDivisorFallsBackToAThousandRatherThanDividingByZero(): void
    {
        // A wrong weight is money, but so is an error page in front of a queue. Zero and negative
        // are typos with no defensible reading, so the historical behaviour is what they get.
        foreach ([0, -1000] as $divisor) {
            $this->givenBarcodeFormats(['{I:4}{W:5}'], $divisor);

            [$quantity] = $this->scan('200100735');

            $this->assertSame(0.735, $quantity, "Divisor $divisor must not change the weight.");
        }
    }

    // ========== Defect 3: the pattern was not anchored ==========

    public function testAFormatMustDescribeTheWholeCodeAndNotAFragmentOfIt(): void
    {
        // Unanchored, '(\w{4})(\d{5})' bit into the middle of this string and came back with item
        // "XX20" weighing 1.007 kg -- a real item number and a real weight, both invented.
        $this->givenBarcodeFormats(['{I:4}{W:5}']);

        [$quantity, $price, $item] = $this->scan('XX200100735YY');

        $this->assertSame(1, $quantity);
        $this->assertNull($price);
        $this->assertSame('XX200100735YY', $item);
    }

    public function testALongerCodeDoesNotMatchAShorterFormat(): void
    {
        $this->givenBarcodeFormats(['{I:13}']);

        [, , $item] = $this->scan('77020012345678');

        $this->assertSame('77020012345678', $item, 'Fourteen digits are not a thirteen-digit EAN.');
    }

    public function testAnExactLengthCodeStillMatches(): void
    {
        // The other half of anchoring: it must not have made the feature unusable.
        $this->givenBarcodeFormats(['{I:13}']);

        [$quantity, , $item] = $this->scan('7702001234567');

        $this->assertSame('7702001234567', $item);
        $this->assertSame(1, $quantity);
    }

    // ========== The (double) cast this method dragged along ==========

    public function testReadingAPriceRaisesNoDeprecation(): void
    {
        // '(double)' is an alias cast, deprecated in PHP 8.5. It sat on the price line, so every
        // price-carrying scan would have written a deprecation into the log of a register that is
        // otherwise quiet.
        $this->givenBarcodeFormats(['{I:4}{P:5}']);

        $deprecations = [];
        set_error_handler(
            static function (int $errno, string $message) use (&$deprecations): bool {
                $deprecations[] = $message;

                return true;
            },
            E_DEPRECATED | E_USER_DEPRECATED
        );

        try {
            [, $price] = $this->scan('200101250');
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $deprecations, implode(' / ', $deprecations));
        $this->assertIsFloat($price);
        $this->assertSame(1250.0, $price);
    }

    // ========== The scale reader must not be dragged along with the barcode reader ==========

    public function testTheScaleReaderStillFindsAFrameInsideALongerRead(): void
    {
        // parse_scale() depends on the pattern being unanchored: a buffered serial read legitimately
        // holds several frames and the first one is the answer. Anchoring parse() outright would
        // have broken the scale to fix the barcode.
        $this->assertSame('12.395', $this->tokenLib->parse_scale("N12.395N12.395", 'N{W:6}', 1));
    }

    public function testParseIsUnanchoredUnlessTheCallerAsksOtherwise(): void
    {
        // Unanchored, four word characters are found at the first offset that has them -- which on
        // this subject is the padding, not the item number. That is the hazard in one line.
        $this->assertSame(
            ['I' => 'XX20'],
            $this->tokenLib->parse('XX2001YY', '{I:4}', Token::get_barcode_tokens())
        );

        $this->assertSame(
            [],
            $this->tokenLib->parse('XX2001YY', '{I:4}', Token::get_barcode_tokens(), true)
        );
    }
}
