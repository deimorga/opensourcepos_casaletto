<?php

namespace Tests\Controllers;

use App\Models\Appconfig;
use App\Models\Dinner_table;
use App\Models\Order_ticket;
use App\Models\Order_ticket_line;
use App\Models\Order_ticket_round;
use App\Models\Sale;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\OSPOS;

/**
 * Order tickets against the REAL register: the money path.
 *
 * Every promise the design makes about the till is proved here through Sales itself, not through the
 * models:
 *
 * - the till pulls the waiter's dishes into the sale, and stamps them only once they are saved;
 * - a cashier's own edits never delete a waiter's dish (the phone never writes sales_items);
 * - a kit arrives as the register would add it, components included;
 * - the till never charges a total the cashier has not seen;
 * - charging closes the ticket; cancelling the tab cancels the ticket;
 * - a ticket cancelled from a phone is dropped from the till, never resurrected by its stale cart;
 * - a dish the waiter changes after billing is shown, not silently applied.
 *
 * Built like SalesControllerTest and SalesKitControllerTest ($refresh = true, and getReq()/postReq()
 * re-arming the session from the LIVE $_SESSION): the register keeps its cart in the session, so each
 * request has to find what the previous one left.
 *
 * @internal
 */
final class OrderTicketsRegisterTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private int $itemA;
    private int $itemB;
    private int $itemC;
    private string $numberB = 'OT-REG-ITEM-B';

    protected function setUp(): void
    {
        parent::setUp();

        // See SalesControllerTest::setUp(): a stale table list makes Config\OSPOS fall back to
        // defaults that crash Email_lib's constructor.
        db_connect()->resetDataCache();

        model(Appconfig::class)->save(['dinner_table_enable' => '1']);
        config(OSPOS::class)->update_settings();

        foreach (['order_tickets', 'order_ticket_lines', 'order_ticket_rounds'] as $table) {
            $this->db->table($table)->truncate();
        }

        $this->itemA = $this->createItem('OT-REG-ITEM-A', 'OT Plato A');
        $this->itemB = $this->createItem($this->numberB, 'OT Plato B');
        $this->itemC = $this->createItem('OT-REG-ITEM-C', 'OT Plato C');

        $this->loginAsCashier();
    }

    // ---------------------------------------------------------------------------------------------

    /**
     * The cashier taps the ticket's tab: its dishes are in the sale, and only then stamped billed.
     */
    public function testOpeningATicketsTabBringsItsDishesIntoTheSaleAndStampsThem(): void
    {
        [$ticket, $table, $sale] = $this->openTicket('ANDREA', [[$this->itemA, '2'], [$this->itemB, '1']]);

        $response = $this->openTab($table);

        $response->assertStatus(200);
        $this->assertSame(['OT Plato A' => '2.000', 'OT Plato B' => '1.000'], $this->saleItems($sale));
        $this->assertSame([], model(Order_ticket_line::class, false)->get_unbilled($ticket), 'Every dish in the sale is stamped.');
    }

    /**
     * THE TEST THIS DESIGN EXISTS FOR (docs/Tecnico §3.1, plan 1.21).
     *
     * The waiter's dishes were sent to the kitchen, the cashier has the tab open and adds an item
     * herself -- which rewrites sales_items from her cart -- and meanwhile the waiter adds one more.
     * Nothing is lost: the waiter's dishes are all in the sale, and the ticket's own lines are exactly
     * as they were, still sent. If someone ever moves the "sent" state into sales_items, this fails.
     */
    public function testTheCashierAddingAnItemDoesNotLoseTheTicketLines(): void
    {
        [$ticket, $table, $sale] = $this->openTicket('ANDREA', [[$this->itemA, '2']]);
        model(Order_ticket_round::class, false)->send($ticket, 1);
        $this->openTab($table);

        model(Order_ticket_line::class, false)->add_line($ticket, $this->itemC, 'OT Plato C', '1', '10', '', 1);
        $this->postReq('sales/add', ['item' => $this->numberB]);

        $items = $this->saleItems($sale);
        $this->assertArrayHasKey('OT Plato A', $items, 'The waiter\'s dish survived the cashier\'s own edit.');
        $this->assertArrayHasKey('OT Plato B', $items);
        $this->assertArrayHasKey('OT Plato C', $items, 'The dish added while the tab was open reached the sale.');

        $lines = model(Order_ticket_line::class, false)->get_lines($ticket);
        $this->assertSame('sent', $lines[0]['status'], 'The kitchen state lives on the ticket, untouched by the till.');
        $this->assertNotNull($lines[0]['round_id']);
        $this->assertSame([], model(Order_ticket_line::class, false)->get_unbilled($ticket));
    }

    /**
     * A kit arrives exactly as the register adds one: its representative item plus its components,
     * the components scaled by the kit quantity so the ingredients deducted are right.
     */
    public function testAKitArrivesAsTheRegisterWouldAddIt(): void
    {
        [, $kitItem] = $this->createKit('OT-REG-KIT', 'OT Combo', [[$this->itemA, '1'], [$this->itemC, '2']]);
        [, $table, $sale] = $this->openTicket('KIT', [[$kitItem, '2']]);

        $this->openTab($table);

        $items = $this->saleItems($sale);
        $this->assertSame('2.000', $items['OT Combo'] ?? null, 'The kit itself, as one dish.');
        $this->assertSame('2.000', $items['OT Plato A'] ?? null, 'Component x kit quantity.');
        $this->assertSame('4.000', $items['OT Plato C'] ?? null, 'Component x kit quantity.');
    }

    /**
     * Never charge a total the cashier has not seen: a dish that arrived after the screen was drawn
     * stops the charge, is brought in, and is said out loud.
     */
    public function testANewDishBeforeChargingStopsTheCharge(): void
    {
        [$ticket, $table, $sale] = $this->openTicket('ANDREA', [[$this->itemA, '1']]);
        $this->openTab($table);
        $this->postReq('sales/addPayment', ['payment_type' => 'Cash', 'amount_tendered' => '100.00']);

        // AFTER the last screen the cashier saw: addPayment itself redraws the register, and every
        // redraw already pulls. The dangerous window is exactly this one -- between the last redraw
        // and the tap on "complete".
        model(Order_ticket_line::class, false)->add_line($ticket, $this->itemB, 'OT Plato B', '1', '10', '', 1);

        $response = $this->postReq('sales/complete', []);

        $response->assertSee(lang('Order_tickets.register_changed_before_charge', ['ANDREA']));
        $this->assertSame(OPENED, $this->saleStatus($sale), 'Nothing was charged.');
        $this->assertArrayHasKey('OT Plato B', $this->saleItems($sale), 'The new dish is now in what will be charged.');
    }

    public function testChargingATicketsTabClosesTheTicket(): void
    {
        [$ticket, $table, $sale] = $this->openTicket('ANDREA', [[$this->itemA, '2']]);
        $this->openTab($table);

        $this->postReq('sales/addPayment', ['payment_type' => 'Cash', 'amount_tendered' => '100.00']);
        $this->postReq('sales/complete', [])->assertStatus(200);

        $this->assertSame(COMPLETED, $this->saleStatus($sale));
        $row = model(Order_ticket::class, false)->get_info($ticket);
        $this->assertSame(Order_ticket::STATUS_CHARGED, $row['status']);
        $this->assertNotNull($row['charged_at']);
    }

    /**
     * D15: the kitchen is optional. A business with the kitchen switched off, whose waiter never
     * presses "send to kitchen", still gets every dish to the till and charges it. The register pulls
     * what is UNBILLED, never what was SENT -- if anyone ever conditions the pull on round_id, a
     * business without a kitchen silently stops billing its waiters' orders, and this fails.
     *
     * Certified by hand on staging on 2026-09-23 as well (ticket «SIN COCINA», POS 990007).
     */
    public function testWithTheKitchenOffADishNeverSentIsBilledAndCharged(): void
    {
        $before = $this->db->table('app_config')->where('key', 'order_tickets_kitchen_enable')->get()->getRow()?->value;
        model(Appconfig::class)->save(['order_tickets_kitchen_enable' => '0']);
        config(OSPOS::class)->update_settings();

        try {
            [$ticket, $table, $sale] = $this->openTicket('SIN COCINA', [[$this->itemA, '2']]);

            $this->openTab($table);
            $this->postReq('sales/addPayment', ['payment_type' => 'Cash', 'amount_tendered' => '100.00']);
            $this->postReq('sales/complete', [])->assertStatus(200);

            $this->assertSame(COMPLETED, $this->saleStatus($sale));
            $this->assertSame(['OT Plato A' => '2.000'], $this->saleItems($sale));
            $this->assertSame(Order_ticket::STATUS_CHARGED, model(Order_ticket::class, false)->get_info($ticket)['status']);
            $this->assertSame(0, $this->db->table('order_ticket_rounds')->where('order_ticket_id', $ticket)->countAllResults(), 'Nothing ever went to a kitchen.');

            $line = $this->db->table('order_ticket_lines')->where('order_ticket_id', $ticket)->get()->getRowArray();
            $this->assertNull($line['round_id']);
            $this->assertSame(Order_ticket_line::STATUS_PENDING, $line['status']);
            $this->assertNotNull($line['billed_at'], 'Billed without ever being sent.');
        } finally {
            if ($before === null) {
                $this->db->table('app_config')->where('key', 'order_tickets_kitchen_enable')->delete();
            } else {
                $this->db->table('app_config')->replace(['key' => 'order_tickets_kitchen_enable', 'value' => $before]);
            }

            config(OSPOS::class)->update_settings();
        }
    }

    /**
     * D9 on the cashier's side: a dish the waiter changes after the till has it is LISTED, not
     * applied behind the cashier's back, until the cashier acknowledges it.
     */
    public function testAWaiterChangeAfterBillingIsListedNotAppliedUntilAcknowledged(): void
    {
        [$ticket, $table, $sale] = $this->openTicket('ANDREA', [[$this->itemA, '2']]);
        $this->openTab($table);
        $line = (int) model(Order_ticket_line::class, false)->get_lines($ticket)[0]['order_ticket_line_id'];

        model(Order_ticket_line::class, false)->edit_line($line, ['quantity' => '5']);
        $response = $this->getReq('sales');

        $response->assertSee(lang('Order_tickets.register_changes_title', ['ANDREA']));
        $this->assertSame('2.000', $this->saleItems($sale)['OT Plato A'], 'The cart is not changed behind the cashier.');

        $this->postReq('sales/acknowledgeOrderTicket', [])
            ->assertDontSee(lang('Order_tickets.register_changes_title', ['ANDREA']));
    }

    /**
     * Cancelled from a phone while this till had the tab open: the till's stale cart must not write
     * the sale back as OPENED. The tab is dropped and the cashier is told.
     */
    public function testATicketCancelledFromAPhoneIsDroppedNotResurrected(): void
    {
        [$ticket, $table, $sale] = $this->openTicket('ANDREA', [[$this->itemA, '1']]);
        $this->openTab($table);

        model(Order_ticket::class, false)->cancel($ticket, 1, 'el cliente se fue');
        model(Sale::class)->update_sale_status($sale, CANCELED);
        model(Dinner_table::class)->delete($table);

        $response = $this->postReq('sales/add', ['item' => $this->numberB]);

        $response->assertSee(lang('Order_tickets.register_cancelled', ['ANDREA']));
        $this->assertSame(CANCELED, $this->saleStatus($sale), 'The cancelled sale was not written back as OPENED.');
    }

    public function testCancellingTheTabFromTheTillCancelsTheTicket(): void
    {
        [$ticket, $table] = $this->openTicket('ANDREA', [[$this->itemA, '1']]);
        $this->openTab($table);

        $this->postReq('sales/cancel', []);

        $row = model(Order_ticket::class, false)->get_info($ticket);
        $this->assertSame(Order_ticket::STATUS_CANCELLED, $row['status']);
        $this->assertSame(lang('Order_tickets.cancelled_from_register'), $row['cancel_reason']);
    }

    /**
     * The tab carries the ticket's full name; the throwaway table only holds it cut to 30.
     */
    public function testTheTabShowsTheTicketsFullName(): void
    {
        $name = 'DOMICILIO JUAN CARLOS PEREZ CALLE 45';
        $this->openTicket($name, [[$this->itemA, '1']]);

        $this->getReq('sales')->assertSee($name);
    }

    /**
     * A sale that is nobody's ticket -- nearly all of them -- goes exactly as before.
     */
    public function testAnOrdinarySaleIsUntouched(): void
    {
        $this->postReq('sales/add', ['item' => $this->numberB]);
        $this->postReq('sales/addPayment', ['payment_type' => 'Cash', 'amount_tendered' => '100.00']);

        $this->postReq('sales/complete', [])->assertStatus(200);

        $this->assertSame(0, $this->db->table('order_tickets')->countAllResults());
    }

    // ---------------------------------------------------------------------------------------------

    /**
     * A ticket as the waiter's screen opens one -- ticket, throwaway table born occupied, OPENED sale
     * -- with its dishes. Returns [ticket_id, dinner_table_id, sale_id].
     *
     * @param list<array{0: int, 1: string}> $dishes [item_id, quantity]
     * @return array{0: int, 1: int, 2: int}
     */
    private function openTicket(string $name, array $dishes): array
    {
        $tickets = model(Order_ticket::class, false);
        $ticket  = $tickets->create_ticket($name, 1, 1);
        $table   = model(Dinner_table::class)->create_at(mb_substr($name, 0, 30), 1, true);
        $sale    = model(Sale::class)->create_open_sale(1, $table, 1);
        $tickets->attach_sale($ticket, $sale);

        $lines = model(Order_ticket_line::class, false);

        foreach ($dishes as [$item, $quantity]) {
            $row = $this->db->table('items')->where('item_id', $item)->get()->getRowArray();
            $lines->add_line($ticket, $item, $row['name'], $quantity, (string) $row['unit_price'], '', 1);
        }

        return [$ticket, $table, $sale];
    }

    /**
     * The cashier taps the tab, exactly as register.php's .open_tab_button does.
     */
    private function openTab(int $table): TestResponse
    {
        return $this->postReq('sales/changeMode', ['mode' => 'sale', 'dinner_table' => (string) $table]);
    }

    /**
     * @return array<string, string> item name => quantity as stored
     */
    private function saleItems(int $sale): array
    {
        $rows = $this->db->table('sales_items')
            ->select('items.name, sales_items.quantity_purchased')
            ->join('items', 'items.item_id = sales_items.item_id')
            ->where('sales_items.sale_id', $sale)
            ->get()->getResultArray();

        $items = [];

        foreach ($rows as $row) {
            $items[$row['name']] = (string) $row['quantity_purchased'];
        }

        ksort($items);

        return $items;
    }

    private function saleStatus(int $sale): int
    {
        return (int) $this->db->table('sales')->where('sale_id', $sale)->get()->getRow()->sale_status;
    }

    private function createItem(string $number, string $name, int $type = ITEM, int $stock = HAS_STOCK): int
    {
        $this->db->table('items')->insert([
            'name'                  => $name,
            'category'              => 'Test',
            'item_number'           => $number,
            'description'           => 'Fixture item for OrderTicketsRegisterTest',
            'cost_price'            => '1.00',
            'unit_price'            => '10.00',
            'reorder_level'         => '0',
            'receiving_quantity'    => '1',
            'allow_alt_description' => 0,
            'is_serialized'         => 0,
            'item_type'             => $type,
            'stock_type'            => $stock,
        ]);
        $id = (int) $this->db->insertID();

        if ($stock === HAS_STOCK) {
            $this->db->table('item_quantities')->insert(['item_id' => $id, 'location_id' => 1, 'quantity' => '1000']);
        }

        return $id;
    }

    /**
     * Same shape as SalesKitControllerTest::createItemKit(): the representative item plus the kit.
     *
     * @param list<array{0: int, 1: string}> $components [item_id, quantity]
     * @return array{0: int, 1: int} [item_kit_id, representative item_id]
     */
    private function createKit(string $number, string $name, array $components): array
    {
        $kitItem = $this->createItem($number, $name, ITEM_KIT, HAS_NO_STOCK);

        $this->db->table('item_kits')->insert([
            'item_kit_number'   => $number,
            'name'              => $name,
            'description'       => $name,
            'item_id'           => $kitItem,
            'kit_discount'      => '0.00',
            'kit_discount_type' => PERCENT,
            'price_option'      => PRICE_OPTION_ALL,
            'print_option'      => PRINT_ALL,
        ]);
        $kit = (int) $this->db->insertID();

        $sequence = 1;

        foreach ($components as [$item, $quantity]) {
            $this->db->table('item_kit_items')->insert([
                'item_kit_id'  => $kit,
                'item_id'      => $item,
                'quantity'     => $quantity,
                'kit_sequence' => $sequence++,
            ]);
        }

        return [$kit, $kitItem];
    }

    private function loginAsCashier(): void
    {
        // Seeded directly: on the very first request of the process the $_SESSION superglobal is
        // empty, and getReq() re-arms from it. See SalesControllerTest::loginAsAdmin().
        $_SESSION = ['person_id' => 1, 'menu_group' => 'home'];
        $this->withSession($_SESSION);

        $this->getReq('sales');
    }

    /**
     * Re-arms the session from the LIVE $_SESSION the previous request left, so the cart carries over.
     */
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
