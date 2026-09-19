<?php

namespace Tests\Views;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Opening the cash drawer when a sale is completed.
 *
 * The drawer hangs off the receipt printer by RJ11, so opening it is a control sequence sent to the
 * printer -- NOT a print. That is the whole reason this exists: the shop was printing a receipt it
 * did not want, on every sale, purely to reach the cash.
 *
 * @internal
 */
final class OpenCashDrawerViewTest extends CIUnitTestCase
{
    private function render(bool $abrir): string
    {
        return view('partial/open_cash_drawer', ['open_cash_drawer' => $abrir]);
    }

    /**
     * Silence is the safety property. A counter with no drawer, a business that sells by delivery,
     * and a sale just paid by card must all get exactly the page they had before.
     */
    public function testEmitsNothingWhenTheSettingIsOff(): void
    {
        $this->assertSame('', trim($this->render(false)));
        $this->assertSame('', trim(view('partial/open_cash_drawer', [])));
    }

    public function testAsksTheLocalProgramToOpenTheDrawer(): void
    {
        $js = $this->render(true);

        $this->assertStringContainsString('ws://127.0.0.1:7878/ws', $js);
        $this->assertStringContainsString('drawer.open', $js);
    }

    /**
     * The bytes are NOT decided here: the local program sends them. Its default sequence works as
     * shipped on practically any thermal printer, so there is nothing to configure per shop -- it
     * lives there so that changing it never requires a server deploy.
     */
    public function testDoesNotHardcodeTheEscposSequence(): void
    {
        $js = $this->render(true);

        $this->assertStringNotContainsString('27, 112', $js);
        $this->assertStringNotContainsString('\x1b', $js);
        $this->assertDoesNotMatchRegularExpression('/\bbytes\s*:/i', $js);
    }

    /**
     * A failure here must not reach the cashier. The sale is already closed and the money already
     * taken; a red banner would only frighten someone about something they cannot undo.
     */
    public function testAFailureIsLoggedAndNotShownToTheCashier(): void
    {
        $js = $this->render(true);

        $this->assertStringContainsString('console.warn', $js);
        $this->assertStringNotContainsString('alert(', $js);
        $this->assertStringNotContainsString('notify', $js);
    }

    /**
     * Printing and the drawer are two different orders to the same printer. Tying them together is
     * exactly the behaviour the shop asked us to break apart.
     */
    public function testTheDrawerDoesNotDependOnPrinting(): void
    {
        $js = $this->render(true);

        $this->assertStringNotContainsString('printdoc', $js);
        $this->assertStringNotContainsString('window.print', $js);
    }
}
