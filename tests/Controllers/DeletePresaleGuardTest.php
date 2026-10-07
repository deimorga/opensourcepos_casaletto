<?php

namespace Tests\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\OSPOS;

/**
 * Deleting what a presale depends on (docs/Tecnico/venta-anticipada.md §8.5).
 *
 *  - An item that is in a campaign, or in an open presale, is not deleted: the delivery would have
 *    nothing to put in the register.
 *  - A customer with an open presale is not deleted: the presale is an agreement with that person.
 *
 * Once the presale is delivered or cancelled the guard lets go. Rows made here are deleted in
 * tearDown (shared test database).
 *
 * @internal
 */
final class DeletePresaleGuardTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const CAMPAIGN = 933_100;
    private const PRESALE  = 933_500;

    /** @var list<int> */
    private array $items = [];

    /** @var list<int> */
    private array $customers = [];

    private int $employee_id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->resetDataCache();
        config(OSPOS::class)->update_settings();

        $employee          = $this->db->table('employees')->select('person_id')->limit(1)->get()->getRow();
        $this->employee_id = $employee === null ? 1 : (int) $employee->person_id;
    }

    protected function tearDown(): void
    {
        $this->db->table('presale_items')->where('presale_id', self::PRESALE)->delete();
        $this->db->table('presales')->where('presale_id', self::PRESALE)->delete();
        $this->db->table('presale_campaign_items')->where('campaign_id', self::CAMPAIGN)->delete();

        if ($this->items !== []) {
            $this->db->table('inventory')->whereIn('trans_items', $this->items)->delete();
            $this->db->table('item_quantities')->whereIn('item_id', $this->items)->delete();
            $this->db->table('items')->whereIn('item_id', $this->items)->delete();
        }

        if ($this->customers !== []) {
            $this->db->table('customers')->whereIn('person_id', $this->customers)->delete();
            $this->db->table('people')->whereIn('person_id', $this->customers)->delete();
        }

        parent::tearDown();
    }

    public function testAnItemInACampaignIsNotDeleted(): void
    {
        $item = $this->createItem('TEST-PV-CAMPANA', 'Pavo de campaña de prueba');
        $this->db->table('presale_campaign_items')->insert(['campaign_id' => self::CAMPAIGN, 'item_id' => $item, 'base_price' => '10.00']);

        $result = $this->post_as('items/delete', ['ids' => [(string) $item]]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Pavo de campaña de prueba', $result['message']);
        $this->assertSame(0, (int) $this->db->table('items')->where('item_id', $item)->get()->getRow()->deleted);
    }

    public function testAnItemInAnOpenPresaleIsNotDeleted(): void
    {
        $item     = $this->createItem('TEST-PV-ABIERTA', 'Jamón de prueba');
        $customer = $this->createCustomer('Ana', 'Preventa Abierta');
        $this->presale($customer, 'open', $item);

        $result = $this->post_as('items/delete', ['ids' => [(string) $item]]);

        $this->assertFalse($result['success']);
        $this->assertSame(0, (int) $this->db->table('items')->where('item_id', $item)->get()->getRow()->deleted);
    }

    /**
     * One blocked item in the selection deletes nothing, like the recipe guard.
     */
    public function testASelectionWithOneBlockedItemDeletesNothing(): void
    {
        $blocked = $this->createItem('TEST-PV-BLOQ', 'Bloqueado de prueba');
        $free    = $this->createItem('TEST-PV-LIBRE', 'Libre de prueba');
        $this->db->table('presale_campaign_items')->insert(['campaign_id' => self::CAMPAIGN, 'item_id' => $blocked, 'base_price' => '10.00']);

        $result = $this->post_as('items/delete', ['ids' => [(string) $free, (string) $blocked]]);

        $this->assertFalse($result['success']);
        $this->assertSame(0, (int) $this->db->table('items')->where('item_id', $free)->get()->getRow()->deleted);
    }

    public function testAnItemOnlyInADeliveredPresaleCanBeDeleted(): void
    {
        $item     = $this->createItem('TEST-PV-ENTREGADA', 'Entregado de prueba');
        $customer = $this->createCustomer('Luis', 'Preventa Entregada');
        $this->presale($customer, 'delivered', $item);

        $result = $this->post_as('items/delete', ['ids' => [(string) $item]]);

        $this->assertTrue($result['success']);
    }

    public function testACustomerWithAnOpenPresaleIsNotDeleted(): void
    {
        $customer = $this->createCustomer('José', 'Muñoz PV-GUARD');
        $this->presale($customer, 'open');

        $result = $this->post_as('customers/delete', ['ids' => [(string) $customer]]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Muñoz PV-GUARD', $result['message']);
        $this->assertSame(0, (int) $this->db->table('customers')->where('person_id', $customer)->get()->getRow()->deleted);
    }

    public function testASelectionWithOneBlockedCustomerDeletesNothing(): void
    {
        $blocked = $this->createCustomer('María', 'Con Preventa');
        $free    = $this->createCustomer('Pedro', 'Sin Preventa');
        $this->presale($blocked, 'open');

        $result = $this->post_as('customers/delete', ['ids' => [(string) $free, (string) $blocked]]);

        $this->assertFalse($result['success']);
        $this->assertSame(0, (int) $this->db->table('customers')->where('person_id', $free)->get()->getRow()->deleted);
    }

    public function testACustomerWhosePresaleWasDeliveredCanBeDeleted(): void
    {
        $customer = $this->createCustomer('Rosa', 'Ya Entregada');
        $this->presale($customer, 'delivered');

        $result = $this->post_as('customers/delete', ['ids' => [(string) $customer]]);

        $this->assertTrue($result['success']);
    }

    private function post_as(string $uri, array $data): array
    {
        $session = Services::session();
        $session->destroy();
        $session->set('person_id', 1);
        $session->set('menu_group', 'office');
        $this->withSession(['person_id' => 1, 'menu_group' => 'office']);

        return json_decode($this->post($uri, $data)->getJSON(), true);
    }

    private function presale(int $customer_id, string $status, ?int $item_id = null): void
    {
        $this->db->table('presales')->insert([
            'presale_id'       => self::PRESALE,
            'campaign_id'      => self::CAMPAIGN,
            'customer_id'      => $customer_id,
            'employee_id'      => $this->employee_id,
            'location_id'      => 1,
            'created_at'       => '2001-04-01 10:00:00',
            'delivery_date_id' => 933_100,
            'delivery_date'    => '2001-04-20',
            'status'           => $status,
            'total'            => '10.00',
        ]);

        if ($item_id !== null) {
            $this->db->table('presale_items')->insert([
                'presale_id' => self::PRESALE,
                'line'       => 1,
                'item_id'    => $item_id,
                'quantity'   => '1.000',
                'unit_price' => '10.00',
            ]);
        }
    }

    private function createItem(string $number, string $name): int
    {
        $this->db->table('items')->insert([
            'name' => $name, 'category' => 'Test', 'item_number' => $number, 'description' => 'DeletePresaleGuardTest',
            'cost_price' => '1.00', 'unit_price' => '10.00', 'reorder_level' => '0', 'receiving_quantity' => '1',
            'allow_alt_description' => 0, 'is_serialized' => 0,
        ]);
        $id            = (int) $this->db->insertID();
        $this->items[] = $id;

        $this->db->table('item_quantities')->insert(['item_id' => $id, 'location_id' => 1, 'quantity' => '5']);

        return $id;
    }

    private function createCustomer(string $first, string $last): int
    {
        $this->db->table('people')->insert([
            'first_name' => $first, 'last_name' => $last, 'phone_number' => '', 'email' => '',
            'address_1'  => '', 'address_2' => '', 'city' => '', 'state' => '', 'zip' => '', 'country' => '', 'comments' => '',
        ]);
        $id = (int) $this->db->insertID();

        $this->db->table('customers')->insert(['person_id' => $id, 'taxable' => 1, 'deleted' => 0, 'employee_id' => $this->employee_id]);
        $this->customers[] = $id;

        return $id;
    }
}
