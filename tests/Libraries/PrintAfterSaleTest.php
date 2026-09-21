<?php

namespace Tests\Libraries;

use App\Libraries\Sale_lib;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;

/**
 * Whether the receipt prints by itself when a sale is completed.
 *
 * This is the rule the completion screen now asks. It used to read the raw session instead, so the
 * configuration only reached the checkbox: the box rendered unchecked because the register DID ask
 * this method, while completing the sale printed anyway because the session still held the "yes"
 * from the last time somebody ticked it. What the cashier saw and what the printer did came apart
 * -- reproduced in staging before the fix, with the box visibly unchecked and paper coming out.
 *
 * @internal
 */
final class PrintAfterSaleTest extends CIUnitTestCase
{
    private function saleLibWith(string $comportamiento, $sesion): Sale_lib
    {
        $config = config(OSPOS::class);
        $original = $config->settings;
        $ajustes = $original;
        $ajustes['print_receipt_check_behaviour'] = $comportamiento;
        $config->settings = $ajustes;

        $lib = new Sale_lib();
        $lib->set_print_after_sale((bool)$sesion);

        $config->settings = $original;

        return $lib;
    }

    /**
     * The case the shop asked for: "never" has to mean never, whatever the session remembers.
     */
    public function testNeverWinsOverAStaleSession(): void
    {
        $this->assertFalse(
            $this->saleLibWith('never', true)->is_print_after_sale(),
            '«Siempre desmarcada» no puede imprimir porque la sesión recuerde un sí'
        );
    }

    public function testAlwaysWinsOverAStaleSession(): void
    {
        $this->assertTrue($this->saleLibWith('always', false)->is_print_after_sale());
    }

    public function testRememberLastFollowsTheSession(): void
    {
        $this->assertTrue($this->saleLibWith('last', true)->is_print_after_sale());
        $this->assertFalse($this->saleLibWith('last', false)->is_print_after_sale());
    }
}
