<?php

namespace Tests\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\OSPOS;

/**
 * The sale that delivered a presale, seen from the Sales screens (docs/Tecnico/venta-anticipada.md
 * §8.3 and §8.4).
 *
 *  - Its 'presale' payment cannot be turned into another type: "Preventa" changed to "Efectivo"
 *    would count money twice, once in the instalments' shifts and again in the delivery's.
 *  - No other payment can be turned into 'presale' either: that would take real money out of a
 *    shift's income.
 *  - The sale cannot be cancelled: the presale would stay delivered while the stock came back.
 *    Returns go through the register's Return mode.
 *
 * Sales and presales made here use ids in the 932_000 range and are deleted in tearDown.
 *
 * @internal
 */
final class SalesPresaleGuardTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const DELIVERY_SALE = 932_001;
    private const PLAIN_SALE    = 932_002;
    private const PRESALE       = 932_500;

    private int $employee_id;

    private ?int $item_id = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->resetDataCache();
        config(OSPOS::class)->update_settings();

        $employee          = $this->db->table('employees')->select('person_id')->limit(1)->get()->getRow();
        $this->employee_id = $employee === null ? 1 : (int) $employee->person_id;

        $this->clearFixture();
    }

    protected function tearDown(): void
    {
        $this->clearFixture();

        parent::tearDown();
    }

    private function clearFixture(): void
    {
        $sales = [self::DELIVERY_SALE, self::PLAIN_SALE];

        $this->db->table('sales_payments')->whereIn('sale_id', $sales)->delete();
        $this->db->table('sales_items')->whereIn('sale_id', $sales)->delete();
        $this->db->table('sales')->whereIn('sale_id', $sales)->delete();
        $this->db->table('presales')->where('presale_id', self::PRESALE)->delete();

        if ($this->item_id !== null) {
            $this->db->table('items')->where('item_id', $this->item_id)->delete();
            $this->item_id = null;
        }
    }

    /**
     * A completed sale with the given payments, as [label, code, amount] rows.
     */
    private function sale(int $sale_id, array $payments): void
    {
        $this->db->table('sales')->insert([
            'sale_id'     => $sale_id,
            'sale_time'   => '2001-03-10 12:00:00',
            'customer_id' => null,
            'employee_id' => $this->employee_id,
            'comment'     => 'before',
            'sale_status' => COMPLETED,
        ]);

        foreach ($payments as [$label, $code, $amount]) {
            $this->db->table('sales_payments')->insert([
                'sale_id'           => $sale_id,
                'payment_type'      => $label,
                'payment_type_code' => $code,
                'payment_amount'    => $amount,
                'cash_refund'       => 0,
                'employee_id'       => $this->employee_id,
            ]);
        }
    }

    private function deliverySale(): void
    {
        $this->sale(self::DELIVERY_SALE, [
            [lang('Sales.presale'), 'presale', 120_000.00],
            [lang('Sales.cash'), 'cash', 5_000.00],
        ]);

        // Sale::get_info(), behind the edit form, starts from sales_items: a sale needs a line.
        $this->db->table('items')->insert([
            'name' => 'Pavo de prueba', 'category' => 'Test', 'item_number' => 'TEST-PRESALE-GUARD', 'description' => 'SalesPresaleGuardTest',
            'cost_price' => '1.00', 'unit_price' => '125000.00', 'reorder_level' => '0', 'receiving_quantity' => '1',
            'allow_alt_description' => 0, 'is_serialized' => 0,
        ]);
        $this->item_id = (int) $this->db->insertID();

        $this->db->table('sales_items')->insert([
            'sale_id'            => self::DELIVERY_SALE,
            'item_id'            => $this->item_id,
            'line'               => 1,
            'description'        => '',
            'serialnumber'       => '',
            'quantity_purchased' => '1.000',
            'item_cost_price'    => '1.00',
            'item_unit_price'    => '125000.00',
            'discount'           => '0.00',
            'discount_type'      => PERCENT,
            'item_location'      => 1,
        ]);

        $this->db->table('presales')->insert([
            'presale_id'       => self::PRESALE,
            'campaign_id'      => 932_500,
            'customer_id'      => 932_500,
            'employee_id'      => $this->employee_id,
            'location_id'      => 1,
            'created_at'       => '2001-03-01 10:00:00',
            'delivery_date_id' => 932_500,
            'delivery_date'    => '2001-03-10',
            'status'           => 'delivered',
            'total'            => '120000.00',
            'sale_id'          => self::DELIVERY_SALE,
            'delivered_at'     => '2001-03-10 12:00:00',
            'delivered_by'     => $this->employee_id,
        ]);
    }

    /**
     * @return array<string, array{payment_id: int, payment_type: string, payment_type_code: string, payment_amount: string}>
     */
    private function payments(int $sale_id): array
    {
        $rows = $this->db->table('sales_payments')->where('sale_id', $sale_id)->orderBy('payment_id', 'asc')->get()->getResultArray();

        return array_column($rows, null, 'payment_type_code');
    }

    private function signIn(): void
    {
        $session = Services::session();
        $session->destroy();
        $session->set('person_id', 1);
        $session->set('menu_group', 'office');
        $this->withSession(['person_id' => 1, 'menu_group' => 'office']);
    }

    /**
     * What the edit form posts: every existing payment with the type chosen for it.
     *
     * @param array<int, string> $types payment_id => label posted as its type
     */
    private function save(int $sale_id, array $types, array $extra = []): array
    {
        $config = config(OSPOS::class)->settings;
        $post   = [
            'date'               => date($config['dateformat'] . ' ' . $config['timeformat'], strtotime('2001-03-10 12:00:00')),
            'customer_id'        => '',
            'employee_id'        => (string) $this->employee_id,
            'comment'            => 'after',
            'invoice_number'     => '',
            'number_of_payments' => (string) count($types),
            'payment_type_new'   => PAYMENT_TYPE_UNASSIGNED,
            'payment_amount_new' => '',
        ];

        $i = 0;

        foreach ($types as $payment_id => $label) {
            $stored                     = $this->db->table('sales_payments')->where('payment_id', $payment_id)->get()->getRowArray();
            $post["payment_id_{$i}"]     = (string) $payment_id;
            $post["payment_type_{$i}"]   = $label;
            $post["payment_amount_{$i}"] = to_currency_no_money($stored['payment_amount']);
            $post["refund_type_{$i}"]    = lang('Sales.cash');
            $post["refund_amount_{$i}"]  = to_currency_no_money($stored['cash_refund']);
            $i++;
        }

        $this->signIn();

        return json_decode($this->post('sales/save/' . $sale_id, array_merge($post, $extra))->getJSON(), true);
    }

    private function delete(array $sale_ids): array
    {
        $this->signIn();

        $response = $this->post('sales/delete', ['ids' => array_map('strval', $sale_ids)]);

        return json_decode($response->getJSON(), true);
    }

    public function testThePresalePaymentCannotBeTurnedIntoCash(): void
    {
        $this->deliverySale();
        $before = $this->payments(self::DELIVERY_SALE);

        $result = $this->save(self::DELIVERY_SALE, [
            (int) $before['presale']['payment_id'] => lang('Sales.cash'),
            (int) $before['cash']['payment_id']    => lang('Sales.cash'),
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Sales.presale_payment_locked'), $result['message']);

        $after = $this->payments(self::DELIVERY_SALE);
        $this->assertArrayHasKey('presale', $after);
        $this->assertSame('120000.00', (string) $after['presale']['payment_amount']);
        $this->assertSame('before', $this->db->table('sales')->where('sale_id', self::DELIVERY_SALE)->get()->getRow()->comment);
    }

    /**
     * Editing the rest of the sale still works, and the 'presale' row comes out as it went in even
     * when the posted amount has been tampered with.
     */
    public function testTheRestOfTheSaleCanStillBeEdited(): void
    {
        $this->deliverySale();
        $before = $this->payments(self::DELIVERY_SALE);

        $result = $this->save(self::DELIVERY_SALE, [
            (int) $before['presale']['payment_id'] => lang('Sales.presale'),
            (int) $before['cash']['payment_id']    => lang('Sales.debit'),
        ], ['payment_amount_0' => '1']);

        $this->assertTrue($result['success'], (string) ($result['message'] ?? ''));

        $after = $this->payments(self::DELIVERY_SALE);
        $this->assertSame('120000.00', (string) $after['presale']['payment_amount']);
        $this->assertSame(lang('Sales.presale'), $after['presale']['payment_type']);
        $this->assertArrayHasKey('debit', $after);
        $this->assertSame('after', $this->db->table('sales')->where('sale_id', self::DELIVERY_SALE)->get()->getRow()->comment);
    }

    /**
     * A lighter real weight leaves the change on a cash row with no tender. Sale::update() deletes
     * every row whose amount is zero, so a plain edit used to erase it, and the shift's expected
     * cash went up by the change already handed back.
     */
    public function testEditingTheSaleKeepsTheChangeHandedBack(): void
    {
        $this->deliverySale();
        $this->db->table('sales_payments')->where('sale_id', self::DELIVERY_SALE)->where('payment_type_code', 'cash')
            ->update(['payment_amount' => 0, 'cash_refund' => 3_000]);
        $before = $this->payments(self::DELIVERY_SALE);

        $result = $this->save(self::DELIVERY_SALE, [
            (int) $before['presale']['payment_id'] => lang('Sales.presale'),
            (int) $before['cash']['payment_id']    => lang('Sales.cash'),
        ]);

        $this->assertTrue($result['success'], (string) ($result['message'] ?? ''));

        $after = $this->payments(self::DELIVERY_SALE);
        $this->assertArrayHasKey('cash', $after);
        $this->assertSame('3000.00', (string) $after['cash']['cash_refund']);
    }

    /**
     * Sale::update() works the code out again from the label, in the editor's language. A 'presale'
     * row stored with a label of another language must keep its code all the same.
     */
    public function testThePresaleCodeSurvivesALabelInAnotherLanguage(): void
    {
        $this->deliverySale();
        $this->db->table('sales_payments')->where('sale_id', self::DELIVERY_SALE)->where('payment_type_code', 'presale')
            ->update(['payment_type' => 'Preventa-otro-idioma']);
        $before = $this->payments(self::DELIVERY_SALE);

        $result = $this->save(self::DELIVERY_SALE, [
            (int) $before['presale']['payment_id'] => 'Preventa-otro-idioma',
            (int) $before['cash']['payment_id']    => lang('Sales.cash'),
        ]);

        $this->assertTrue($result['success'], (string) ($result['message'] ?? ''));
        $this->assertArrayHasKey('presale', $this->payments(self::DELIVERY_SALE));
    }

    public function testAnotherPaymentCannotBeTurnedIntoPresale(): void
    {
        $this->sale(self::PLAIN_SALE, [[lang('Sales.cash'), 'cash', 50_000.00]]);
        $before = $this->payments(self::PLAIN_SALE);

        $result = $this->save(self::PLAIN_SALE, [(int) $before['cash']['payment_id'] => lang('Sales.presale')]);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Sales.presale_payment_not_allowed'), $result['message']);
        $this->assertArrayHasKey('cash', $this->payments(self::PLAIN_SALE));
    }

    public function testANewPresalePaymentCannotBeAddedFromTheEditForm(): void
    {
        $this->sale(self::PLAIN_SALE, [[lang('Sales.cash'), 'cash', 50_000.00]]);
        $before = $this->payments(self::PLAIN_SALE);

        $result = $this->save(
            self::PLAIN_SALE,
            [(int) $before['cash']['payment_id'] => lang('Sales.cash')],
            ['payment_type_new' => lang('Sales.presale'), 'payment_amount_new' => '10000']
        );

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Sales.presale_payment_not_allowed'), $result['message']);
        $this->assertArrayNotHasKey('presale', $this->payments(self::PLAIN_SALE));
    }

    /**
     * A payment of another sale cannot be rewritten through this sale's form.
     */
    public function testAPaymentOfAnotherSaleIsRefused(): void
    {
        $this->deliverySale();
        $this->sale(self::PLAIN_SALE, [[lang('Sales.cash'), 'cash', 50_000.00]]);
        $foreign = $this->payments(self::DELIVERY_SALE)['presale'];

        $result = $this->save(self::PLAIN_SALE, [(int) $foreign['payment_id'] => lang('Sales.cash')]);

        $this->assertFalse($result['success']);
        $this->assertSame('presale', $this->db->table('sales_payments')->where('payment_id', $foreign['payment_id'])->get()->getRow()->payment_type_code);
    }

    public function testTheEditFormShowsThePresalePaymentReadOnly(): void
    {
        $this->deliverySale();
        $this->signIn();

        $body = (string) $this->get('sales/edit/' . self::DELIVERY_SALE)->getBody();

        $this->assertMatchesRegularExpression('/<input[^>]*name="payment_type_0"[^>]*readonly/', $body);
        $this->assertDoesNotMatchRegularExpression('/<select[^>]*name="payment_type_0"/', $body);
        $this->assertMatchesRegularExpression('/<select[^>]*name="payment_type_1"/', $body);
    }

    public function testTheSaleThatDeliveredAPresaleCannotBeCancelled(): void
    {
        $this->deliverySale();

        $result = $this->delete([self::DELIVERY_SALE]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('932500', $result['message']);
        $this->assertSame(COMPLETED, (int) $this->db->table('sales')->where('sale_id', self::DELIVERY_SALE)->get()->getRow()->sale_status);
    }

    /**
     * All or nothing, as the grid shows it: one blocked sale in the selection cancels none.
     */
    public function testASelectionWithADeliverySaleCancelsNothing(): void
    {
        $this->deliverySale();
        $this->sale(self::PLAIN_SALE, [[lang('Sales.cash'), 'cash', 50_000.00]]);

        $result = $this->delete([self::PLAIN_SALE, self::DELIVERY_SALE]);

        $this->assertFalse($result['success']);
        $this->assertSame(COMPLETED, (int) $this->db->table('sales')->where('sale_id', self::PLAIN_SALE)->get()->getRow()->sale_status);
    }

    /**
     * The guard is narrow: a sale with nothing to do with presales is cancelled as always.
     */
    public function testAnOrdinarySaleIsStillCancelled(): void
    {
        $this->sale(self::PLAIN_SALE, [[lang('Sales.cash'), 'cash', 50_000.00]]);

        $result = $this->delete([self::PLAIN_SALE]);

        $this->assertTrue($result['success']);
        $this->assertSame(CANCELED, (int) $this->db->table('sales')->where('sale_id', self::PLAIN_SALE)->get()->getRow()->sale_status);
    }
}
