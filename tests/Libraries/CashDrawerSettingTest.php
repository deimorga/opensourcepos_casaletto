<?php

namespace Tests\Libraries;

use App\Libraries\Sale_lib;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;

/**
 * The setting that decides whether the drawer pops when a sale is completed.
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
            unset($ajustes['open_cash_drawer_on_sale']);
        } else {
            $ajustes['open_cash_drawer_on_sale'] = $valor;
        }
        $config->settings = $ajustes;

        $lib = new Sale_lib();
        $config->settings = $original;

        return $lib;
    }

    /**
     * The shipped state. A tenant that migrates and never opens the screen keeps the behaviour it
     * had, which is what protects a counter with no drawer.
     */
    public function testOffByDefault(): void
    {
        $this->assertFalse($this->saleLibWith('0')->should_open_cash_drawer());
    }

    public function testOnWhenTheBusinessTurnsItOn(): void
    {
        $this->assertTrue($this->saleLibWith('1')->should_open_cash_drawer());
    }

    /**
     * The settings map is cached, so a tenant can be running this code against a map that predates
     * the migration. A missing key must read as off, not as a fatal.
     */
    public function testAMissingSettingReadsAsOff(): void
    {
        $this->assertFalse($this->saleLibWith(null)->should_open_cash_drawer());
    }
}
