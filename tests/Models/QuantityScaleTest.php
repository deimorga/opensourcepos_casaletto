<?php

namespace Tests\Models;

use App\Models\Item_quantity;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;

/**
 * Covers Item_quantity::quantity_scale(), the single place that decides how
 * many decimals every quantity computation keeps.
 *
 * Why this exists at all: Load_config.php sets the *global* bcmath scale to
 * max(2, totals_decimals() + tax_decimals()), and totals_decimals() returns
 * `currency_decimals` -- a money setting that has nothing to do with weight.
 * For the supermarket tenant (currency_decimals = 0, tax_decimals = 2) the
 * global scale lands on 2, so any bcadd() of quantities that forgets to pass
 * a scale silently drops the third decimal: five grams per operation, with no
 * error and no trace. See docs/Tecnico/venta-por-peso-y-hardware-de-caja.md
 * sections 2.2 and 2.4.
 *
 * These tests run without a database on purpose: the scale rule is pure
 * arithmetic policy and must stay verifiable even when the DB-backed tests
 * cannot run.
 */
class QuantityScaleTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once APPPATH . 'Helpers/locale_helper.php';

        // Reproduce production's global scale for the supermarket tenant
        // (currency_decimals = 0 + tax_decimals = 2), so a regression that
        // drops the explicit scale argument fails here instead of passing by
        // accident under PHP's default scale of 0.
        bcscale(2);
    }

    /**
     * Casaletto is the tenant already selling every day with this code:
     * quantity_decimals = 0, currency_decimals = 0, tax_decimals = 2.
     */
    private function useUnitTenant(): void
    {
        $this->useTenantSettings(['quantity_decimals' => '0', 'currency_decimals' => '0', 'tax_decimals' => '2']);
    }

    /**
     * The greengrocer: quantity_decimals = 3, weights in kilograms.
     */
    private function useWeightTenant(): void
    {
        $this->useTenantSettings(['quantity_decimals' => '3', 'currency_decimals' => '0', 'tax_decimals' => '2']);
    }

    private function useTenantSettings(array $settings): void
    {
        $config           = new OSPOS();
        $config->settings = $settings;

        Factories::injectMock('config', OSPOS::class, $config);
    }

    public function testWeightTenantGetsItsThreeConfiguredDecimals(): void
    {
        $this->useWeightTenant();

        $this->assertSame(3, Item_quantity::quantity_scale());
    }

    public function testTenantAskingForMoreDecimalsThanUsualKeepsThem(): void
    {
        $this->useTenantSettings(['quantity_decimals' => '4', 'currency_decimals' => '0', 'tax_decimals' => '2']);

        $this->assertSame(4, Item_quantity::quantity_scale());
    }

    /**
     * quantity_decimals is a *display* setting. Every quantity column in the
     * schema is decimal(15,3) (item_quantities, sales_items.quantity_purchased,
     * receivings_items.quantity_purchased, inventory.trans_inventory), so a
     * tenant with quantity_decimals = 0 can still legitimately have 5.500 on
     * file. Computing its stock at scale 0 would round that half unit away --
     * that would be a brand new bug introduced by the fix, not a fix.
     */
    public function testUnitTenantNeverComputesBelowTheStoredColumnPrecision(): void
    {
        $this->useUnitTenant();

        $this->assertSame(3, Item_quantity::quantity_scale());
    }

    public function testUnitTenantAdditionOfWholeNumbersIsUnchanged(): void
    {
        $this->useUnitTenant();

        // The scale must never alter arithmetic that was already correct:
        // 5 + 2 has to stay 5 + 2 for the tenant that sells by the unit.
        $this->assertSame(0, bccomp('7', bcadd('5', '2', Item_quantity::quantity_scale()), 3));
    }

    public function testUnitTenantDoesNotLoseFractionalStockAlreadyOnFile(): void
    {
        $this->useUnitTenant();

        // Restocking 2 units on top of a 5.5 unit balance must land on 7.5,
        // exactly as today's float arithmetic does.
        $this->assertSame(0, bccomp('7.500', bcadd('5.500', '2', Item_quantity::quantity_scale()), 3));
    }

    /**
     * The defect in one line: two weighed bags of the same product. At the
     * global scale of 2 this sum is 1.47; at the quantity scale it is 1.475.
     */
    public function testWeighingTwoBagsOfTheSameProductKeepsTheGram(): void
    {
        $this->useWeightTenant();

        $this->assertSame('1.475', bcadd('0.735', '0.740', Item_quantity::quantity_scale()));
    }
}
