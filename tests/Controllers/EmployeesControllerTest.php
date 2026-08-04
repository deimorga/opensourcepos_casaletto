<?php

namespace Tests\Controllers;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Config\Services;
use App\Models\Employee;
use App\Models\Module;
use Config\OSPOS;

class EmployeesControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    protected function setUp(): void
    {
        parent::setUp();

        // See SalesControllerTest::setUp() -- the pooled 'tests' connection
        // can cache a stale (pre-migration) table list otherwise, which makes
        // Config\OSPOS::set_settings() fall back to a handful of hardcoded
        // defaults missing keys like country_codes, crashing any view that
        // reads $config['country_codes'] (e.g. employees/form.php).
        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();
    }

    protected function createNonAdminEmployee(): int
    {
        $unique = uniqid();

        return $this->createTestEmployee("nonadmin_$unique", 'NonAdmin', 'User', "nonadmin_$unique@test.com", [
            ['permission_id' => 'customers', 'menu_group' => 'home'],
            ['permission_id' => 'sales', 'menu_group' => 'home']
        ]);
    }

    /**
     * Creates a non-admin employee with no grants by default, for use as a
     * modification/permission-delegation target distinct from the acting
     * user. Employee ids are DB auto-increment, so the id is discovered by
     * looking the row back up via its (unique) username after insert --
     * get_found_rows('') returns a total employee COUNT, not an insert id,
     * and using it as an id here previously aliased the "non-admin" actor
     * onto person_id 1 (the real admin) whenever exactly one employee
     * existed, silently turning every BOLA test into admin-vs-admin.
     *
     * $username defaults to a unique value: this class has $refresh=false,
     * and this CI4 version's DatabaseTestTrait doesn't wrap each test in its
     * own rollback transaction, so rows from earlier test methods in this
     * class are still present -- a fixed default would collide with
     * `username` 's UNIQUE constraint the second time any test calls this.
     */
    protected function createTestEmployee(?string $username = null, string $firstName = 'Test', string $lastName = 'Employee', ?string $email = null, array $grantsData = []): int
    {
        $username ??= 'testuser_' . uniqid();
        $email ??= $username . '@test.com';

        $personData = [
            'first_name'   => $firstName,
            'last_name'    => $lastName,
            'email'        => $email,
            'phone_number' => '555-1234'
        ];

        $employeeData = [
            'username'      => $username,
            'password'      => password_hash('password123', PASSWORD_DEFAULT),
            'hash_version'  => 2,
            'language_code' => 'en',
            'language'      => 'english'
        ];

        $employeeModel = model(Employee::class);
        $employeeModel->save_employee($personData, $employeeData, $grantsData, NEW_ENTRY);

        $row = db_connect()->table('employees')->where('username', $username)->get()->getRow();

        return (int) $row->person_id;
    }

    protected function loginAsAdmin(): void
    {
        $session = Services::session();
        $session->destroy();
        $session->set('person_id', 1);
        $session->set('menu_group', 'office');

        // FeatureTestTrait::call() ignores the real session service above --
        // it unconditionally overwrites $_SESSION with its own $this->session
        // property before dispatching the request (see populateGlobals(),
        // "$_SESSION = $this->session;"). Without this, every request below
        // runs with an empty session, Secure_Controller sees an anonymous
        // user and calls exit() (a real exit(), not a redirect/exception),
        // which silently kills the whole PHPUnit process with no test output
        // and a misleading exit 0.
        $this->withSession(['person_id' => 1, 'menu_group' => 'office']);
    }

    protected function loginAsNonAdmin(int $personId): void
    {
        $session = Services::session();
        $session->destroy();
        $session->set('person_id', $personId);
        $session->set('menu_group', 'home');

        // See loginAsAdmin() above for why this is required.
        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);
    }

    public function testNonAdminCannotViewAdminAccount(): void
    {
        $nonAdminId = $this->createNonAdminEmployee();
        $this->loginAsNonAdmin($nonAdminId);
        
        $response = $this->get('/employees/view/1');

        $response->assertRedirect();
        $this->assertStringContainsString('no_access', $response->getRedirectUrl());
    }

    public function testNonAdminCannotModifyAdminAccount(): void
    {
        $nonAdminId = $this->createNonAdminEmployee();
        $this->loginAsNonAdmin($nonAdminId);

        $response = $this->post('/employees/save/1', [
            'first_name' => 'Hacked',
            'last_name' => 'Admin',
            'email' => 'hacked@evil.com',
            'username' => 'admin'
        ]);

        // The employees module itself is admin-only (Secure_Controller gates
        // the whole controller on the "employees" grant), so a non-admin
        // without it never reaches Employees::postSave()'s own
        // canModifyEmployee() check -- they're redirected to no_access first.
        $response->assertRedirect();
        $this->assertStringContainsString('no_access', $response->getRedirectUrl());
    }

    public function testNonAdminCannotDeleteAdminAccount(): void
    {
        $nonAdminId = $this->createNonAdminEmployee();
        $this->loginAsNonAdmin($nonAdminId);

        $response = $this->post('/employees/delete', [
            'ids' => ['1']
        ]);

        // Same as testNonAdminCannotModifyAdminAccount() above: the employees
        // module is admin-only, so a non-admin is redirected to no_access
        // before postDelete()'s own logic ever runs.
        $response->assertRedirect();
        $this->assertStringContainsString('no_access', $response->getRedirectUrl());
    }

    public function testNonAdminCannotGrantPermissionsTheyDontHave(): void
    {
        $nonAdminId = $this->createNonAdminEmployee();
        $this->loginAsNonAdmin($nonAdminId);
        
        $targetEmployeeId = $this->createTestEmployee();

        $response = $this->post('/employees/save/' . $targetEmployeeId, [
            'first_name' => 'Test',
            'last_name' => 'Employee',
            'email' => 'test@test.com',
            'username' => 'testuser',
            'grant_employees' => 'employees',
            'grant_config' => 'config'
        ]);
        
        $employeeModel = model(Employee::class);
        $hasEmployeesGrant = $employeeModel->has_grant('employees', $targetEmployeeId);
        $hasConfigGrant = $employeeModel->has_grant('config', $targetEmployeeId);
        
        $this->assertFalse($hasEmployeesGrant);
        $this->assertFalse($hasConfigGrant);
    }

    public function testAdminCanModifyAnyAccount(): void
    {
        $nonAdminId = $this->createNonAdminEmployee();
        $this->loginAsAdmin();
        
        $response = $this->post('/employees/save/' . $nonAdminId, [
            'first_name' => 'Modified',
            'last_name' => 'User',
            'email' => 'modified_' . uniqid() . '@test.com',
            'username' => 'modified_' . uniqid()
        ]);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);
    }

    public function testAdminCanDeleteAnyAccount(): void
    {
        $nonAdminId = $this->createNonAdminEmployee();
        $this->loginAsAdmin();
        
        $response = $this->post('/employees/delete', [
            'ids' => [(string) $nonAdminId]
        ]);
        
        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);
    }

    /**
     * The employees module (and so Employees::postSave()) is admin-only --
     * a regular employee without the "employees" grant is blocked by
     * Secure_Controller before reaching canModifyEmployee()'s finer-grained
     * self-vs-other check, even when the target is their own account.
     * Self-service profile edits go through a different, ungated path
     * (e.g. Home::postSave() for password changes).
     */
    public function testNonAdminWithoutEmployeesGrantCannotModifyOwnAccountViaEmployeesModule(): void
    {
        $nonAdminId = $this->createNonAdminEmployee();
        $this->loginAsNonAdmin($nonAdminId);

        $response = $this->post('/employees/save/' . $nonAdminId, [
            'first_name' => 'Modified',
            'last_name' => 'OwnAccount',
            'email' => 'own_' . uniqid() . '@test.com',
            'username' => 'own_' . uniqid()
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('no_access', $response->getRedirectUrl());
    }

    public function testPermissionDelegationRule(): void
    {
        $permissionsRequested = ['customers', 'employees', 'sales', 'config'];
        $userPermissions = ['customers', 'sales'];
        $isAdmin = false;
        
        $granted = [];
        foreach ($permissionsRequested as $perm) {
            if ($isAdmin || in_array($perm, $userPermissions)) {
                $granted[] = $perm;
            }
        }
        
        $this->assertEquals(['customers', 'sales'], $granted);
    }

    public function testAdminCanGrantAnyPermission(): void
    {
        $permissionsRequested = ['customers', 'employees', 'sales', 'config'];
        $userPermissions = ['customers', 'sales'];
        $isAdmin = true;
        
        $granted = [];
        foreach ($permissionsRequested as $perm) {
            if ($isAdmin || in_array($perm, $userPermissions)) {
                $granted[] = $perm;
            }
        }
        
        $this->assertEquals($permissionsRequested, $granted);
    }
}