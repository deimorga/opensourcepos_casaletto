<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Models\Item;
use App\Models\Presale;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\OSPOS;

/**
 * Delivering a presale through the REAL register (docs/Tecnico/venta-anticipada.md §7, D17, T4, T18).
 *
 * The money path, end to end through Sales: a paid presale is sent to the register, the cashier
 * completes it, and what comes out is a completed sale at the agreed prices, paid by one presale
 * payment for what was paid, sealed with the open shift, with the stock gone from the presale's
 * location -- and the presale delivered, by that sale, once.
 *
 * Every guard of §7.3 is proved to refuse on a delivery AND to leave every other cart alone.
 *
 * SHARED DATABASE: this file creates its own customer, items, open shift and presales, and removes
 * exactly those in tearDown. Never a truncate. The settings it touches (presales_enable,
 * dinner_table_enable) are put back as they were, and the presales grant is given to person 1 only
 * when it did not have it, and taken away again.
 *
 * Sessions are built like OrderTicketsRegisterTest: getReq()/postReq() re-arm from the LIVE $_SESSION
 * the previous request left, so the cart carries over.
 *
 * @internal
 */
final class PresaleDeliveryRegisterTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private const TOUCHED_KEYS = ['presales_enable', 'dinner_table_enable'];

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    /**
     * @var array<string, string|null>
     */
    private array $configBefore = [];

    private bool $grantedHere = false;
    private int $customerId;
    private int $otherCustomerId;
    private int $cashupId;
    private int $unitItem;
    private int $weightItem;

    /**
     * @var list<int>
     */
    private array $tablesCreated = [];

    protected function setUp(): void
    {
        parent::setUp();

        // See SalesControllerTest::setUp(): a stale table list makes Config\OSPOS fall back to defaults.
        db_connect()->resetDataCache();

        foreach (self::TOUCHED_KEYS as $key) {
            $row                      = $this->db->table('app_config')->where('key', $key)->get()->getRow();
            $this->configBefore[$key] = $row === null ? null : (string) $row->value;
        }

        $this->setConfig('presales_enable', '1');
        $this->setConfig('dinner_table_enable', '1');

        if ($this->db->table('grants')->where(['permission_id' => 'presales', 'person_id' => 1])->countAllResults() === 0) {
            $this->db->table('grants')->insert(['permission_id' => 'presales', 'person_id' => 1, 'menu_group' => 'home']);
            $this->grantedHere = true;
        }

        $this->customerId      = $this->makeCustomer('Muñoz PDREG-TEST');
        $this->otherCustomerId = $this->makeCustomer('Otro PDREG-TEST');
        $this->cashupId        = $this->openShift();
        $this->unitItem        = $this->makeItem('PDREG-TEST unidad', '10.00', Item::UNIT_OF_MEASURE_UNIT);
        $this->weightItem      = $this->makeItem('PDREG-TEST pernil', '20.00', Item::UNIT_OF_MEASURE_KG);

        $this->loginAsCashier();
    }

    protected function tearDown(): void
    {
        $customers = [$this->customerId ?? 0, $this->otherCustomerId ?? 0];

        $presales = array_column($this->db->table('presales')->select('presale_id')->whereIn('customer_id', $customers)->get()->getResultArray(), 'presale_id');

        if ($presales !== []) {
            foreach (['presale_events', 'presale_payments', 'presale_installments', 'presale_items', 'presales'] as $table) {
                $this->db->table($table)->whereIn('presale_id', $presales)->delete();
            }
        }

        $sales   = $this->db->table('sales')->select('sale_id, dinner_table_id')->whereIn('customer_id', $customers)->get()->getResultArray();
        $saleIds = array_column($sales, 'sale_id');

        foreach (array_column($sales, 'dinner_table_id') as $table) {
            if ((int) $table > 2) {
                $this->tablesCreated[] = (int) $table;
            }
        }

        // An open tab can still be customer-less if a test failed half way.
        $saleIds = array_merge($saleIds, array_column(
            $this->db->table('sales')->select('sale_id')->whereIn('dinner_table_id', $this->tablesCreated === [] ? [0] : $this->tablesCreated)->get()->getResultArray(),
            'sale_id',
        ));

        if ($saleIds !== []) {
            foreach (['sales_payments', 'sales_items_taxes', 'sales_taxes', 'sales_items', 'sales'] as $table) {
                $this->db->table($table)->whereIn('sale_id', $saleIds)->delete();
            }
        }

        if ($this->tablesCreated !== []) {
            $this->db->table('dinner_tables')->whereIn('dinner_table_id', $this->tablesCreated)->delete();
        }

        $items = [$this->unitItem ?? 0, $this->weightItem ?? 0];
        $this->db->table('inventory')->whereIn('trans_items', $items)->delete();
        $this->db->table('item_quantities')->whereIn('item_id', $items)->delete();
        $this->db->table('items')->whereIn('item_id', $items)->delete();
        $this->db->table('cash_up')->where('cashup_id', $this->cashupId ?? 0)->delete();
        $this->db->table('customers')->whereIn('person_id', $customers)->delete();
        $this->db->table('people')->whereIn('person_id', $customers)->delete();

        if ($this->grantedHere) {
            $this->db->table('grants')->where(['permission_id' => 'presales', 'person_id' => 1])->delete();
        }

        foreach ($this->configBefore as $key => $value) {
            if ($value === null) {
                $this->db->table('app_config')->where('key', $key)->delete();
            } else {
                $this->db->table('app_config')->replace(['key' => $key, 'value' => $value]);
            }
        }

        config(OSPOS::class)->update_settings();

        $_SESSION = [];

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    // Delivering
    // ---------------------------------------------------------------------------------------------

    /**
     * Tables on: the delivery gets a tab of its own, linked to the presale, with the agreed lines, the
     * presale's customer (no customer discount) and one presale payment for what was paid.
     */
    public function testAPaidPresaleOpensItsOwnTabAsAgreed(): void
    {
        $presale = $this->makePaidPresale();

        $this->postReq('sales/deliverPresale/' . $presale, [])->assertRedirectTo(site_url('sales'));
        $page = $this->getReq('sales');

        $this->assertDeliveryBanner($page, $presale);

        $sale = (int) model(Presale::class)->get_info($presale)['sale_id'];
        $this->assertGreaterThan(0, $sale, 'The presale is linked to its tab.');
        $this->assertSame(OPENED, $this->saleStatus($sale));
        $this->assertSame($sale, (int) $_SESSION['sale_id']);

        $this->assertSame([
            1 => [$this->unitItem, '2.000', '7.00', '0.00'],
            2 => [$this->weightItem, '1.500', '18.00', '0.00'],
        ], $this->cartSignature(), 'Agreed prices, not the catalogue\'s, and no customer discount.');
        $this->assertSame($this->customerId, (int) $_SESSION['sales_customer']);
        $this->assertSame([lang('Sales.presale') => '41.00'], $this->sessionPayments());
    }

    /**
     * T4: completing the delivery is a normal completed sale, and the presale is delivered by it.
     */
    public function testCompletingTheDeliveryIsACompletedSaleAndDeliversThePresale(): void
    {
        $presale = $this->makePaidPresale();
        $this->deliver($presale);

        $this->postReq('sales/complete', [])->assertStatus(200);

        $row  = model(Presale::class)->get_info($presale);
        $sale = (int) $row['sale_id'];

        $this->assertSame(Presale::STATUS_DELIVERED, $row['status']);
        $this->assertSame(COMPLETED, $this->saleStatus($sale));
        $this->assertSame($this->cashupId, (int) $this->db->table('sales')->where('sale_id', $sale)->get()->getRow()->cashup_id, 'Sealed with the shift of the delivery.');
        $this->assertSame([[$this->unitItem, '2.000', '7.00'], [$this->weightItem, '1.500', '18.00']], $this->saleLines($sale));
        $this->assertSame([['presale', '41.00', '0.00']], $this->salePayments($sale), 'One presale payment, for what was paid.');
        $this->assertSame('98.000', $this->stock($this->unitItem));
        $this->assertSame('98.500', $this->stock($this->weightItem));
        $this->assertSame(0, (int) ($_SESSION['sales_presale_id'] ?? 0), 'The register forgot the delivery.');
    }

    public function testAPresaleWithABalanceIsNotSentToTheRegister(): void
    {
        $presale = $this->makePaidPresale('20.00');

        $this->postReq('sales/deliverPresale/' . $presale, [])->assertRedirect();

        $this->assertNull(model(Presale::class)->get_info($presale)['sale_id']);
        $this->assertSame([], $_SESSION['sales_cart'] ?? []);
        $this->assertSame(0, (int) ($_SESSION['sales_presale_id'] ?? 0));
    }

    public function testWithTheModuleOffTheEndpointIsRefused(): void
    {
        $presale = $this->makePaidPresale();
        $this->setConfig('presales_enable', '0');

        $this->postReq('sales/deliverPresale/' . $presale, [])->assertRedirect();

        $this->assertNull(model(Presale::class)->get_info($presale)['sale_id']);
        $this->assertSame([], $_SESSION['sales_cart'] ?? []);
    }

    public function testWithoutThePresalesPermissionTheEndpointIsRefused(): void
    {
        if (! $this->grantedHere) {
            $this->markTestSkipped('Person 1 already held the presales permission in this database.');
        }

        $presale = $this->makePaidPresale();
        $this->db->table('grants')->where(['permission_id' => 'presales', 'person_id' => 1])->delete();

        $this->postReq('sales/deliverPresale/' . $presale, [])->assertRedirect();

        $this->assertNull(model(Presale::class)->get_info($presale)['sale_id']);
    }

    /**
     * A sale the cashier is ringing up and nothing has saved is never thrown away for a delivery.
     */
    public function testACartInProgressIsNotThrownAwayForADelivery(): void
    {
        $this->setConfig('dinner_table_enable', '0');
        $this->loginAsCashier();
        $presale = $this->makePaidPresale();

        $this->postReq('sales/add', ['item' => 'ID ' . $this->otherItemForCart()]);
        $before = $this->cartSignature();
        $this->assertCount(1, $before);

        $this->postReq('sales/deliverPresale/' . $presale, [])->assertRedirect();

        $this->assertSame($before, $this->cartSignature());
        $this->assertSame(0, (int) ($_SESSION['sales_presale_id'] ?? 0));
    }

    /**
     * Two completions of the same delivery -- a double tap, or two tills -- produce one sale.
     */
    public function testCompletingTheSameDeliveryTwiceProducesOneSale(): void
    {
        $presale = $this->makePaidPresale();
        $this->deliver($presale);
        $snapshot = $_SESSION;

        $this->postReq('sales/complete', [])->assertStatus(200);

        $_SESSION = $snapshot;
        $second   = $this->postReq('sales/complete', []);

        $second->assertSee(esc(lang('Presale_register.delivery_failed')));
        $this->assertSame(1, $this->completedSalesOfCustomer());
        $this->assertSame('98.000', $this->stock($this->unitItem), 'Stock went down once.');
        $this->assertCount(1, $this->salePayments((int) model(Presale::class)->get_info($presale)['sale_id']));
    }

    // ---------------------------------------------------------------------------------------------
    // Tables off
    // ---------------------------------------------------------------------------------------------

    /**
     * Without Tables there is no tab: the delivery is the register's cart, and completes the same way.
     */
    public function testWithTablesOffTheDeliveryIsTheCartAndCompletes(): void
    {
        $this->setConfig('dinner_table_enable', '0');
        $this->loginAsCashier();
        $presale = $this->makePaidPresale();

        $this->postReq('sales/deliverPresale/' . $presale, [])->assertRedirectTo(site_url('sales'));
        $this->assertDeliveryBanner($this->getReq('sales'), $presale);

        $this->assertNull(model(Presale::class)->get_info($presale)['sale_id'], 'Nothing is written before completion.');
        $this->assertCount(2, $this->cartSignature());

        $this->postReq('sales/complete', [])->assertStatus(200);

        $row  = model(Presale::class)->get_info($presale);
        $sale = (int) $row['sale_id'];
        $this->assertSame(Presale::STATUS_DELIVERED, $row['status']);
        $this->assertSame(COMPLETED, $this->saleStatus($sale));
        $this->assertNull($this->db->table('sales')->where('sale_id', $sale)->get()->getRow()->dinner_table_id);
        $this->assertSame([['presale', '41.00', '0.00']], $this->salePayments($sale));
        $this->assertSame('98.000', $this->stock($this->unitItem));
    }

    public function testWithTablesOffCompletingTwiceProducesOneSale(): void
    {
        $this->setConfig('dinner_table_enable', '0');
        $this->loginAsCashier();
        $presale = $this->makePaidPresale();
        $this->deliver($presale);
        $snapshot = $_SESSION;

        $this->postReq('sales/complete', [])->assertStatus(200);

        $_SESSION = $snapshot;
        $this->postReq('sales/complete', [])->assertSee(esc(lang('Presale_register.delivery_failed')));

        $this->assertSame(1, $this->completedSalesOfCustomer());
        $this->assertSame('98.000', $this->stock($this->unitItem));
    }

    public function testWithTablesOffTheGuardsHold(): void
    {
        $this->setConfig('dinner_table_enable', '0');
        $this->loginAsCashier();
        $this->deliver($this->makePaidPresale());
        $before = $this->cartSignature();

        $this->postReq('sales/add', ['item' => 'ID ' . $this->otherItemForCart()])->assertSee(esc(lang('Presale_register.locked')));
        $this->postReq('sales/suspend', [])->assertSee(esc(lang('Presale_register.locked')));
        $this->postReq('sales/cancel', [])->assertSee(esc(lang('Presale_register.locked')));

        $this->assertSame($before, $this->cartSignature());
        $this->assertSame(0, $this->db->table('sales')->where('customer_id', $this->customerId)->countAllResults(), 'Nothing was suspended or saved.');
    }

    // ---------------------------------------------------------------------------------------------
    // Weight (T18)
    // ---------------------------------------------------------------------------------------------

    public function testOnlyTheWeightOfALineSoldByWeightChangesAndAtTheAgreedPrice(): void
    {
        $this->deliver($this->makePaidPresale());

        $this->postReq('sales/editItem/2', ['quantity' => '2.000', 'price' => '1.00', 'discount' => '50', 'discount_type' => '0', 'description' => 'x', 'serialnumber' => '']);
        $this->postReq('sales/editItem/1', ['quantity' => '5', 'price' => '1.00', 'discount' => '0', 'description' => '', 'serialnumber' => ''])
            ->assertSee(esc(lang('Presale_register.weight_only')));

        $this->assertSame([
            1 => [$this->unitItem, '2.000', '7.00', '0.00'],
            2 => [$this->weightItem, '2.000', '18.00', '0.00'],
        ], $this->cartSignature());
        $this->assertSame([lang('Sales.presale') => '41.00'], $this->sessionPayments(), 'The presale payment survives the edit.');
    }

    public function testAHeavierWeightNeedsTheDifferencePaidBeforeCompleting(): void
    {
        $presale = $this->makePaidPresale();
        $this->deliver($presale);
        $this->postReq('sales/editItem/2', ['quantity' => '2.000', 'price' => '18.00', 'discount' => '0', 'description' => '', 'serialnumber' => '']);

        // 2 x 7 + 2 x 18 = 50, of which 41 were paid.
        $this->postReq('sales/complete', [])->assertSee(esc(lang('Presale_register.not_covered')));
        $this->assertSame(Presale::STATUS_OPEN, model(Presale::class)->get_info($presale)['status']);

        $this->postReq('sales/addPayment', ['payment_type' => lang('Sales.cash'), 'amount_tendered' => '9.00']);
        $this->postReq('sales/complete', [])->assertStatus(200);

        $sale = (int) model(Presale::class)->get_info($presale)['sale_id'];
        $this->assertSame(COMPLETED, $this->saleStatus($sale));
        $this->assertSame([['cash', '9.00', '0.00'], ['presale', '41.00', '0.00']], $this->salePayments($sale));
        $this->assertSame('98.000', $this->stock($this->weightItem));
    }

    public function testALighterWeightGivesCashChangeInTheDeliveryShift(): void
    {
        $presale = $this->makePaidPresale();
        $this->deliver($presale);
        $this->postReq('sales/editItem/2', ['quantity' => '1.000', 'price' => '18.00', 'discount' => '0', 'description' => '', 'serialnumber' => '']);

        // 2 x 7 + 1 x 18 = 32, and 41 were paid: 9 back in cash.
        $this->postReq('sales/complete', [])->assertStatus(200);

        $sale = (int) model(Presale::class)->get_info($presale)['sale_id'];
        $this->assertSame(COMPLETED, $this->saleStatus($sale));
        $this->assertSame([['cash', '0.00', '9.00'], ['presale', '41.00', '0.00']], $this->salePayments($sale));
        $this->assertSame($this->cashupId, (int) $this->db->table('sales')->where('sale_id', $sale)->get()->getRow()->cashup_id);

        // Cash went back across the counter: the real weight is on the presale's record.
        $event = $this->db->table('presale_events')->where(['presale_id' => $presale, 'event_type' => 'quantity_adjusted'])->get()->getRowArray();
        $this->assertNotNull($event);
        $this->assertSame(
            ['sale_id' => $sale, 'lines' => [['line' => 2, 'item_id' => $this->weightItem, 'agreed' => '1.500', 'delivered' => '1.000']]],
            json_decode((string) $event['detail'], true),
        );
    }

    // ---------------------------------------------------------------------------------------------
    // Getting a delivery off the register without delivering it
    // ---------------------------------------------------------------------------------------------

    /**
     * The wrong presale was sent, or the customer did not come: «Devolver a preventas» takes the
     * delivery off the register and leaves the presale open and paid, ready to be sent again.
     */
    public function testReleasingADeliveryTabLeavesThePresaleOpenAndPaid(): void
    {
        $presale = $this->makePaidPresale();
        $this->deliver($presale);
        $sale = (int) model(Presale::class)->get_info($presale)['sale_id'];

        $this->postReq('sales/releasePresale', [])
            ->assertSee(esc(lang('Presale_register.released', [model(Presale::class)->number($presale)])));

        $row = model(Presale::class)->get_info($presale);
        $this->assertSame(Presale::STATUS_OPEN, $row['status']);
        $this->assertNull($row['sale_id'], 'Unlinked from the tab.');
        $this->assertSame(CANCELED, $this->saleStatus($sale), 'The open tab is gone.');
        $this->assertSame([], $this->cartSignature());
        $this->assertSame('100.000', $this->stock($this->unitItem));

        // And it can be sent again.
        $this->deliver($presale);
        $this->assertNotSame($sale, (int) model(Presale::class)->get_info($presale)['sale_id']);
    }

    public function testWithTablesOffReleasingADeliveryFreesTheRegister(): void
    {
        $this->setConfig('dinner_table_enable', '0');
        $this->loginAsCashier();
        $presale = $this->makePaidPresale();
        $this->deliver($presale);

        $this->postReq('sales/releasePresale', []);

        $this->assertSame([], $this->cartSignature());
        $this->assertSame(0, (int) ($_SESSION['sales_presale_id'] ?? 0));
        $this->assertSame(Presale::STATUS_OPEN, model(Presale::class)->get_info($presale)['status']);
        $this->assertSame(0, $this->db->table('sales')->where('customer_id', $this->customerId)->countAllResults(), 'Nothing was ever written.');

        // The register is free again for an ordinary sale.
        $this->postReq('sales/add', ['item' => 'ID ' . $this->otherItemForCart()]);
        $this->assertCount(1, $this->cartSignature());
    }

    /**
     * On a cart that is not a delivery, releasing does nothing at all.
     */
    public function testReleasingOutsideADeliveryChangesNothing(): void
    {
        $this->setConfig('dinner_table_enable', '0');
        $this->loginAsCashier();
        $this->postReq('sales/add', ['item' => 'ID ' . $this->otherItemForCart()]);
        $before = $this->cartSignature();

        $this->postReq('sales/releasePresale', []);

        $this->assertSame($before, $this->cartSignature());
    }

    // ---------------------------------------------------------------------------------------------
    // Guards (§7.3)
    // ---------------------------------------------------------------------------------------------

    public function testEveryGuardRefusesOnADeliveryTab(): void
    {
        $presale = $this->makePaidPresale();
        $this->deliver($presale);
        $sale   = (int) model(Presale::class)->get_info($presale)['sale_id'];
        $cart   = $this->cartSignature();
        $locked = esc(lang('Presale_register.locked'));

        $this->postReq('sales/add', ['item' => 'ID ' . $this->otherItemForCart()])->assertSee($locked);
        $this->postReq('sales/addWeight', ['weight' => '1'])->assertSee($locked);
        $this->getReq('sales/deleteItem/1')->assertSee($locked);
        $this->postReq('sales/selectCustomer', ['customer' => (string) $this->otherCustomerId])->assertSee($locked);
        $this->getReq('sales/removeCustomer')->assertSee($locked);
        $this->postReq('sales/suspend', [])->assertSee($locked);
        $this->postReq('sales/cancel', [])->assertSee($locked);
        $this->getReq('sales/deletePayment/' . $this->base64url(lang('Sales.presale')))->assertSee($locked);
        $this->postReq('sales/addPayment', ['payment_type' => lang('Sales.presale'), 'amount_tendered' => '5.00'])
            ->assertSee(esc(lang('Presale_register.presale_payment_refused')));

        $renamed = $this->postReq('sales/changeItemName', ['item_id' => (string) $this->unitItem, 'item_name' => 'Cambiado']);
        $this->assertFalse(json_decode((string) $renamed->getJSON(), true)['success']);
        $this->assertSame('PDREG-TEST unidad', $this->db->table('items')->where('item_id', $this->unitItem)->get()->getRow()->name);

        $this->postReq('sales/changeMode', ['mode' => 'return', 'dinner_table' => (string) $_SESSION['dinner_table']]);
        $this->assertSame('sale', $_SESSION['sales_mode'], 'A delivery stays a sale.');

        $this->assertSame($cart, $this->cartSignature());
        $this->assertSame($this->customerId, (int) $_SESSION['sales_customer']);
        $this->assertSame([lang('Sales.presale') => '41.00'], $this->sessionPayments());
        $this->assertSame(OPENED, $this->saleStatus($sale), 'Neither suspended nor cancelled.');
        $this->assertSame($sale, (int) model(Presale::class)->get_info($presale)['sale_id']);
    }

    /**
     * The same actions on a cart that is not a delivery go through exactly as before -- except a
     * presale payment typed by hand, which no cart takes.
     */
    public function testTheGuardsLeaveEveryOtherCartAlone(): void
    {
        $this->setConfig('dinner_table_enable', '0');
        $this->loginAsCashier();
        $other = $this->otherItemForCart();

        $this->postReq('sales/add', ['item' => 'ID ' . $other])->assertDontSee(esc(lang('Presale_register.locked')));
        $this->assertCount(1, $this->cartSignature());

        $this->postReq('sales/selectCustomer', ['customer' => (string) $this->otherCustomerId]);
        $this->assertSame($this->otherCustomerId, (int) $_SESSION['sales_customer']);

        $this->postReq('sales/addPayment', ['payment_type' => lang('Sales.presale'), 'amount_tendered' => '5.00'])
            ->assertSee(esc(lang('Presale_register.presale_payment_refused')));
        $this->assertSame([], $this->sessionPayments());

        $this->getReq('sales/deleteItem/1');
        $this->assertSame([], $this->cartSignature());
    }

    // ---------------------------------------------------------------------------------------------
    // The tab survives
    // ---------------------------------------------------------------------------------------------

    /**
     * Switching to another tab and back, and a brand new session on the same tab: the database link
     * keeps it a delivery, with its payment.
     */
    public function testTheDeliveryTabSurvivesSwitchingTabsAndANewSession(): void
    {
        $presale = $this->makePaidPresale();
        $this->deliver($presale);
        $deliveryTable = (int) $_SESSION['dinner_table'];

        $this->db->table('dinner_tables')->insert(['name' => 'PDREG otra', 'status' => 0, 'deleted' => 0]);
        $otherTable            = (int) $this->db->insertID();
        $this->tablesCreated[] = $otherTable;

        $this->postReq('sales/changeMode', ['mode' => 'sale', 'dinner_table' => (string) $otherTable]);
        $this->assertSame([], $this->cartSignature());
        $this->assertSame(0, (int) ($_SESSION['sales_presale_id'] ?? 0));

        $this->assertDeliveryBanner($this->postReq('sales/changeMode', ['mode' => 'sale', 'dinner_table' => (string) $deliveryTable]), $presale);
        $this->assertSame([lang('Sales.presale') => '41.00'], $this->sessionPayments());

        $this->loginAsCashier();
        $this->postReq('sales/changeMode', ['mode' => 'sale', 'dinner_table' => (string) $deliveryTable]);
        $this->postReq('sales/add', ['item' => 'ID ' . $this->otherItemForCart()])->assertSee(esc(lang('Presale_register.locked')));
        $this->assertSame([lang('Sales.presale') => '41.00'], $this->sessionPayments());
    }

    /**
     * A presale cancelled while its tab is open: the tab goes, its open sale is cancelled and the
     * presale is unlinked from it.
     *
     * Since lane E, Presale::cancel() refuses a presale whose tab is open (PresalesCancelTest), so the
     * cancellation is written here directly: this pins the register's fallback for a presale that
     * got cancelled by any other path.
     */
    public function testAPresaleCancelledWhileItsTabIsOpenLeavesTheRegister(): void
    {
        $presale = $this->makePaidPresale();
        $this->deliver($presale);
        $sale = (int) model(Presale::class)->get_info($presale)['sale_id'];

        $this->assertSame('Presales.cancel_open_in_register', model(Presale::class)->cancel($presale, 1, 'El cliente desistió'));
        $this->db->table('presales')->where('presale_id', $presale)->update(['status' => Presale::STATUS_CANCELED, 'canceled_at' => date('Y-m-d H:i:s'), 'canceled_by' => 1]);

        $this->getReq('sales')->assertSee(esc(lang('Presale_register.no_longer_open', [model(Presale::class)->number($presale)])));

        $this->assertSame(CANCELED, $this->saleStatus($sale));
        $this->assertNull(model(Presale::class)->get_info($presale)['sale_id']);
        $this->assertSame([], $this->cartSignature());
    }

    /**
     * Pressing "Entregar" again goes to the tab that already exists instead of opening a second one.
     */
    public function testDeliveringTwiceGoesToTheSameTab(): void
    {
        $presale = $this->makePaidPresale();
        $this->deliver($presale);
        $sale = (int) model(Presale::class)->get_info($presale)['sale_id'];

        $this->loginAsCashier();
        $this->postReq('sales/deliverPresale/' . $presale, [])->assertRedirectTo(site_url('sales'));

        $this->assertSame($sale, (int) $_SESSION['sale_id']);
        $this->assertSame($sale, (int) model(Presale::class)->get_info($presale)['sale_id']);
        $this->assertSame(1, $this->db->table('sales')->where('customer_id', $this->customerId)->countAllResults());
    }

    // ---------------------------------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------------------------------

    /**
     * 2 units at 7.00 (catalogue 10.00) + 1.5 kg at 18.00/kg (catalogue 20.00) = 41.00.
     */
    private function makePaidPresale(string $paid = '41.00'): int
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
            'total'            => '41.00',
            'comment'          => null,
        ]);
        $presale = (int) $this->db->insertID();

        $line = 0;

        foreach ([[$this->unitItem, '2', '7.00'], [$this->weightItem, '1.5', '18.00']] as [$item, $quantity, $price]) {
            $this->db->table('presale_items')->insert([
                'presale_id'    => $presale,
                'line'          => ++$line,
                'item_id'       => $item,
                'description'   => null,
                'quantity'      => $quantity,
                'unit_price'    => $price,
                'discount'      => '0',
                'discount_type' => 0,
                'print_option'  => PRINT_YES,
                'item_type'     => ITEM,
            ]);
        }

        $this->db->table('presale_payments')->insert([
            'presale_id'        => $presale,
            'kind'              => 'payment',
            'payment_type_code' => 'cash',
            'amount'            => $paid,
            'payment_time'      => date('Y-m-d H:i:s'),
            'employee_id'       => 1,
            'cashup_id'         => $this->cashupId,
        ]);

        return $presale;
    }

    private function deliver(int $presale): void
    {
        $this->postReq('sales/deliverPresale/' . $presale, [])->assertRedirectTo(site_url('sales'));
        $this->assertDeliveryBanner($this->getReq('sales'), $presale);
    }

    /**
     * The banner is looked for by its element and the presale number, not by its full text: the
     * sentence carries a dash that the DOM parser of the test response re-encodes.
     */
    private function assertDeliveryBanner(TestResponse $response, int $presale): void
    {
        $response->assertSeeElement('#presale_delivery_banner');
        $response->assertSee(model(Presale::class)->number($presale), 'strong');
    }

    private function otherItemForCart(): int
    {
        return $this->unitItem;
    }

    private function makeCustomer(string $lastName): int
    {
        $this->db->table('people')->insert([
            'first_name'   => 'José',
            'last_name'    => $lastName,
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

        // A standing 10% discount: it must NOT reach the agreed prices.
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

    private function openShift(): int
    {
        $this->db->table('cash_up')->insert([
            'status'               => 'open',
            'open_date'            => '2099-06-01 08:00:00',
            'close_date'           => null,
            'open_amount_cash'     => '0.00',
            'transfer_amount_cash' => '0.00',
            'note'                 => 0,
            'closed_amount_cash'   => '0.00',
            'closed_amount_card'   => '0.00',
            'closed_amount_check'  => '0.00',
            'closed_amount_due'    => '0.00',
            'closed_amount_total'  => '0.00',
            'description'          => 'PresaleDeliveryRegisterTest fixture',
            'open_employee_id'     => 1,
            'close_employee_id'    => 1,
            'deleted'              => 0,
        ]);

        return (int) $this->db->insertID();
    }

    private function makeItem(string $name, string $price, string $unit): int
    {
        $this->db->table('items')->insert([
            'name'                  => $name,
            'category'              => 'Test',
            'item_number'           => null,
            'description'           => '',
            'cost_price'            => '1.00',
            'unit_price'            => $price,
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

    private function setConfig(string $key, string $value): void
    {
        $this->db->table('app_config')->replace(['key' => $key, 'value' => $value]);
        config(OSPOS::class)->update_settings();
    }

    /**
     * @return array<int, array{0: int, 1: string, 2: string, 3: string}> line => [item, quantity, price, discount]
     */
    private function cartSignature(): array
    {
        $signature = [];

        foreach ($_SESSION['sales_cart'] ?? [] as $line => $item) {
            $signature[(int) $line] = [
                (int) $item['item_id'],
                bcadd((string) $item['quantity'], '0', 3),
                bcadd((string) $item['price'], '0', 2),
                bcadd((string) $item['discount'], '0', 2),
            ];
        }

        ksort($signature);

        return $signature;
    }

    /**
     * @return array<string, string> label => amount
     */
    private function sessionPayments(): array
    {
        $payments = [];

        foreach ($_SESSION['sales_payments'] ?? [] as $label => $payment) {
            $payments[(string) $label] = bcadd((string) $payment['payment_amount'], '0', 2);
        }

        return $payments;
    }

    /**
     * @return list<array{0: int, 1: string, 2: string}> [item, quantity, unit price] by line
     */
    private function saleLines(int $sale): array
    {
        $rows = $this->db->table('sales_items')->where('sale_id', $sale)->orderBy('line', 'asc')->get()->getResultArray();

        return array_map(static fn (array $row): array => [
            (int) $row['item_id'],
            bcadd((string) $row['quantity_purchased'], '0', 3),
            bcadd((string) $row['item_unit_price'], '0', 2),
        ], $rows);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}> [code, amount, cash refund], by code
     */
    private function salePayments(int $sale): array
    {
        $rows = $this->db->table('sales_payments')->where('sale_id', $sale)->orderBy('payment_type_code', 'asc')->get()->getResultArray();

        return array_map(static fn (array $row): array => [
            (string) $row['payment_type_code'],
            bcadd((string) $row['payment_amount'], '0', 2),
            bcadd((string) $row['cash_refund'], '0', 2),
        ], $rows);
    }

    private function saleStatus(int $sale): int
    {
        return (int) $this->db->table('sales')->where('sale_id', $sale)->get()->getRow()->sale_status;
    }

    private function completedSalesOfCustomer(): int
    {
        return $this->db->table('sales')->where(['customer_id' => $this->customerId, 'sale_status' => COMPLETED])->countAllResults();
    }

    private function stock(int $item): string
    {
        return bcadd((string) $this->db->table('item_quantities')->where(['item_id' => $item, 'location_id' => 1])->get()->getRow()->quantity, '0', 3);
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function loginAsCashier(): void
    {
        $_SESSION = ['person_id' => 1, 'menu_group' => 'home'];
        $this->withSession($_SESSION);

        $this->getReq('sales');
    }

    private function getReq(string $path): TestResponse
    {
        $this->withSession($_SESSION);

        return $this->get($path);
    }

    /**
     * @param array<string, string> $params
     */
    private function postReq(string $path, array $params): TestResponse
    {
        $this->withSession($_SESSION);

        return $this->post($path, $params);
    }
}
