<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Models\Customer;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\OSPOS;

/**
 * A customer saved through the real Customers screen keeps its accents as text, and is found by them.
 *
 * Presales bring hundreds of new customers, and the accents repair of 2026-08-22 left Customers on the
 * pending list: FILTER_SANITIZE_FULL_SPECIAL_CHARS turns "José" into "Jos&eacute;" and the search for
 * "José" then finds nothing (docs/Tecnico/venta-anticipada.md §8.7). The rule of that repair applies:
 * run it rather than reason about it. This is the run.
 *
 * SHARED DATABASE: the customer created here is found by its email and removed; person 1 is given the
 * `customers` grant only if it did not hold it.
 *
 * @internal
 */
final class CustomersAccentTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private const EMAIL = 'presale-accent-test@example.invalid';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';
    private bool $grantedHere = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->removeCustomer();

        if ($this->db->table('grants')->where('permission_id', 'customers')->where('person_id', 1)->countAllResults() === 0) {
            $this->db->table('grants')->insert(['permission_id' => 'customers', 'person_id' => 1, 'menu_group' => 'home']);
            $this->grantedHere = true;
        }
    }

    protected function tearDown(): void
    {
        $this->removeCustomer();

        if ($this->grantedHere) {
            $this->db->table('grants')->where('permission_id', 'customers')->where('person_id', 1)->delete();
        }

        parent::tearDown();
    }

    public function testAccentsAreStoredAsTextAndFound(): void
    {
        $config = config(OSPOS::class)->settings;

        $_SESSION = ['person_id' => 1, 'menu_group' => 'home'];
        $this->withSession($_SESSION);

        $response = $this->post('customers/save', [
            'first_name'   => 'José',
            'last_name'    => 'Muñoz',
            'email'        => self::EMAIL,
            'phone_number' => '',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
            'account_number' => '',
            'tax_id'       => '',
            'company_name' => '',
            'discount'     => '',
            'discount_type' => (string) PERCENT,
            'date'         => date($config['dateformat'] . ' ' . $config['timeformat']),
            'employee_id'  => '1',
        ]);

        $response->assertStatus(200);
        $result = json_decode((string) $response->getJSON(), true);
        $this->assertTrue($result['success'], $result['message'] ?? '');

        $person = $this->db->table('people')->where('email', self::EMAIL)->get()->getRowArray();

        $this->assertNotNull($person);
        $this->assertSame('José', $person['first_name'], 'Stored as an HTML entity or altered');
        $this->assertSame('Muñoz', $person['last_name'], 'Stored as an HTML entity or altered');

        $found = array_map('intval', array_column(model(Customer::class)->get_search_suggestions('José'), 'value'));
        $this->assertContains((int) $person['person_id'], $found);
    }

    private function removeCustomer(): void
    {
        $ids = array_column($this->db->table('people')->select('person_id')->where('email', self::EMAIL)->get()->getResultArray(), 'person_id');

        if ($ids !== []) {
            $this->db->table('customers')->whereIn('person_id', $ids)->delete();
            $this->db->table('people')->whereIn('person_id', $ids)->delete();
        }
    }
}
