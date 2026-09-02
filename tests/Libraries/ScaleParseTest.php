<?php

namespace Tests\Libraries;

use App\Libraries\Token_lib;
use App\Models\Tokens\Token;
use App\Models\Tokens\Token_barcode_weight;
use App\Models\Tokens\Token_scale_weight;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The scale interpreter is pure logic and is tested as such: no database, no HTTP, no scale.
 *
 * Frames come from docs/Tecnico/venta-por-peso-y-hardware-de-caja.md sections 4.3 and 5.10b, and
 * -- since 2026-09-01 -- from the client's own scale, captured off the port at the counter. The
 * point is twofold: that the shapes the market emits are all reachable from the configuration
 * screen without touching PHP, and that the frame Paraiso's ROCHI RC-A01E actually sends is read
 * correctly, byte for byte as it arrived.
 */
class ScaleParseTest extends CIUnitTestCase
{
    private Token_lib $tokenLib;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenLib = new Token_lib();
    }

    // ========== Why a new token had to exist ==========

    public function testBarcodeWeightTokenStillMatchesDigitsOnly(): void
    {
        // Locks the boundary: the barcode reader keeps the class it always had.
        $this->assertSame('\d', (new Token_barcode_weight())->get_value());
    }

    public function testBarcodeWeightTokenCannotCaptureADecimalPoint(): void
    {
        $result = $this->tokenLib->parse('N12.395', 'N{W:6}', Token::get_barcode_tokens());

        $this->assertSame([], $result, 'A digits-only class cannot span the point in the middle.');
    }

    public function testSplittingTheWeightInTwoTokensLosesTheSecondGroup(): void
    {
        // parse() indexes the tree by token id and array_shifts one length out of it, so the
        // '{W:3}' is never substituted and stays in the pattern as text that matches nothing.
        $tree = $this->tokenLib->scan('N{W:2}\.{W:3}');
        $this->assertSame(['2' => '{W:2}', '3' => '{W:3}'], $tree['W']);

        $result = $this->tokenLib->parse('N12.395', 'N{W:2}\.{W:3}', Token::get_barcode_tokens());
        $this->assertSame([], $result);
    }

    public function testScaleWeightTokenAcceptsDigitsAndThePoint(): void
    {
        $token = new Token_scale_weight();

        $this->assertSame('W', $token->token_id());
        $this->assertSame('[\d.]', $token->get_value());
    }

    // ========== The frames the market emits ==========

    public function testReadsTheMorescoFrameDocumentedInSection510b(): void
    {
        $raw = "N12.395  \n\r";

        $this->assertSame('12.395', $this->tokenLib->parse_scale($raw, 'N{W:6}', 1));
    }

    // ========== La báscula real del cliente, capturada el 2026-09-01 ==========

    /**
     * El formato REAL del ROCHI RC-A01E de Paraiso de la Canasta.
     *
     * Capturado en el mostrador: 4800 8-N-1 -- ni los 9600 del manual del fabricante ni los 19200
     * que concluyo la propia herramienta de captura -- y trama `NNN.NNN` + CR, en kilogramos, sin
     * signo y sin bandera de estado. La hipotesis que traiamos (la bandera `N` del modelo hermano
     * Moresco) resulto FALSA; de haberla dado por buena, el interprete no habria leido nada.
     *
     * Los dos valores estan verificados contra el visor del propio equipo, con foto.
     */
    #[DataProvider('tramasRealesDeParaiso')]
    public function testReadsTheFrameParaisoScaleActuallySends(string $frame, string $expected): void
    {
        $this->assertSame($expected, $this->tokenLib->parse_scale($frame, '{W:7}', 1));
    }

    public static function tramasRealesDeParaiso(): array
    {
        return [
            'visor marcaba 0.410'       => ["000.410\r", '0.410'],
            'visor marcaba 0.555'       => ["000.555\r", '0.555'],
            'plato vacio'               => ["000.000\r", '0.000'],
            'del barrido de scale-probe' => ["000.265\r", '0.265'],
        ];
    }

    /**
     * La trama deforme que llego justo al retirar el peso: nueve bytes de basura pegados delante de
     * un peso valido, SIN retorno de carro que los separe. Fue 1 de 126 tramas.
     *
     * Se lee 0.000, y esta bien que asi sea: `parse_scale()` busca la trama DENTRO de lo que le
     * llega, a proposito, porque el agente puede entregar dos lecturas pegadas -- ver
     * testTakesTheFirstReadingOutOfABufferedPairOfFrames. Anclar el patron arreglaria este caso y
     * romperia aquel.
     *
     * **La proteccion contra un peso equivocado no esta aqui, y esta prueba existe para dejarlo
     * dicho.** Esta en la caja: el bloque de la bascula de register.php exige TRES lecturas
     * seguidas, con marcas de tiempo distintas, que digan lo mismo. Una trama deforme no se repite
     * tres veces; el ruido medido en el equipo real no paso nunca de dos.
     */
    public function testTheMalformedFrameSeenAtTheCounterFindsTheWeightInsideIt(): void
    {
        $deforme = "\x00\x40\x3f\x40\x3f\x3f\x00\x3f\x3f" . '000.000';

        $this->assertSame('0.000', $this->tokenLib->parse_scale($deforme, '{W:7}', 1));
    }

    public function testReadsAPlainKilogramFrame(): void
    {
        $this->assertSame('0.735', $this->tokenLib->parse_scale('0.735', '{W:5}', 1));
    }

    public function testReadsAGramFrameThroughTheDivisor(): void
    {
        $this->assertSame('0.735', $this->tokenLib->parse_scale('+000735 g', '\+{W:6} g', 1000));
    }

    public function testReadsAStatusPrefixedFrame(): void
    {
        // The sign and the blank padding are matched by the pattern, not captured by the token.
        $this->assertSame('0.735', $this->tokenLib->parse_scale('ST,GS,+  0.735kg', 'ST,GS,\+\s+{W:5}kg', 1));
    }

    public function testTakesTheFirstReadingOutOfABufferedPairOfFrames(): void
    {
        $raw = "N12.395  \r\nN12.400  \r\n";

        $this->assertSame('12.395', $this->tokenLib->parse_scale($raw, 'N{W:6}', 1));
    }

    // ========== The shape of the answer ==========

    public function testAlwaysAnswersWithThreeDecimals(): void
    {
        $this->assertSame('12.000', $this->tokenLib->parse_scale('N12', 'N{W:2}', 1));
    }

    public function testAnswersAStringSoNoFloatEverRoundsTheWeight(): void
    {
        $weight = $this->tokenLib->parse_scale('001005', '{W:6}', 1000);

        $this->assertIsString($weight);
        $this->assertSame('1.005', $weight);
    }

    public function testAnEmptyPlatformIsAWeightOfZeroAndNotAFailure(): void
    {
        // Zero is a faithful reading. Refusing to sell it is the register's rule, not this one's.
        $this->assertSame('0.000', $this->tokenLib->parse_scale('N00.000', 'N{W:6}', 1));
    }

    // ========== Garbage in, null out -- never an exception ==========

    public function testEmptyFrameYieldsNull(): void
    {
        $this->assertNull($this->tokenLib->parse_scale('', 'N{W:6}', 1));
    }

    public function testFrameOfOnlyControlCharactersYieldsNull(): void
    {
        $this->assertNull($this->tokenLib->parse_scale("\x02\r\n\x03", 'N{W:6}', 1));
    }

    public function testPartialFrameYieldsNull(): void
    {
        $this->assertNull($this->tokenLib->parse_scale('N12.3', 'N{W:6}', 1));
    }

    public function testLineNoiseYieldsNull(): void
    {
        $this->assertNull($this->tokenLib->parse_scale('\xff\xfe garbage \x00', 'N{W:6}', 1));
    }

    public function testAFrameLongerThanTheCapYieldsNull(): void
    {
        $raw = str_repeat('N12.395  ', 200);

        $this->assertGreaterThan(Token_lib::SCALE_MAX_FRAME_LENGTH, strlen($raw));
        $this->assertNull($this->tokenLib->parse_scale($raw, 'N{W:6}', 1));
    }

    public function testAPatternThatDoesNotCompileYieldsNullInsteadOfAnErrorPage(): void
    {
        // Unbalanced parenthesis: preg_match() warns, CodeIgniter turns that into an exception, and
        // without the guard the cashier would see an error page instead of a missing weight.
        $this->assertNull($this->tokenLib->parse_scale('N12.395', 'N({W:6}', 1));
    }

    public function testAPatternWithNoWeightTokenYieldsNull(): void
    {
        $this->assertNull($this->tokenLib->parse_scale('N12.395', 'N12.395', 1));
    }

    public function testAMisplacedTokenThatCapturesTwoPointsYieldsNull(): void
    {
        // '{W:5}' over "1.2.3" captures "1.2.3", which is not a number.
        $this->assertNull($this->tokenLib->parse_scale('1.2.3', '{W:5}', 1));
    }

    public function testACapturedLoneDecimalPointYieldsNull(): void
    {
        $this->assertNull($this->tokenLib->parse_scale('N.', 'N{W:1}', 1));
    }

    public function testAnEmptyFormatYieldsNull(): void
    {
        $this->assertNull($this->tokenLib->parse_scale('N12.395', '', 1));
    }

    public function testANonPositiveDivisorYieldsNullRatherThanAWrongWeight(): void
    {
        $this->assertNull($this->tokenLib->parse_scale('N12.395', 'N{W:6}', 0));
        $this->assertNull($this->tokenLib->parse_scale('N12.395', 'N{W:6}', -1000));
    }

    public function testATenantWithNoScaleKeysAtAllGetsNull(): void
    {
        // Every tenant that was running before this feature existed. Reading the settings with ??
        // is what keeps this from being a fatal error instead of a null.
        $this->assertNull($this->tokenLib->parse_scale('N12.395'));
    }

    public function testNoFrameEverThrows(): void
    {
        $formats = ['N{W:6}', '{W:5}', 'N({W:6}', '', '{W:0}', '[{W:3}', '{W:600}', 'ST,GS,\+\s+{W:5}kg'];
        $frames = [
            '', ' ', "\x00", "\x02N12.395\x03", 'N12.395', '0.735', '+000735 g', '.', '...',
            'NaN', '-12.395', str_repeat('9', 300), "N12.395\x00\x00", '{W:5}', '/*+?[](){}\\',
            "\xc3\x28", 'N12.395N12.395'
        ];

        foreach ($formats as $format) {
            foreach ($frames as $frame) {
                foreach ([1, 1000] as $divisor) {
                    $weight = $this->tokenLib->parse_scale($frame, $format, $divisor);

                    $this->assertTrue(
                        $weight === null || preg_match('/^\d+\.\d{3}$/', $weight) === 1,
                        "Format '$format' on frame '" . addcslashes($frame, "\0..\37") . "' answered "
                        . var_export($weight, true)
                    );
                }
            }
        }
    }

    // ========== Pattern suggestion: the ten-minute installation ==========

    #[DataProvider('suggestionProvider')]
    public function testSuggestsAPatternThatReadsTheFrameItCameFrom(string $raw, string $format, int $divisor, string $weight): void
    {
        $suggestion = $this->tokenLib->suggest_scale_format($raw);

        $this->assertSame(['format' => $format, 'divisor' => $divisor], $suggestion);
        $this->assertSame($weight, $this->tokenLib->parse_scale($raw, $suggestion['format'], $suggestion['divisor']));
    }

    public static function suggestionProvider(): array
    {
        return [
            'moresco'      => ["N12.395  \n\r", 'N{W:6}', 1, '12.395'],
            'bare weight'  => ['0.735', '{W:5}', 1, '0.735'],
            'grams'        => ['+000735 g', '\+{W:6} g', 1000, '0.735'],
            'status frame' => ['ST,GS,+  0.735kg', 'ST,GS,\+  {W:5}kg', 1, '0.735'],
        ];
    }

    public function testSuggestionPicksTheLongestRunOfDigits(): void
    {
        $suggestion = $this->tokenLib->suggest_scale_format('02N12.395kg');

        $this->assertSame('02N{W:6}kg', $suggestion['format']);
        $this->assertSame('12.395', $this->tokenLib->parse_scale('02N12.395kg', $suggestion['format'], $suggestion['divisor']));
    }

    public function testSuggestionQuotesTheLiteralPartsOfTheFrame(): void
    {
        // A frame that happens to contain a token cannot smuggle a second one into the pattern.
        $suggestion = $this->tokenLib->suggest_scale_format('{W:5}12.395');

        $this->assertSame('\{W\:5\}{W:6}', $suggestion['format']);
        $this->assertSame('12.395', $this->tokenLib->parse_scale('{W:5}12.395', $suggestion['format'], $suggestion['divisor']));
    }

    public function testSuggestionReadsAWholeNumberAsGrams(): void
    {
        $this->assertSame(1000, $this->tokenLib->suggest_scale_format('+000735 g')['divisor']);
    }

    public function testSuggestionReadsANumberWithAPointAsKilograms(): void
    {
        $this->assertSame(1, $this->tokenLib->suggest_scale_format('N12.395')['divisor']);
    }

    public function testNoSuggestionForAFrameWithoutDigits(): void
    {
        $this->assertNull($this->tokenLib->suggest_scale_format('ST,GS,'));
        $this->assertNull($this->tokenLib->suggest_scale_format(''));
        $this->assertNull($this->tokenLib->suggest_scale_format("\x02\r\n"));
        $this->assertNull($this->tokenLib->suggest_scale_format(str_repeat('9', 300)));
    }

    // ========== Save-time validation ==========

    public function testAUsablePatternIsValid(): void
    {
        $this->assertTrue($this->tokenLib->is_valid_scale_format('N{W:6}'));
        $this->assertTrue($this->tokenLib->is_valid_scale_format('ST,GS,\+\s+{W:5}kg'));
    }

    public function testAPatternWithoutAWeightTokenIsNotValid(): void
    {
        $this->assertFalse($this->tokenLib->is_valid_scale_format('N12.395'));
        $this->assertFalse($this->tokenLib->is_valid_scale_format('N{P:6}'));
    }

    public function testAPatternThatDoesNotCompileIsNotValid(): void
    {
        $this->assertFalse($this->tokenLib->is_valid_scale_format('N({W:6}'));
        $this->assertFalse($this->tokenLib->is_valid_scale_format('[{W:6}'));
    }

    public function testAnEmptyPatternIsNotValid(): void
    {
        // "Not configured" is the caller's reading of empty; there is nothing here to compile.
        $this->assertFalse($this->tokenLib->is_valid_scale_format(''));
    }
}
