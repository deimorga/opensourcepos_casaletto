<?php

namespace Tests\Controllers;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\OSPOS;
use DOMDocument;

/**
 * What a waiter can and cannot reach.
 *
 * A waiter is granted order_tickets and nothing else (D17). The promise is that this gives them their
 * screen and ONLY their screen: not the register, not the configuration, not anything that handles
 * money. And the design of the permissions carries a second promise, about a real hole in
 * Employee::has_module_grant(): with two or more subpermissions under a prefix and no base
 * permission, a module opens anyway -- order tickets ship exactly ONE subpermission so that it does
 * not (see 20260923020000_AddOrderTicketsModule).
 *
 * A real employee is created: grants carries a foreign key to employees. Everything this file writes
 * is removed in tearDown; the test database is shared between files.
 *
 * @internal
 */
final class OrderTicketsPermissionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private int $waiter = 0;
    private ?string $switchBefore = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->resetDataCache();

        $row                = $this->db->table('app_config')->where('key', 'order_tickets_enable')->get()->getRow();
        $this->switchBefore = $row === null ? null : (string) $row->value;
        $this->db->table('app_config')->replace(['key' => 'order_tickets_enable', 'value' => '1']);
        config(OSPOS::class)->update_settings();

        $this->waiter = $this->createEmployee();
    }

    protected function tearDown(): void
    {
        if ($this->waiter > 0) {
            $this->db->table('grants')->where('person_id', $this->waiter)->delete();
            $this->db->table('employees')->where('person_id', $this->waiter)->delete();
            $this->db->table('people')->where('person_id', $this->waiter)->delete();
        }

        if ($this->switchBefore === null) {
            $this->db->table('app_config')->where('key', 'order_tickets_enable')->delete();
        } else {
            $this->db->table('app_config')->replace(['key' => 'order_tickets_enable', 'value' => $this->switchBefore]);
        }

        config(OSPOS::class)->update_settings();

        parent::tearDown();
    }

    public function testAWaiterReachesTheirScreen(): void
    {
        $this->grant('order_tickets');

        $this->getAs('comandas')->assertStatus(200);
    }

    /**
     * THE promise: order tickets do not open the register. Not by the menu, not by typing the URL.
     */
    public function testAWaiterCannotReachTheRegister(): void
    {
        $this->grant('order_tickets');

        $this->assertDenied($this->getAs('sales'));
    }

    public function testAWaiterCannotReachTheConfigurationNorTheHomeScreen(): void
    {
        $this->grant('order_tickets');

        $this->assertDenied($this->getAs('config'));
        $this->assertDenied($this->getAs('home'));
    }

    /**
     * The hole the single subpermission closes. With only order_tickets_void and NOT the base
     * permission, has_module_grant() finds one match that is not the base, asks
     * has_subpermissions(), and gets "this module has subpermissions" -- so it denies. Were a second
     * subpermission ever added under this prefix, two grants without the base would pass instead:
     * this test and OrderTicketsMigrationTest::testThereIsExactlyOneSubpermissionUnderTheModulePrefix
     * are what would say so.
     */
    public function testTheCancelPermissionAloneDoesNotOpenTheModule(): void
    {
        $this->grant('order_tickets_void', '--');

        $this->assertDenied($this->getAs('comandas'));
    }

    /**
     * The menu tile of every module links to base_url($module_id): for order tickets, /order_tickets.
     * The screen lives at /comandas. Without the redirect, the tile of anyone granted Comandas led to
     * a 404 -- found on 2026-09-23, when the owner looked for the way in as a cashier.
     */
    public function testTheMenuTileLeadsToTheScreen(): void
    {
        $this->grant('order_tickets');

        $response = $this->getAs('order_tickets');

        $response->assertRedirect();
        $this->assertStringEndsWith('/comandas', (string) $response->getRedirectUrl());
    }

    /**
     * Taking orders is a permission, not a kind of employee. At Casaletto the cashier also walks to
     * the tables: granted both, they reach both screens, and the order screen offers the way back to
     * the till instead of only "log out".
     */
    public function testACashierWhoAlsoTakesOrdersReachesBothAndCanGoBackToTheTill(): void
    {
        $this->grant('order_tickets');
        $this->grant('sales');
        // A real cashier also holds their site: without a sales location grant the register itself
        // fails (Stock_location::get_default_location_id(), a known pre-existing defect, docs/Tecnico
        // §0.7). cert_cajero on staging holds exactly these.
        $this->grant('sales_stock', '--');

        $screen = $this->getAs('comandas');
        $screen->assertStatus(200);
        $this->assertContains(base_url('sales'), $this->links($screen->getBody()), 'The "Caja" link is there, and leads to the till.');

        $register = $this->getAs('sales');
        $this->assertStringNotContainsString('no_access', (string) $register->getRedirectUrl());
    }

    /**
     * The waiter granted only Comandas gets no link to a register they cannot open: it would lead to
     * no_access, which is a dead end on a phone.
     */
    public function testAWaiterIsNotOfferedTheTill(): void
    {
        $this->grant('order_tickets');

        $links = $this->links($this->getAs('comandas')->getBody());

        $this->assertContains(base_url('comandas/salir'), $links, 'Guard: the links were read at all.');
        $this->assertNotContains(base_url('sales'), $links);
    }

    /**
     * Every href on the page, decoded the way the browser decodes it. The layout escapes attributes
     * (esc(..., 'attr') turns "https://" into entities), so matching the raw HTML for a literal URL
     * would never match -- and a "must NOT contain" assertion would pass no matter what.
     *
     * @return list<string>
     */
    private function links(string $html): array
    {
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $links = [];

        foreach ($dom->getElementsByTagName('a') as $a) {
            $links[] = $a->getAttribute('href');
        }

        return $links;
    }

    private function assertDenied(TestResponse $response): void
    {
        // Not assertFalse($response->isOK()): CI4 counts 200-399 as OK, so a redirect would pass that
        // check on a screen that was actually served. Same reasoning as WriteoffsControllerTest.
        $response->assertRedirect();
        $this->assertStringContainsString('no_access', (string) $response->getRedirectUrl());
    }

    /**
     * A request as the waiter. The session is re-armed on every call: FeatureTestTrait overwrites
     * $_SESSION on each request, and an anonymous one ends the process with a real exit().
     */
    private function getAs(string $path): TestResponse
    {
        $_SESSION = ['person_id' => $this->waiter, 'menu_group' => 'home'];
        $this->withSession($_SESSION);

        return $this->get($path);
    }

    private function grant(string $permission_id, string $menu_group = 'home'): void
    {
        $this->db->table('grants')->insert([
            'permission_id' => $permission_id,
            'person_id'     => $this->waiter,
            'menu_group'    => $menu_group,
        ]);
    }

    private function createEmployee(): int
    {
        $this->db->table('people')->insert([
            'first_name'   => 'Mesero',
            'last_name'    => 'Permisos',
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

        $personId = (int) $this->db->insertID();

        $this->db->table('employees')->insert([
            'username'      => 'mesero_permisos_' . $personId,
            'password'      => password_hash('no-se-usa', PASSWORD_DEFAULT),
            'person_id'     => $personId,
            'deleted'       => 0,
            'hash_version'  => 2,
            'language'      => null,
            'language_code' => null,
        ]);

        return $personId;
    }
}
