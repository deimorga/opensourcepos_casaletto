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
    private bool $grantedHere     = false;

    protected function setUp(): void
    {
        parent::setUp();

        // See SalesControllerTest::setUp(): without this, Config\OSPOS can see no app_config table
        // and fall back to defaults that crash a controller's constructor.
        $this->db->resetDataCache();

        $row                = $this->db->table('app_config')->where('key', 'order_tickets_enable')->get()->getRow();
        $this->switchBefore = $row === null ? null : (string) $row->value;

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
}
