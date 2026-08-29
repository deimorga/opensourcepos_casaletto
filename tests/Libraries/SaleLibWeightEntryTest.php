<?php

namespace Tests\Libraries;

use App\Libraries\Sale_lib;
use CodeIgniter\Config\Factories;
use CodeIgniter\Session\Handlers\ArrayHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Config\OSPOS;
use Config\Services;

/**
 * Turning what the cashier -- or a scale in keyboard mode -- typed into a
 * number we are willing to sell against, and remembering which item is
 * waiting for it.
 *
 * Database-free on purpose. The parser is a pure function and the pending
 * item lives in the session, so the failure mode that would actually cost
 * money here (0.735 read as 735 kilos of tomatoes) stays verifiable even when
 * the DB-backed suite cannot run at all.
 */
class SaleLibWeightEntryTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Sale_lib reads config in its constructor and keeps the cart in the
        // session. Injecting both keeps this test at the library boundary --
        // no schema, no HTTP request.
        $config           = new OSPOS();
        $config->settings = [
            'quantity_decimals'  => '3',
            'currency_decimals'  => '0',
            'tax_decimals'       => '2',
            'multi_pack_enabled' => '0',
            'line_sequence'      => '0'
        ];
        Factories::injectMock('config', OSPOS::class, $config);

        $sessionConfig = config('Session');
        $session       = new MockSession(new ArrayHandler($sessionConfig, '0.0.0.0'), $sessionConfig);
        // Session::start() logs, and LoggerAwareTrait leaves $logger null until
        // something sets it -- without this the first start() dies with
        // "Call to a member function debug() on null".
        $session->setLogger(service('logger'));
        $session->start();
        Services::injectMock('session', $session);
    }

    /**
     * The reason the weight field does not go through parse_decimals().
     * With number_locale = es_CO that helper reads "0.735" as 735: a scale in
     * keyboard mode types a dot, and so does anyone used to a calculator.
     */
    public function testADotIsADecimalPointNotAThousandsSeparator(): void
    {
        $this->assertSame('0.735', Sale_lib::normalize_weight_input('0.735'));
    }

    public function testACommaIsADecimalPointToo(): void
    {
        $this->assertSame('0.735', Sale_lib::normalize_weight_input('0,735'));
    }

    public function testTheThirdDecimalSurvives(): void
    {
        $this->assertSame('1.475', Sale_lib::normalize_weight_input('1,475'));
        $this->assertSame('1.475', Sale_lib::normalize_weight_input('1.475'));
    }

    public function testSurroundingWhitespaceIsIgnored(): void
    {
        $this->assertSame('0.735', Sale_lib::normalize_weight_input("  0,735\r\n"));
    }

    public function testAWeightTypedWithoutItsLeadingZeroIsAccepted(): void
    {
        $this->assertSame('0.735', Sale_lib::normalize_weight_input('.735'));
        $this->assertSame('0.735', Sale_lib::normalize_weight_input(',735'));
    }

    public function testATrailingSeparatorIsCompletedRatherThanRejected(): void
    {
        $this->assertSame('5.0', Sale_lib::normalize_weight_input('5,'));
    }

    public function testAWholeNumberOfKilosIsAWeight(): void
    {
        $this->assertSame('12', Sale_lib::normalize_weight_input('12'));
    }

    /**
     * The weight field holds the focus for exactly as long as the register is
     * waiting to be told a weight, which is exactly when a cashier is most
     * likely to reach for the scanner instead. A barcode is a well-formed
     * number, and at 4.500 a kilo "7702001002344" is a line worth more than
     * the shop -- so a long round number is refused rather than sold.
     */
    public function testALongRoundNumberIsABarcodeAndNotAWeight(): void
    {
        $this->assertNull(Sale_lib::normalize_weight_input('7702001002344'));
    }

    public function testTheGuardStopsAtBarcodeLengthAndDoesNotEatRealWeights(): void
    {
        $this->assertSame('9999999', Sale_lib::normalize_weight_input('9999999'), 'Seven digits is still a number somebody could mean.');
        $this->assertSame('7702001.002344', Sale_lib::normalize_weight_input('7702001,002344'), 'The guard is for round numbers; anything with decimals was typed, not scanned.');
    }

    /**
     * Weights are never grouped, so a second separator is not a weight -- it
     * is a misread, and guessing which separator meant what is how a 1,475 kg
     * bag becomes a 1475 kg bag.
     */
    #[DataProvider('notAWeightProvider')]
    public function testWhatIsNotAWeightIsRejectedRatherThanGuessedAt(string $raw): void
    {
        $this->assertNull(Sale_lib::normalize_weight_input($raw), sprintf('"%s" must not be accepted as a weight.', $raw));
    }

    public static function notAWeightProvider(): array
    {
        return [
            'empty'                 => [''],
            'blank'                 => ['   '],
            'grouped thousands'     => ['1.234,5'],
            'grouped the other way' => ['1,234.5'],
            'two dots'              => ['1.2.3'],
            'signed'                => ['+0.735'],
            'negative'              => ['-0.735'],
            'with a unit suffix'    => ['0.735kg'],
            'a raw scale frame'     => ['ST,GS,+  0.735kg'],
            'a barcode with a check letter' => ['7702001002344X'],
            'a bare EAN-13 barcode'         => ['7702001002344'],
            'the shortest standard barcode' => ['77020010'],
            'letters'               => ['abc'],
            'separator only'        => ['.'],
            'an expression'         => ['0.5+0.235'],
        ];
    }

    /**
     * The weight the cashier is waiting to type is session state, so it has to
     * survive a page reload and disappear when the sale does.
     */
    public function testTheItemWaitingForAWeightIsRememberedAcrossRequests(): void
    {
        $sale_lib = new Sale_lib();

        $this->assertSame([], $sale_lib->get_weight_entry(), 'Nothing is pending on a fresh register.');

        $sale_lib->set_weight_entry(['item_id_or_number' => '42', 'name' => 'Tomate']);

        $this->assertSame('42', (new Sale_lib())->get_weight_entry()['item_id_or_number'], 'A second request must find the pending item still there.');
    }

    public function testCancellingTheSaleForgetsTheItemWaitingForAWeight(): void
    {
        $sale_lib = new Sale_lib();
        $sale_lib->set_weight_entry(['item_id_or_number' => '42', 'name' => 'Tomate']);

        $sale_lib->clear_weight_entry();

        $this->assertSame([], $sale_lib->get_weight_entry());
    }

    /**
     * lang() hands back the key itself when the line is missing, which would
     * put "Sales.weight" on the register in place of a label.
     */
    public function testAMissingTranslationFallsBackToRealTextInsteadOfTheKey(): void
    {
        $this->assertSame('Weight', Sale_lib::translate_or('Sales.a_key_that_does_not_exist', 'Weight'));
    }

    public function testAnExistingTranslationWins(): void
    {
        $this->assertSame(lang('Sales.quantity'), Sale_lib::translate_or('Sales.quantity', 'a fallback nobody should see'));
        $this->assertNotSame('a fallback nobody should see', Sale_lib::translate_or('Sales.quantity', 'a fallback nobody should see'));
    }
}
