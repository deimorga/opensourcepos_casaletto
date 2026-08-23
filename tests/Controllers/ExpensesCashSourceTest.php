<?php

namespace Tests\Controllers;

use App\Models\Employee;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\OSPOS;

/**
 * Covers the server-side decision for an expense's cash source
 * (docs/Tecnico/cuadre-de-caja-y-origen-del-efectivo.md section 3.3).
 *
 * CashSourceHelperTest already proves the rule in isolation. These go through the real request so
 * the wiring is covered too: that the permission is read from the logged-in employee and not from
 * the request, that the value survives Expense::$allowedFields, and that a refusal comes back in
 * the {success, message, id} shape the grid's submit handler expects.
 */
class ExpensesCashSourceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        // See SalesControllerTest::setUp() -- the pooled 'tests' connection can cache a stale
        // (pre-migration) table list, which makes Config\OSPOS::set_settings() fall back to
        // defaults that are missing keys the expense views read.
        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();

        $db = db_connect();
        $db->table('expense_categories')->insert([
            'category_name'        => 'Cash Source Fixture ' . uniqid(),
            'category_description' => 'Fixture for ExpensesCashSourceTest',
            'deleted'              => 0
        ]);
        $this->categoryId = (int) $db->insertID();
    }

    /**
     * An employee who can record expenses but holds no 'config' grant: a cashier.
     */
    private function createCashier(): int
    {
        $username = 'cashier_' . uniqid();

        // save_employee() takes all three arrays by reference -- it writes the new ids back into
        // them -- so they have to be variables. PHP will not bind a literal to a reference
        // parameter, and passing one is a fatal rather than a notice.
        $person_data = [
            'first_name'   => 'Cash',
            'last_name'    => 'Ier',
            'email'        => $username . '@test.com',
            'phone_number' => '555-1234'
        ];
        $employee_data = [
            'username'      => $username,
            'password'      => password_hash('password123', PASSWORD_DEFAULT),
            'hash_version'  => 2,
            'language_code' => 'en',
            'language'      => 'english'
        ];
        $grants_data = [['permission_id' => 'expenses', 'menu_group' => 'office']];

        model(Employee::class)->save_employee($person_data, $employee_data, $grants_data, NEW_ENTRY);

        return (int) db_connect()->table('employees')->where('username', $username)->get()->getRow()->person_id;
    }

    /**
     * FeatureTestTrait::call() overwrites $_SESSION with its own property on every request, so the
     * session has to be armed through withSession() rather than the session service. See
     * EmployeesControllerTest::loginAsAdmin().
     */
    private function loginAs(int $personId): void
    {
        $session = Services::session();
        $session->destroy();
        $session->set('person_id', $personId);
        $session->set('menu_group', 'office');

        // item_location travels with the session because saving reaches Item_lib, which asks
        // Stock_location for a default and dereferences the row without checking. A cashier holds
        // only the expenses grant, so that lookup finds nothing and fatals. Which location it is
        // has no bearing on what these tests check.
        $session->set('item_location', 1);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'office', 'item_location' => 1]);
    }

    /**
     * The form posts the translated label, so the fixture does too.
     */
    private function expensePost(string $paymentCode, ?string $cashSource): array
    {
        $config = config(OSPOS::class)->settings;

        $post = [
            'date'                => date($config['dateformat'] . ' ' . $config['timeformat']),
            'supplier_id'         => '',
            'supplier_tax_code'   => '',
            'amount'              => '1000',
            'tax_amount'          => '0',
            'payment_type'        => payment_type_label($paymentCode),
            'expense_category_id' => (string) $this->categoryId,
            'description'         => 'ExpensesCashSourceTest'
        ];

        if ($cashSource !== null) {
            $post['cash_source'] = $cashSource;
        }

        return $post;
    }

    private function storedCashSource(int $expenseId): ?string
    {
        $row = db_connect()->table('expenses')->where('expense_id', $expenseId)->get()->getRow();

        return $row->cash_source;
    }

    public function testCashierCashExpenseIsRecordedAgainstTheRegister(): void
    {
        $this->loginAs($this->createCashier());

        $response = $this->post('/expenses/save', $this->expensePost('cash', null));

        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);
        $this->assertSame('register', $this->storedCashSource((int) $result['id']));
    }

    /**
     * The field is greyed out in the cashier's form, but a disabled field is not submitted and a
     * hand-made POST can say anything. The decision belongs to the server.
     */
    public function testCashierCannotPostTheirWayIntoCollectedCash(): void
    {
        $this->loginAs($this->createCashier());

        $response = $this->post('/expenses/save', $this->expensePost('cash', 'collected'));

        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);
        $this->assertSame('register', $this->storedCashSource((int) $result['id']));
    }

    /**
     * The question does not apply to a transfer, and answering it anyway must not invent a cash
     * movement that would then be subtracted from the till.
     */
    public function testTransferExpenseKeepsNoCashSource(): void
    {
        $this->loginAs($this->createCashier());

        $response = $this->post('/expenses/save', $this->expensePost('bank_transfer', 'register'));

        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);
        $this->assertNull($this->storedCashSource((int) $result['id']));
    }

    public function testAdministratorCashExpenseKeepsTheSourceTheyChose(): void
    {
        $this->loginAs(1);

        $response = $this->post('/expenses/save', $this->expensePost('cash', 'collected'));

        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);
        $this->assertSame('collected', $this->storedCashSource((int) $result['id']));
    }

    /**
     * The cash-up 29 lesson: a value that could not be determined has to make noise. Nothing is
     * stored, and the refusal comes back in the same {success, message, id} envelope.
     */
    public function testAdministratorWhoChoseNoSourceIsRefusedAndNothingIsStored(): void
    {
        $this->loginAs(1);

        $before = db_connect()->table('expenses')->countAllResults();

        $response = $this->post('/expenses/save', $this->expensePost('cash', null));

        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('id', $result);
        $this->assertNotSame('', $result['message']);

        $this->assertSame($before, db_connect()->table('expenses')->countAllResults());
    }

    public function testAdministratorSourceOutsideTheAllowlistIsRefused(): void
    {
        $this->loginAs(1);

        $before = db_connect()->table('expenses')->countAllResults();

        $response = $this->post('/expenses/save', $this->expensePost('cash', 'petty_cash'));

        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
        $this->assertSame($before, db_connect()->table('expenses')->countAllResults());
    }

    /**
     * CodeIgniter drops unlisted fields silently, so the column being writable at all is worth its
     * own assertion rather than being inferred from the cases above.
     */
    public function testCashSourceSurvivesTheModelsAllowedFields(): void
    {
        $this->assertContains('cash_source', model(\App\Models\Expense::class)->allowedFields);
    }
}
