<?php

namespace Tests\Libraries;

use App\Libraries\Sale_lib;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;

/**
 * The «Imprimir recibo» box: how it starts each sale, and what completing the sale does with it.
 *
 * Two different questions, and mixing them up is how this went wrong twice:
 *
 * - is_print_after_sale() -- how the box STARTS a sale. That is all print_receipt_check_behaviour
 *   decides: ticked, unticked, or as it was left.
 * - print_after_sale_choice() -- what the box holds NOW. That is what completing the sale obeys.
 *
 * Until 2026-09-18 completing read the raw session, and a "yes" from an earlier sale leaked into a
 * box drawn unticked. That day's fix made completing read the configuration instead -- and then a
 * cashier who ticked the box got nothing, because «Siempre desmarcada» was being read as «never
 * print». Found at the till on 2026-09-30.
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
     * «Siempre desmarcada»: every sale STARTS unticked, whatever the last one left behind.
     */
    public function testNeverStartsUnticked(): void
    {
        $this->assertFalse($this->saleLibWith('never', true)->is_print_after_sale());
    }

    public function testAlwaysStartsTicked(): void
    {
        $this->assertTrue($this->saleLibWith('always', false)->is_print_after_sale());
    }

    public function testRememberLastStartsAsItWasLeft(): void
    {
        $this->assertTrue($this->saleLibWith('last', true)->is_print_after_sale());
        $this->assertFalse($this->saleLibWith('last', false)->is_print_after_sale());
    }

    /**
     * Completing obeys the box, not the configuration. With «Siempre desmarcada» a cashier who
     * ticks it still gets the receipt -- that is what the shop asked for at the till.
     */
    public function testTheChoiceIsWhatTheBoxHolds(): void
    {
        $this->assertTrue($this->saleLibWith('never', true)->print_after_sale_choice());
        $this->assertFalse($this->saleLibWith('always', false)->print_after_sale_choice());
    }

    /**
     * A session that never went through the register has no answer, and no answer is not a yes.
     */
    public function testNoChoiceMeansNoPrint(): void
    {
        $lib = new Sale_lib();
        session()->remove('sales_print_after_sale');

        $this->assertFalse($lib->print_after_sale_choice());
    }
}
