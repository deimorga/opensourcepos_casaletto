<?php

namespace Tests\Libraries;

use App\Libraries\Sale_lib;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;

/**
 * The setting that decides whether the drawer pops when a sale is completed.
 *
 * Three states, because "abrirlo siempre" y "abrirlo cuando entra efectivo" son dos negocios
 * distintos: un cajon que salta en un pago con tarjeta se nota enseguida, y en una venta cobrada
 * por transferencia no hay ningun billete que guardar.
 *
 * @internal
 */
final class CashDrawerSettingTest extends CIUnitTestCase
{
    private function saleLibWith(?string $valor): Sale_lib
    {
        $config = config(OSPOS::class);
        $original = $config->settings;

        $ajustes = $original;
        if ($valor === null) {
            unset($ajustes['open_cash_drawer_behaviour']);
        } else {
            $ajustes['open_cash_drawer_behaviour'] = $valor;
        }
        $config->settings = $ajustes;

        $lib = new Sale_lib();
        $config->settings = $original;

        return $lib;
    }

    private function pago(string $tipo): array
    {
        return [$tipo => ['payment_type' => $tipo, 'payment_amount' => '10000']];
    }

    /**
     * The shipped state. A tenant that migrates and never opens the screen keeps the behaviour it
     * had, which is what protects a counter with no drawer.
     */
    public function testNeverIsTheShippedState(): void
    {
        $lib = $this->saleLibWith('never');

        $this->assertSame('never', $lib->cash_drawer_behaviour());
        $this->assertFalse($lib->should_open_cash_drawer($this->pago(lang('Sales.cash'))));
    }

    /**
     * The settings map is cached, so a tenant can be running this code against a map that predates
     * the migration. A missing key must read as «never», not as a fatal.
     */
    public function testAMissingSettingReadsAsNever(): void
    {
        $this->assertSame('never', $this->saleLibWith(null)->cash_drawer_behaviour());
        $this->assertFalse($this->saleLibWith(null)->should_open_cash_drawer($this->pago(lang('Sales.cash'))));
    }

    /**
     * Anything that is not one of the three states -- a hand-made request, a value left over from
     * an older shape of this setting -- falls back to the state where nothing moves.
     */
    public function testAnUnknownValueFallsBackToNever(): void
    {
        foreach (['1', '0', 'siempre', ''] as $valor) {
            $this->assertSame('never', $this->saleLibWith($valor)->cash_drawer_behaviour(), $valor);
        }

        $this->assertSame('never', Sale_lib::sanitizeCashDrawerBehaviour(null));
        $this->assertSame('never', Sale_lib::sanitizeCashDrawerBehaviour(['cash']));
        $this->assertSame('cash', Sale_lib::sanitizeCashDrawerBehaviour('cash'));
    }

    public function testCashOnlyOpensOnCash(): void
    {
        $lib = $this->saleLibWith('cash');

        $this->assertTrue($lib->should_open_cash_drawer($this->pago(lang('Sales.cash'))));
        $this->assertFalse($lib->should_open_cash_drawer($this->pago('Tarjeta')));
        $this->assertFalse($lib->should_open_cash_drawer([]));
    }

    /**
     * A split payment still moves cash, and that cash has to go into the drawer.
     */
    public function testCashOpensWhenOnlyPartOfTheSaleWasPaidInCash(): void
    {
        $pagos = $this->pago('Tarjeta') + $this->pago(lang('Sales.cash'));

        $this->assertTrue($this->saleLibWith('cash')->should_open_cash_drawer($pagos));
    }

    /**
     * The rounding adjustment is cash: it only ever exists alongside a cash payment, and it is
     * literally the coins that make the total land on a round figure.
     */
    public function testTheRoundingAdjustmentCountsAsCash(): void
    {
        $pagos = $this->pago(lang('Sales.cash_adjustment'));

        $this->assertTrue($this->saleLibWith('cash')->should_open_cash_drawer($pagos));
    }

    /**
     * Some counters keep everything in the drawer -- vouchers, notes, the card slips. That is a
     * decision for the business, not for this code.
     */
    public function testAlwaysOpensWhateverTheSaleWasPaidWith(): void
    {
        $lib = $this->saleLibWith('always');

        $this->assertTrue($lib->should_open_cash_drawer($this->pago('Tarjeta')));
        $this->assertTrue($lib->should_open_cash_drawer([]));
    }

    /**
     * The screen offers exactly the three states the library accepts. A fourth option in the
     * dropdown would save a value that reads back as «never», which looks like the setting is
     * broken rather than like the option does not exist.
     */
    public function testTheDropdownOffersExactlyTheStatesThatWork(): void
    {
        $opciones = array_keys(Sale_lib::get_cash_drawer_options());

        $this->assertSame(['never', 'cash', 'always'], $opciones);

        foreach ($opciones as $opcion) {
            $this->assertSame($opcion, Sale_lib::sanitizeCashDrawerBehaviour($opcion));
        }
    }
}
