<?php

namespace Tests\Controllers;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\OSPOS;

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
