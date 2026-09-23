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
