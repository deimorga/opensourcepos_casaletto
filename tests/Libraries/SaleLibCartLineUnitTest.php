<?php

namespace Tests\Libraries;

use App\Libraries\Sale_lib;
use App\Models\Item;
use CodeIgniter\Config\Factories;
use CodeIgniter\Session\Handlers\ArrayHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockSession;
use Config\OSPOS;
use Config\Services;

/**
 * Reading the unit of measure off a cart line.
 *
 * Database-free on purpose. The cart lives in the session, not in a table, so
 * the failure this guards against -- a register throwing "undefined array key"
 * on a sale that was already open when the code shipped -- can be reproduced
 * with nothing but an array, and stays verifiable even when the DB-backed
 * suite cannot run at all.
 */
class SaleLibCartLineUnitTest extends CIUnitTestCase
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
     * The one that keeps the till open on deploy day. Every sale that is open
     * when this ships has cart lines written before the key existed, and no
     * migration can reach them: the cart is in the session.
     */
    public function testACartLineFromASessionOpenedBeforeTheDeployReadsAsAUnitItem(): void
    {
        $legacy_line = [
            'item_id'  => 1,
            'name'     => 'Empanada',
            'quantity' => '2',
            'price'    => '5000'
        ];

        $this->assertSame(Item::UNIT_OF_MEASURE_UNIT, Sale_lib::line_unit_of_measure($legacy_line));
        $this->assertFalse(Sale_lib::line_sells_by_weight($legacy_line));
    }

    public function testACartLineCarryingTheUnitIsReadBack(): void
    {
        $line = ['item_id' => 1, 'unit_of_measure' => Item::UNIT_OF_MEASURE_KG];

        $this->assertSame(Item::UNIT_OF_MEASURE_KG, Sale_lib::line_unit_of_measure($line));
        $this->assertTrue(Sale_lib::line_sells_by_weight($line));
    }

    /**
     * edit_item() mutates the line in place and never looks the item up again,
     * so a line from an older session cannot gain the key by being edited.
     * This test exists so that stays a decision and not a surprise.
     */
    public function testEditingALegacyLineDoesNotGiveItTheKeyAndStillReadsSafely(): void
    {
        $sale_lib = new Sale_lib();
        $sale_lib->set_cart([
            1 => [
                'item_id'          => 1,
                'item_location'    => 1,
                'line'             => 1,
                'name'             => 'Empanada',
                'description'      => '',
                'serialnumber'     => '',
                'quantity'         => '2',
                'discount'         => '0',
                'discount_type'    => 0,
                'price'            => '5000',
                'total'            => '10000',
                'discounted_total' => '10000',
                'print_option'     => PRINT_YES
            ]
        ]);

        $sale_lib->edit_item('1', '', '', '3', '0', '0', '5000');

        $line = $sale_lib->get_cart()[1];

        $this->assertArrayNotHasKey('unit_of_measure', $line, 'A legacy line does not gain the key by being edited -- which is exactly why every read defaults.');
        $this->assertSame(Item::UNIT_OF_MEASURE_UNIT, Sale_lib::line_unit_of_measure($line));
    }
}
