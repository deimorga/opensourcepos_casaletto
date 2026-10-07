<?php

declare(strict_types=1);

namespace Tests\Libraries;

use App\Libraries\Presale_register;
use App\Libraries\Sale_lib;
use App\Models\Item;
use App\Models\Presale;
use CodeIgniter\Session\Handlers\ArrayHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\Mock\MockSession;
use Config\Services;

/**
 * Building the register's cart for a presale delivery (App\Libraries\Presale_register).
 *
 * The register's cart is merged and discounted in ways that are right for a sale rung up at the till
 * and wrong for one whose prices were agreed months before: add_item() merges two lines of the same
 * product at the first line's price, and selecting a customer applies the customer's standing
 * discount. These tests pin that a delivery goes through neither.
 *
 * SHARED DATABASE: own customer, items and presale, removed in tearDown. Never a truncate.
 *
 * @internal
 */
final class PresaleRegisterTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';
    private Sale_lib $saleLib;
    private Presale_register $register;
    private int $customerId;
    private int $unitItem;
    private int $weightItem;
    private int $presaleId;

    protected function setUp(): void
    {
        parent::setUp();

        db_connect()->resetDataCache();

        $sessionConfig = config('Session');
        $session       = new MockSession(new ArrayHandler($sessionConfig, '0.0.0.0'), $sessionConfig);
        $session->setLogger(service('logger'));
        $session->start();
        Services::injectMock('session', $session);

        $this->saleLib  = new Sale_lib();
        $this->register = new Presale_register();

        $this->customerId = $this->makeCustomer();
        $this->unitItem   = $this->makeItem('PRREG-TEST unidad', Item::UNIT_OF_MEASURE_UNIT);
        $this->weightItem = $this->makeItem('PRREG-TEST kilo', Item::UNIT_OF_MEASURE_KG);

        // The same product twice at two agreed prices, then a product sold by weight.
        $this->presaleId = $this->makePresale([
            [$this->unitItem, '1', '7.00', '0'],
            [$this->unitItem, '2', '5.00', '0'],
            [$this->weightItem, '1.25', '16.00', '1.00'],
        ], '45.00');
    }

    protected function tearDown(): void
    {
        foreach (['presale_payments', 'presale_items', 'presales'] as $table) {
            $this->db->table($table)->where('presale_id', $this->presaleId ?? 0)->delete();
        }

        $items = [$this->unitItem ?? 0, $this->weightItem ?? 0];
        $this->db->table('item_quantities')->whereIn('item_id', $items)->delete();
        $this->db->table('items')->whereIn('item_id', $items)->delete();
        $this->db->table('customers')->where('person_id', $this->customerId ?? 0)->delete();
        $this->db->table('people')->where('person_id', $this->customerId ?? 0)->delete();

        parent::tearDown();
    }

    public function testTwoLinesOfTheSameProductKeepTheirOwnPrices(): void
    {
        $this->assertTrue($this->register->load($this->saleLib, $this->presale()));

        $cart = $this->saleLib->get_cart();

        $this->assertSame([1, 2, 3], array_keys($cart));
        $this->assertSame(['7.00', '1.000'], [$cart[1]['price'], $cart[1]['quantity']]);
        $this->assertSame(['5.00', '2.000'], [$cart[2]['price'], $cart[2]['quantity']], 'Not merged into the first line.');
        $this->assertSame(0, bccomp((string) $cart[2]['discounted_total'], '10.00', 2));
        $this->assertSame(['16.00', '1.250'], [$cart[3]['price'], $cart[3]['quantity']]);
        $this->assertSame(0, bccomp((string) $cart[3]['discount'], '1.00', 2), 'The agreed discount, not the customer\'s.');
    }

    public function testTheCustomerIsSetWithoutItsStandingDiscount(): void
    {
        $this->register->load($this->saleLib, $this->presale());

        $this->assertSame($this->customerId, $this->saleLib->get_customer());

        foreach ([1, 2] as $line) {
            $this->assertSame(0, bccomp((string) $this->saleLib->get_cart()[$line]['discount'], '0', 2), 'The customer has 10% standing; the agreed lines have none.');
        }
    }

    public function testThereIsExactlyOnePresalePaymentForWhatWasPaid(): void
    {
        $this->register->load($this->saleLib, $this->presale());
        $this->saleLib->add_payment(lang('Sales.cash'), '3.00');
        $this->saleLib->add_payment(lang('Sales.presale'), '999.00');

        $this->register->ensure_payment($this->saleLib, $this->presaleId);

        $payments = $this->saleLib->get_payments();
        $this->assertSame([lang('Sales.presale'), lang('Sales.cash')], array_keys($payments));
        $this->assertSame('45.00', $payments[lang('Sales.presale')]['payment_amount']);
        $this->assertSame('3.00', $payments[lang('Sales.cash')]['payment_amount'], 'A weight difference paid in cash stays.');
    }

    public function testOnlyTheWeightOfALineSoldByWeightMayDiffer(): void
    {
        $this->register->load($this->saleLib, $this->presale());
        $agreed = $this->saleLib->get_cart();

        $this->assertTrue($this->register->cart_matches($this->presaleId, $agreed));

        $heavier                = $agreed;
        $heavier[3]['quantity'] = '1.400';
        $this->assertTrue($this->register->cart_matches($this->presaleId, $heavier));

        $moreUnits                = $agreed;
        $moreUnits[1]['quantity'] = '2';
        $this->assertFalse($this->register->cart_matches($this->presaleId, $moreUnits));

        $cheaper             = $agreed;
        $cheaper[3]['price'] = '1.00';
        $this->assertFalse($this->register->cart_matches($this->presaleId, $cheaper));

        $missing = $agreed;
        unset($missing[2]);
        $this->assertFalse($this->register->cart_matches($this->presaleId, $missing));
    }

    public function testRestoringKeepsTheWeightAlreadyEntered(): void
    {
        $this->register->load($this->saleLib, $this->presale());
        $cart                = $this->saleLib->get_cart();
        $cart[3]['quantity'] = '1.400';
        // What copy_entire_sale() does to two lines of the same product: one line, first price.
        $cart[1]['quantity'] = '3';
        unset($cart[2]);
        $this->saleLib->set_cart($cart);

        $this->assertTrue($this->register->restore($this->saleLib, $this->presale(), NEW_ENTRY));

        $restored = $this->saleLib->get_cart();
        $this->assertTrue($this->register->cart_matches($this->presaleId, $restored));
        $this->assertSame('1.400', $restored[3]['quantity']);
    }

    public function testThePresalePaymentIsRecognisedByItsCode(): void
    {
        $this->assertTrue(Presale_register::is_presale_payment(lang('Sales.presale')));
        $this->assertFalse(Presale_register::is_presale_payment(lang('Sales.cash')));
        $this->assertTrue(Presale_register::has_presale_payment([lang('Sales.presale') => []]));
        $this->assertFalse(Presale_register::has_presale_payment([lang('Sales.cash') => []]));
    }

    /**
     * A payment reloaded from a tab saved in another language keeps its stored code, and the code
     * decides: "Preventa" under an English register is still the presale payment, and is replaced,
     * not kept next to the new one.
     */
    public function testAPresalePaymentLabelledInAnotherLanguageIsReplacedByItsCode(): void
    {
        $this->saleLib->add_payment('Preventa-otro-idioma', '41.00', CASH_ADJUSTMENT_FALSE, 'presale');
        $this->saleLib->add_payment(lang('Sales.debit'), '5.00', CASH_ADJUSTMENT_FALSE, 'debit');

        $this->assertTrue(Presale_register::is_presale_entry('Preventa-otro-idioma', $this->saleLib->get_payments()['Preventa-otro-idioma']));
        $this->assertFalse(Presale_register::is_presale_entry(lang('Sales.presale'), ['payment_type_code' => 'cash']), 'The code wins over the label.');

        $this->register->ensure_payment($this->saleLib, $this->presaleId);

        $payments = $this->saleLib->get_payments();
        $this->assertSame([lang('Sales.presale'), lang('Sales.debit')], array_keys($payments));
        $this->assertCount(1, Presale_register::presale_entries($payments));
    }

    private function presale(): array
    {
        return model(Presale::class)->get_info($this->presaleId);
    }

    /**
     * @param list<array{0: int, 1: string, 2: string, 3: string}> $lines [item, quantity, price, discount]
     */
    private function makePresale(array $lines, string $total): int
    {
        $this->db->table('presales')->insert([
            'campaign_id'      => 0,
            'customer_id'      => $this->customerId,
            'employee_id'      => 1,
            'location_id'      => 1,
            'created_at'       => date('Y-m-d H:i:s'),
            'delivery_date_id' => 0,
            'delivery_date'    => '2099-12-24',
            'status'           => Presale::STATUS_OPEN,
            'total'            => $total,
        ]);
        $presale = (int) $this->db->insertID();

        foreach ($lines as $index => [$item, $quantity, $price, $discount]) {
            $this->db->table('presale_items')->insert([
                'presale_id'    => $presale,
                'line'          => $index + 1,
                'item_id'       => $item,
                'quantity'      => $quantity,
                'unit_price'    => $price,
                'discount'      => $discount,
                'discount_type' => FIXED,
                'print_option'  => PRINT_YES,
                'item_type'     => ITEM,
            ]);
        }

        $this->db->table('presale_payments')->insert([
            'presale_id'        => $presale,
            'kind'              => 'payment',
            'payment_type_code' => 'cash',
            'amount'            => $total,
            'payment_time'      => date('Y-m-d H:i:s'),
            'employee_id'       => 1,
            'cashup_id'         => 0,
        ]);

        return $presale;
    }

    private function makeCustomer(): int
    {
        $this->db->table('people')->insert([
            'first_name'   => 'Ana',
            'last_name'    => 'PRREG-TEST',
            'phone_number' => '',
            'email'        => '',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
        ]);
        $person = (int) $this->db->insertID();

        $this->db->table('customers')->insert([
            'person_id'     => $person,
            'taxable'       => 1,
            'deleted'       => 0,
            'discount'      => '10.00',
            'discount_type' => PERCENT,
            'employee_id'   => 1,
        ]);

        return $person;
    }

    private function makeItem(string $name, string $unit): int
    {
        $this->db->table('items')->insert([
            'name'                  => $name,
            'category'              => 'Test',
            'item_number'           => null,
            'description'           => '',
            'cost_price'            => '1.00',
            'unit_price'            => '10.00',
            'unit_of_measure'       => $unit,
            'reorder_level'         => '0',
            'receiving_quantity'    => '1',
            'allow_alt_description' => 0,
            'is_serialized'         => 0,
            'item_type'             => ITEM,
            'stock_type'            => HAS_STOCK,
        ]);
        $id = (int) $this->db->insertID();

        $this->db->table('item_quantities')->insert(['item_id' => $id, 'location_id' => 1, 'quantity' => '100']);

        return $id;
    }
}
