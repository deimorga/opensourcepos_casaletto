<?php

namespace Tests\Views;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The geometry emitted for the receipt.
 *
 * @internal
 */
final class ReceiptPaperViewTest extends CIUnitTestCase
{
    private function render(array $config): string
    {
        return view('partial/receipt_paper', ['config' => $config]);
    }

    /**
     * Silence is the whole safety property here: a business that never chose a paper must get the
     * printing it had before, byte for byte.
     */
    public function testEmitsNothingWhenNoPaperIsConfigured(): void
    {
        $this->assertSame('', trim($this->render([])));
        $this->assertSame('', trim($this->render(['receipt_paper' => ''])));
    }

    /**
     * `margin: 0` is the fix that matters. The browser's default margins are around 10 mm a side,
     * and on the 48 mm this paper can actually print that eats more than half the usable width --
     * which is what made the receipt come out squeezed into a column.
     */
    public function testARollGetsItsOwnPageWithoutBrowserMargins(): void
    {
        $css = $this->render(['receipt_paper' => '58mm']);

        $this->assertStringContainsString('@page', $css);
        $this->assertMatchesRegularExpression('/@page\s*\{[^}]*size:\s*48mm auto/', $css);
        $this->assertMatchesRegularExpression('/@page\s*\{[^}]*margin:\s*0/', $css);
    }

    public function testAnEightyMillimetreRollUsesItsOwnWidth(): void
    {
        $css = $this->render(['receipt_paper' => '80mm']);

        $this->assertMatchesRegularExpression('/@page\s*\{[^}]*size:\s*72mm auto/', $css);
    }

    /**
     * OSPOS's own print sheet shrinks the receipt by a percentage. On a roll the size is already
     * decided by the rules above, and shrinking it again leaves it unreadable -- so the wrapper has
     * to state an absolute size that wins over the inherited one.
     *
     * This asserts the declaration, not the absence of a word: the first version of this test
     * checked that "75%" appeared nowhere, and failed because the CSS *comment* explains why the
     * 75% is being overridden. A test that reads the prose instead of the behaviour is a test that
     * breaks when someone improves a comment.
     */
    public function testTheRollSetsAnAbsoluteFontSizeInsteadOfShrinking(): void
    {
        $css = $this->render(['receipt_paper' => '58mm']);

        $this->assertMatchesRegularExpression(
            '/#receipt_wrapper[^{]*\{[^}]*font-size:\s*[\d.]+pt/',
            $css,
            'el recibo en tirilla necesita un tamaño de letra absoluto, no heredado'
        );
    }
}
