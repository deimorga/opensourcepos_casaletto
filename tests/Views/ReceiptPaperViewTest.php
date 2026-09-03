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

        $this->assertStringContainsString('72mm', $css);
        $this->assertStringNotContainsString('48mm', $css);
    }

    /**
     * OSPOS's own print sheet shrinks the receipt to 75%. On a roll the size is already decided by
     * the rules above, and shrinking it again leaves it unreadable.
     */
    public function testTheRollSetsAnExplicitFontSizeInsteadOfShrinking(): void
    {
        $css = $this->render(['receipt_paper' => '58mm']);

        $this->assertStringContainsString('#receipt_wrapper', $css);
        $this->assertStringContainsString('font-size', $css);
        $this->assertStringNotContainsString('75%', $css);
    }
}
