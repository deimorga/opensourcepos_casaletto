<?php

namespace Tests\Controllers;

use App\Models\Order_ticket;
use App\Models\Order_ticket_line;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\OSPOS;

/**
 * The waiter's order-ticket screens, over real requests.
 *
 * SHARED STATE: the test database is shared between files. This one writes the order_tickets_enable
 * switch, grants order_tickets to the seeded administrator (person 1 -- no migration grants it, on
 * purpose), and fills the three ticket tables. setUp() records the switch and whether the grant
 * already existed; tearDown() puts both back and rebuilds the settings cache.
 *
 * SESSION: see SalesControllerTest::loginAsAdmin(). FeatureTestTrait overwrites $_SESSION on every
 * request, and an anonymous request makes Secure_Controller end the PHP process with a real exit()
 * -- the whole suite stops with no output and exit code 0. getReq() re-arms the session every time.
 *
 * @internal
 */
final class OrderTicketsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private const LOCATION_ID       = 1;
    private const OTHER_LOCATION_ID = 99;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private ?string $switchBefore = null;
    private ?string $tablesBefore = null;
    private bool $grantedHere     = false;
    private bool $voidGrantedHere = false;

    /** @var list<int> items this file created, removed in tearDown -- items is shared by every file */
    private array $createdItems = [];

    protected function setUp(): void
    {
        parent::setUp();

        // See SalesControllerTest::setUp(): without this, Config\OSPOS can see no app_config table
        // and fall back to defaults that crash a controller's constructor.
        $this->db->resetDataCache();

        $row                = $this->db->table('app_config')->where('key', 'order_tickets_enable')->get()->getRow();
        $this->switchBefore = $row === null ? null : (string) $row->value;
        $row                = $this->db->table('app_config')->where('key', 'dinner_table_enable')->get()->getRow();
        $this->tablesBefore = $row === null ? null : (string) $row->value;

        if ($this->db->table('grants')->where(['permission_id' => 'order_tickets', 'person_id' => 1])->countAllResults() === 0) {
            $this->db->table('grants')->insert(['permission_id' => 'order_tickets', 'person_id' => 1, 'menu_group' => 'home']);
            $this->grantedHere = true;
        }

        foreach (['order_tickets', 'order_ticket_lines', 'order_ticket_rounds'] as $table) {
            $this->db->table($table)->truncate();
        }
    }

    protected function tearDown(): void
    {
        $this->removeWhatOpeningTicketsCreated();

        if ($this->createdItems !== []) {
            $this->db->table('items')->whereIn('item_id', $this->createdItems)->delete();
        }

        if ($this->voidGrantedHere) {
            $this->db->table('grants')->where(['permission_id' => 'order_tickets_void', 'person_id' => 1])->delete();
        }

        if ($this->tablesBefore === null) {
            $this->db->table('app_config')->where('key', 'dinner_table_enable')->delete();
        } else {
            $this->db->table('app_config')->replace(['key' => 'dinner_table_enable', 'value' => $this->tablesBefore]);
        }

        if ($this->switchBefore === null) {
            $this->db->table('app_config')->where('key', 'order_tickets_enable')->delete();
        } else {
            $this->db->table('app_config')->replace(['key' => 'order_tickets_enable', 'value' => $this->switchBefore]);
        }

        if ($this->grantedHere) {
            $this->db->table('grants')->where(['permission_id' => 'order_tickets', 'person_id' => 1])->delete();
        }

        config(OSPOS::class)->update_settings();

        parent::tearDown();
    }

    /**
     * Off, the screen explains it is a setting, and shows none of the business's tickets.
     */
    public function testWithTheSwitchOffTheScreenSaysItIsTurnedOff(): void
    {
        $this->switchTo('0');
        $this->insertTicket('ANDREA', self::LOCATION_ID);

        $response = $this->getReq('comandas');

        $response->assertStatus(200);
        $response->assertSee(lang('Order_tickets.disabled'));
        $response->assertDontSee('ANDREA');
    }

    /**
     * An absent switch -- a settings cache older than the migration -- reads as off, never as on.
     */
    public function testAMissingSwitchReadsAsOff(): void
    {
        $this->db->table('app_config')->where('key', 'order_tickets_enable')->delete();
        config(OSPOS::class)->update_settings();

        $this->getReq('comandas')->assertSee(lang('Order_tickets.disabled'));
    }

    /**
     * The live tickets of THIS site only: a charged one, a cancelled one and another site's are
     * noise on a till that cannot act on them.
     */
    public function testTheListShowsOnlyTheLiveTicketsOfThisSite(): void
    {
        $this->switchTo('1');

        $this->insertTicket('ANDREA', self::LOCATION_ID);
        $this->insertTicket('ENTREGADA', self::LOCATION_ID, Order_ticket::STATUS_DELIVERED);
        $this->insertTicket('YA COBRADA', self::LOCATION_ID, Order_ticket::STATUS_CHARGED);
        $this->insertTicket('CANCELADA', self::LOCATION_ID, Order_ticket::STATUS_CANCELLED);
        $this->insertTicket('OTRA SEDE', self::OTHER_LOCATION_ID);

        $response = $this->getReq('comandas');

        $response->assertStatus(200);
        $response->assertSee('ANDREA');
        $response->assertSee('ENTREGADA');
        $response->assertDontSee('YA COBRADA');
        $response->assertDontSee('CANCELADA');
        $response->assertDontSee('OTRA SEDE');
    }

    /**
     * "Not sent" is what matters at a glance: a ticket with dishes pending is an order somebody is
     * still taking, or forgot to send.
     */
    public function testEachTicketSaysHowManyDishesAreNotSentYet(): void
    {
        $this->switchTo('1');

        $ticket = $this->insertTicket('ANDREA', self::LOCATION_ID);
        $lines  = model(Order_ticket_line::class, false);
        $lines->add_line($ticket, 7, 'Empanada', '1', '3500', '', 1);
        $lines->add_line($ticket, 8, 'Jugo', '2', '8000', '', 1);

        $response = $this->getReq('comandas');

        $response->assertSee(lang('Order_tickets.dishes', [2]));
        $response->assertSee(lang('Order_tickets.pending', [2]));
    }

    public function testTheScreenIsTheMobileLayoutNotTheRegisterHeader(): void
    {
        $this->switchTo('1');

        $html = $this->getReq('comandas')->getBody();

        $this->assertStringContainsString('viewport-fit=cover', $html);
        $this->assertStringContainsString('css/order_tickets.css', $html);
        $this->assertStringNotContainsString('resources/bootswatch/', $html, 'The POS Bootstrap 3 must never be mixed in.');
    }

    /**
     * The waiter's own logout, which works with the switch off too: turning the module off must never
     * lock anybody in.
     *
     * Only the redirect can be asserted. CodeIgniter's Session::destroy() returns early when
     * ENVIRONMENT is 'testing', so the session still holds person_id afterwards no matter what the
     * controller did; asserting on it would test the harness, not the code.
     */
    public function testLogoutGoesToLoginEvenWithTheSwitchOff(): void
    {
        $this->switchTo('0');

        $this->getReq('comandas/salir')->assertRedirectTo('login');
    }

    // ---------------------------------------------------------------------------------------------
    // Opening a ticket (1.14)
    // ---------------------------------------------------------------------------------------------

    /**
     * All three or none: the ticket, the throwaway table that puts it on the register's tab bar, and
     * the OPENED sale that will charge it -- linked to each other.
     */
    public function testOpeningATicketCreatesTheTicketItsTableAndItsOpenSaleTogether(): void
    {
        $this->switchTo('1');
        $this->tablesTo('1');

        $response = $this->postReq('comandas/crear', ['name' => 'ANDREA', 'note' => 'cumpleaños']);

        $ticket = $this->db->table('order_tickets')->get()->getRowArray();
        $this->assertNotNull($ticket);
        $response->assertRedirectTo('comandas/' . $ticket['order_ticket_id']);

        $this->assertSame('ANDREA', $ticket['name']);
        $this->assertSame('cumpleaños', $ticket['note']);
        $this->assertSame(Order_ticket::STATUS_OPEN, $ticket['status']);
        $this->assertSame(1, (int) $ticket['opened_by']);
        $this->assertNotNull($ticket['sale_id']);

        $sale = $this->db->table('sales')->where('sale_id', $ticket['sale_id'])->get()->getRowArray();
        $this->assertSame(OPENED, (int) $sale['sale_status'], 'An open account, exactly like a tab the cashier opened.');
        $this->assertSame(SALE_TYPE_POS, (int) $sale['sale_type']);
        $this->assertSame((int) $ticket['location_id'], (int) $sale['location_id']);

        $table = $this->db->table('dinner_tables')->where('dinner_table_id', $sale['dinner_table_id'])->get()->getRowArray();
        $this->assertSame('ANDREA', $table['name']);
        $this->assertSame(1, (int) $table['status'], 'Born occupied, or the register would offer it as a free table.');
        $this->assertGreaterThan(2, (int) $table['dinner_table_id'], 'Never Delivery or Take Away.');
    }

    /**
     * The table is a label cut to its column's 30 characters; the ticket is the record and keeps 64.
     */
    public function testTheTicketKeepsTheFullNameWhileTheTabLabelIsCut(): void
    {
        $this->switchTo('1');
        $this->tablesTo('1');
        $name = 'DOMICILIO JUAN CARLOS PEREZ CALLE 45 NUMERO 12';

        $this->postReq('comandas/crear', ['name' => $name]);

        $ticket = $this->db->table('order_tickets')->get()->getRowArray();
        $sale   = $this->db->table('sales')->where('sale_id', $ticket['sale_id'])->get()->getRowArray();
        $table  = $this->db->table('dinner_tables')->where('dinner_table_id', $sale['dinner_table_id'])->get()->getRowArray();

        $this->assertSame($name, $ticket['name']);
        $this->assertSame(mb_substr($name, 0, 30), $table['name']);
    }

    public function testABlankNameOpensNothing(): void
    {
        $this->switchTo('1');
        $this->tablesTo('1');
        $salesBefore = $this->db->table('sales')->countAllResults();

        $this->postReq('comandas/crear', ['name' => '   '])->assertRedirectTo('comandas/nueva');

        $this->assertSame(0, $this->db->table('order_tickets')->countAllResults());
        $this->assertSame($salesBefore, $this->db->table('sales')->countAllResults());
    }

    /**
     * The tab bar lives behind Tables. A ticket opened without it would be unreachable from the till,
     * so nothing is opened at all.
     */
    public function testWithTablesOffNothingIsOpened(): void
    {
        $this->switchTo('1');
        $this->tablesTo('0');

        $this->postReq('comandas/crear', ['name' => 'ANDREA'])->assertRedirectTo('comandas/nueva');

        $this->assertSame(0, $this->db->table('order_tickets')->countAllResults());
    }

    public function testWithTheSwitchOffOpeningIsNotPossible(): void
    {
        $this->switchTo('0');
        $this->tablesTo('1');

        $this->postReq('comandas/crear', ['name' => 'ANDREA'])->assertRedirectTo('comandas');

        $this->assertSame(0, $this->db->table('order_tickets')->countAllResults());
    }

    // ---------------------------------------------------------------------------------------------
    // The ticket screen (1.15 - 1.18)
    // ---------------------------------------------------------------------------------------------

    /**
     * The waiter can order items and kits (through the kit's representative item), and nothing that
     * needs a price typed at the till or no longer exists.
     */
    public function testTheSearchOffersItemsAndKitsOnly(): void
    {
        $ticket = $this->openLiveTicket();
        $this->createItem('PRUEBA OT EMPANADA');
        $this->createItem('PRUEBA OT SANDWICH KIT', ['item_type' => ITEM_KIT]);
        $this->createItem('PRUEBA OT MONTO LIBRE', ['item_type' => ITEM_AMOUNT_ENTRY]);
        $this->createItem('PRUEBA OT BORRADO', ['deleted' => 1]);

        $response = $this->getReq('comandas/' . $ticket . '?q=PRUEBA%20OT');

        $response->assertStatus(200);
        $response->assertSee('PRUEBA OT EMPANADA');
        $response->assertSee('PRUEBA OT SANDWICH KIT');
        $response->assertDontSee('PRUEBA OT MONTO LIBRE');
        $response->assertDontSee('PRUEBA OT BORRADO');
    }

    /**
     * Name and price come from the catalogue, read by the server. A form can be edited, and a price
     * typed into it must never reach the ticket.
     */
    public function testAddingADishCopiesNameAndPriceFromTheCatalogueNotFromTheForm(): void
    {
        $ticket = $this->openLiveTicket();
        $item   = $this->createItem('PRUEBA OT CAFE', ['unit_price' => '4500.00']);

        $this->postReq('comandas/' . $ticket . '/linea', [
            'item_id'      => (string) $item,
            'quantity'     => '2',
            'kitchen_note' => 'sin azúcar',
            'unit_price'   => '1',
            'item_name'    => 'FALSO',
        ])->assertRedirectTo('comandas/' . $ticket);

        $line = $this->db->table('order_ticket_lines')->where('order_ticket_id', $ticket)->get()->getRowArray();
        $this->assertSame('PRUEBA OT CAFE', $line['item_name']);
        $this->assertSame('4500.00', (string) $line['unit_price']);
        $this->assertSame('2.000', (string) $line['quantity']);
        $this->assertSame('sin azúcar', $line['kitchen_note']);
    }

    /**
     * After adding a dish the waiter comes back to the SAME search, scrolled to the results: three
     * sandwiches of different kinds should not mean typing the search three times on a phone.
     */
    public function testAddingADishComesBackToTheSameSearch(): void
    {
        $ticket = $this->openLiveTicket();
        $item   = $this->createItem('PRUEBA OT SANDWICH UNO');

        $response = $this->postReq('comandas/' . $ticket . '/linea', ['item_id' => (string) $item, 'quantity' => '1', 'q' => 'PRUEBA OT SANDWICH']);

        $this->assertStringContainsString('comandas/' . $ticket . '?q=PRUEBA%20OT%20SANDWICH#ot-results', (string) $response->getRedirectUrl());
    }

    /**
     * A phone keyboard in a comma-decimal locale sends "0,5". Half a portion is still half.
     */
    public function testACommaDecimalQuantityIsUnderstood(): void
    {
        $ticket = $this->openLiveTicket();
        $item   = $this->createItem('PRUEBA OT QUESO');

        $this->postReq('comandas/' . $ticket . '/linea', ['item_id' => (string) $item, 'quantity' => '0,5']);

        $this->assertSame('0.500', (string) $this->db->table('order_ticket_lines')->get()->getRow()->quantity);
    }

    public function testAClosedTicketAcceptsNoMoreDishes(): void
    {
        $ticket = $this->insertTicket('YA COBRADA', self::LOCATION_ID, Order_ticket::STATUS_CHARGED);
        $this->switchTo('1');
        $item = $this->createItem('PRUEBA OT POSTRE');

        $this->postReq('comandas/' . $ticket . '/linea', ['item_id' => (string) $item, 'quantity' => '1']);

        $this->assertSame(0, $this->db->table('order_ticket_lines')->countAllResults());
    }

    /**
     * Both ids come from the URL. A line must not be editable through another ticket's address.
     */
    public function testALineCannotBeEditedThroughAnotherTicketsAddress(): void
    {
        $mine   = $this->openLiveTicket('MIA');
        $theirs = $this->insertTicket('AJENA', self::LOCATION_ID);
        $line   = model(Order_ticket_line::class, false)->add_line($mine, 7, 'Empanada', '1', '3500', '', 1);

        $this->postReq('comandas/' . $theirs . '/linea/' . $line, ['quantity' => '9', 'kitchen_note' => '']);
        $this->postReq('comandas/' . $theirs . '/linea/' . $line . '/anular', []);

        $row = $this->db->table('order_ticket_lines')->where('order_ticket_line_id', $line)->get()->getRowArray();
        $this->assertSame('1.000', (string) $row['quantity']);
        $this->assertSame('pending', $row['status']);
    }

    /**
     * D9 from the screen: editing a dish the kitchen already has is allowed, and the dish is marked.
     */
    public function testEditingADishAlreadyInTheKitchenIsAllowedAndMarked(): void
    {
        $ticket = $this->openLiveTicket();
        $line   = model(Order_ticket_line::class, false)->add_line($ticket, 7, 'Empanada', '1', '3500', '', 1);
        $this->postReq('comandas/' . $ticket . '/enviar', []);

        $this->postReq('comandas/' . $ticket . '/linea/' . $line, ['quantity' => '2', 'kitchen_note' => ''])
            ->assertRedirectTo('comandas/' . $ticket);

        $row = $this->db->table('order_ticket_lines')->where('order_ticket_line_id', $line)->get()->getRowArray();
        $this->assertSame('2.000', (string) $row['quantity']);
        $this->assertSame(1, (int) $row['changed_after_send']);
    }

    /**
     * The double tap, through the real endpoint: one round, not two.
     */
    public function testSendingTwiceFromTheScreenCreatesOneRound(): void
    {
        $ticket = $this->openLiveTicket();
        model(Order_ticket_line::class, false)->add_line($ticket, 7, 'Empanada', '1', '3500', '', 1);

        $this->postReq('comandas/' . $ticket . '/enviar', []);
        $this->postReq('comandas/' . $ticket . '/enviar', []);

        $this->assertSame(1, $this->db->table('order_ticket_rounds')->where('order_ticket_id', $ticket)->countAllResults());
    }

    /**
     * The kitchen's sheet: this round's dishes with the note, never the line description where
     * "Unidad: kilogramo" lives. Printing marks it; merely viewing does not, and does not print.
     */
    public function testTheRoundSheetPrintsOnlyWhenAskedAndNeverShowsTheDescription(): void
    {
        $ticket = $this->openLiveTicket();
        $item   = $this->createItem('PRUEBA OT CHORIZO', ['description' => 'Unidad: kilogramo']);
        $this->postReq('comandas/' . $ticket . '/linea', ['item_id' => (string) $item, 'quantity' => '1', 'kitchen_note' => 'bien asado']);
        $this->postReq('comandas/' . $ticket . '/enviar', []);
        $round = (int) $this->db->table('order_ticket_rounds')->get()->getRow()->round_id;

        $viewed = $this->getReq('comandas/' . $ticket . '/ronda/' . $round)->getBody();
        $this->assertStringContainsString('PRUEBA OT CHORIZO', $viewed);
        $this->assertStringContainsString('bien asado', $viewed);
        $this->assertStringNotContainsString('Unidad:', $viewed);
        $this->assertStringNotContainsString('window.print', $viewed, 'Viewing on a phone must not open a print dialog.');
        $this->assertNull($this->db->table('order_ticket_rounds')->get()->getRow()->printed_at);

        $printed = $this->getReq('comandas/' . $ticket . '/ronda/' . $round . '?imprimir=1')->getBody();
        $this->assertStringContainsString('window.print', $printed);
        $this->assertNotNull($this->db->table('order_ticket_rounds')->get()->getRow()->printed_at);
    }

    public function testMarkingDeliveredMovesTheTicketOn(): void
    {
        $ticket = $this->openLiveTicket();

        $this->postReq('comandas/' . $ticket . '/entregada', [])->assertRedirectTo('comandas/' . $ticket);

        $this->assertSame(Order_ticket::STATUS_DELIVERED, $this->db->table('order_tickets')->get()->getRow()->status);
    }

    /**
     * Taking orders is a waiter's job; cancelling one the kitchen may already have cooked needs its
     * own permission -- even by typing the address.
     */
    public function testCancellingNeedsItsOwnPermission(): void
    {
        $ticket = $this->openLiveTicket();

        $this->postReq('comandas/' . $ticket . '/cancelar', ['reason' => 'el cliente se fue']);

        $this->assertSame(Order_ticket::STATUS_OPEN, $this->db->table('order_tickets')->get()->getRow()->status);
    }

    /**
     * With the permission and a reason, the ticket is cancelled AND its tab is closed the way the
     * register closes one: the sale becomes CANCELED and the throwaway table is deleted. Without
     * that, a ghost tab would stay on every till.
     */
    public function testCancellingWithAReasonClosesTheTicketAndItsTab(): void
    {
        $this->grantVoid();
        $ticket = $this->openLiveTicket();

        $this->postReq('comandas/' . $ticket . '/cancelar', ['reason' => '   ']);
        $this->assertSame(Order_ticket::STATUS_OPEN, $this->db->table('order_tickets')->get()->getRow()->status, 'A blank reason is no reason.');

        $this->postReq('comandas/' . $ticket . '/cancelar', ['reason' => 'el cliente se fue'])->assertRedirectTo('comandas');

        $row = $this->db->table('order_tickets')->get()->getRowArray();
        $this->assertSame(Order_ticket::STATUS_CANCELLED, $row['status']);
        $this->assertSame('el cliente se fue', $row['cancel_reason']);

        $sale = $this->db->table('sales')->where('sale_id', $row['sale_id'])->get()->getRowArray();
        $this->assertSame(CANCELED, (int) $sale['sale_status']);
        $this->assertSame(1, (int) $this->db->table('dinner_tables')->where('dinner_table_id', $sale['dinner_table_id'])->get()->getRow()->deleted);
    }

    /**
     * Opens a real ticket through the endpoint -- ticket, table and OPENED sale -- and returns its id.
     */
    private function openLiveTicket(string $name = 'ANDREA'): int
    {
        $this->switchTo('1');
        $this->tablesTo('1');

        $this->postReq('comandas/crear', ['name' => $name]);

        return (int) $this->db->table('order_tickets')->where('name', $name)->get()->getRow()->order_ticket_id;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createItem(string $name, array $overrides = []): int
    {
        $this->db->table('items')->insert($overrides + [
            'name'                  => $name,
            'category'              => 'Test',
            'item_number'           => null,
            'description'           => '',
            'cost_price'            => '1.00',
            'unit_price'            => '3500.00',
            'reorder_level'         => '0',
            'receiving_quantity'    => '1',
            'allow_alt_description' => 0,
            'is_serialized'         => 0,
        ]);

        $id                   = (int) $this->db->insertID();
        $this->createdItems[] = $id;

        return $id;
    }

    private function grantVoid(): void
    {
        if ($this->db->table('grants')->where(['permission_id' => 'order_tickets_void', 'person_id' => 1])->countAllResults() === 0) {
            $this->db->table('grants')->insert(['permission_id' => 'order_tickets_void', 'person_id' => 1, 'menu_group' => '--']);
            $this->voidGrantedHere = true;
        }
    }

    private function tablesTo(string $value): void
    {
        $this->db->table('app_config')->replace(['key' => 'dinner_table_enable', 'value' => $value]);
        config(OSPOS::class)->update_settings();
    }

    /**
     * Opening a ticket writes into sales and dinner_tables, which every other test file shares.
     * Sales first: sales.dinner_table_id carries a foreign key to the table.
     */
    private function removeWhatOpeningTicketsCreated(): void
    {
        $saleIds = array_filter(array_map('intval', array_column(
            $this->db->table('order_tickets')->select('sale_id')->get()->getResultArray(),
            'sale_id'
        )));

        if ($saleIds === []) {
            return;
        }

        $tableIds = array_map('intval', array_column(
            $this->db->table('sales')->select('dinner_table_id')->whereIn('sale_id', $saleIds)->get()->getResultArray(),
            'dinner_table_id'
        ));

        $this->db->table('sales')->whereIn('sale_id', $saleIds)->delete();

        $tableIds = array_values(array_filter($tableIds, static fn (int $id): bool => $id > 2));

        if ($tableIds !== []) {
            $this->db->table('dinner_tables')->whereIn('dinner_table_id', $tableIds)->delete();
        }
    }

    private function switchTo(string $value): void
    {
        $this->db->table('app_config')->replace(['key' => 'order_tickets_enable', 'value' => $value]);
        config(OSPOS::class)->update_settings();
    }

    private function insertTicket(string $name, int $locationId, string $status = Order_ticket::STATUS_OPEN): int
    {
        $this->db->table('order_tickets')->insert([
            'name'        => $name,
            'status'      => $status,
            'location_id' => $locationId,
            'note'        => '',
            'opened_by'   => 1,
            'opened_at'   => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * GET with the session re-armed every time -- see the class docblock.
     */
    private function getReq(string $path): TestResponse
    {
        $_SESSION = ['person_id' => 1, 'menu_group' => 'home'];
        $this->withSession($_SESSION);

        return $this->get($path);
    }

    /**
     * POST with the session re-armed -- see getReq().
     *
     * @param array<string, string> $params
     */
    private function postReq(string $path, array $params): TestResponse
    {
        $_SESSION = ['person_id' => 1, 'menu_group' => 'home'];
        $this->withSession($_SESSION);

        return $this->post($path, $params);
    }
}
