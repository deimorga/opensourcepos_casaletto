<?php

namespace Tests\Models;

use App\Models\Employee;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\OSPOS;

/**
 * Where an employee lands after logging in.
 *
 * Every login path sent everybody to `home`, and Home is gated on the `home` grant. A waiter granted
 * only order_tickets does not have it, so they logged in straight onto no_access with no way
 * forward. The rule that fixes it must change nothing for anybody who existed before: every one of
 * them holds `home`.
 *
 * A real employee is created for this file, because grants carries a foreign key to employees. It
 * is removed in tearDown -- the test database is shared between files -- and its grants go with it
 * (ON DELETE CASCADE).
 *
 * @internal
 */
final class EmployeeLandingRouteTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private Employee $employees;
    private int $personId = 0;
    private ?string $switchBefore = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->resetDataCache();
        $this->employees = model(Employee::class, false);
        $this->personId  = $this->createEmployee();

        $row                = $this->db->table('app_config')->where('key', 'order_tickets_enable')->get()->getRow();
        $this->switchBefore = $row === null ? null : (string) $row->value;
    }

    protected function tearDown(): void
    {
        if ($this->personId > 0) {
            $this->db->table('grants')->where('person_id', $this->personId)->delete();
            $this->db->table('employees')->where('person_id', $this->personId)->delete();
            $this->db->table('people')->where('person_id', $this->personId)->delete();
        }

        // app_config is shared between test files: put the switch back exactly as it was.
        if ($this->switchBefore === null) {
            $this->db->table('app_config')->where('key', 'order_tickets_enable')->delete();
        } else {
            $this->db->table('app_config')->replace(['key' => 'order_tickets_enable', 'value' => $this->switchBefore]);
        }

        config(OSPOS::class)->update_settings();

        parent::tearDown();
    }

    public function testAWaiterWithOnlyOrderTicketsLandsOnTheirScreen(): void
    {
        $this->grant('order_tickets');

        $this->assertSame('comandas', $this->employees->landing_route($this->personId));
    }

    /**
     * The guarantee that nothing changes for anybody else: holding `home` means landing on home,
     * whatever else the employee also holds.
     */
    public function testAnEmployeeWithHomeLandsOnHomeEvenWithOrderTickets(): void
    {
        $this->grant('home');
        $this->grant('order_tickets');

        $this->assertSame('home', $this->employees->landing_route($this->personId));
    }

    /**
     * No grants at all: home, exactly as before. Home will answer no_access, which is the honest
     * answer for an employee nobody has given anything to.
     */
    public function testAnEmployeeWithNoGrantsStillLandsOnHome(): void
    {
        $this->assertSame('home', $this->employees->landing_route($this->personId));
    }

    /**
     * D26: a cashier who also takes orders, logging in from a phone, lands on the order screen. At
     * Casaletto the cashier walks to the tables; a phone at login means taking orders.
     */
    public function testACashierWithOrderTicketsLoggingInFromAPhoneLandsOnTheOrderScreen(): void
    {
        $this->switchTo('1');
        $this->grant('home');
        $this->grant('order_tickets');

        $this->assertSame('comandas', $this->employees->landing_route($this->personId, true));
    }

    /**
     * The same cashier from a computer: home, exactly as before D26.
     */
    public function testTheSameCashierFromAComputerStillLandsOnHome(): void
    {
        $this->switchTo('1');
        $this->grant('home');
        $this->grant('order_tickets');

        $this->assertSame('home', $this->employees->landing_route($this->personId, false));
    }

    /**
     * With order tickets switched off, a phone login must reach the menu, not a page saying the
     * module is off.
     */
    public function testAPhoneLoginGoesHomeWhenOrderTicketsAreSwitchedOff(): void
    {
        $this->switchTo('0');
        $this->grant('home');
        $this->grant('order_tickets');

        $this->assertSame('home', $this->employees->landing_route($this->personId, true));
    }

    /**
     * A phone alone changes nothing for somebody who does not take orders.
     */
    public function testAPhoneLoginWithoutOrderTicketsGoesHome(): void
    {
        $this->switchTo('1');
        $this->grant('home');

        $this->assertSame('home', $this->employees->landing_route($this->personId, true));
    }

    public function testTheSeededAdministratorLandsOnHome(): void
    {
        $this->assertSame('home', $this->employees->landing_route(1));
    }

    private function switchTo(string $value): void
    {
        $this->db->table('app_config')->replace(['key' => 'order_tickets_enable', 'value' => $value]);
        config(OSPOS::class)->update_settings();
    }

    private function grant(string $permission_id): void
    {
        $this->db->table('grants')->insert([
            'permission_id' => $permission_id,
            'person_id'     => $this->personId,
            'menu_group'    => $permission_id === 'home' ? 'office' : 'home',
        ]);
    }

    private function createEmployee(): int
    {
        $this->db->table('people')->insert([
            'first_name'   => 'Mesero',
            'last_name'    => 'De Prueba',
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

        // A username of its own: employees.username is unique and this schema is shared by dozens
        // of files.
        $this->db->table('employees')->insert([
            'username'      => 'mesero_aterrizaje_' . $personId,
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
