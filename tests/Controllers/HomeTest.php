<?php

namespace Tests\Controllers;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Config\Services;
use App\Models\Employee;
use Config\OSPOS;

/**
 * Test suite for Home controller password validation
 *
 * Tests the critical fix for password minimum length validation bypass
 * Issue: Code was checking hashed password length (always 60 chars) instead of actual password
 * Fix: Validate raw password length BEFORE hashing
 */
class HomeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    /**
     * Set up test environment
     */
    protected function setUp(): void
    {
        parent::setUp();

        // See SalesControllerTest::setUp() -- the pooled 'tests' connection
        // can cache a stale (pre-migration) table list otherwise, which makes
        // Config\OSPOS::set_settings() fall back to a handful of hardcoded
        // defaults missing keys that views under test may read.
        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();

        // This project's seed data (initial_schema.sql) ships the admin
        // account as "admin_casaletto" with a Casaletto-specific password,
        // not upstream OSPOS's generic "admin"/"pointofsale". Force it to a
        // known password before every test so tests below can authenticate
        // as admin via Employee::check_password() -- idempotent regardless
        // of test order/failures, since $refresh=false here means state
        // (including any password a prior test changed) carries over.
        model(Employee::class)->change_password([
            'password'     => password_hash('pointofsale', PASSWORD_DEFAULT),
            'hash_version' => 2
        ], 1);
    }

    /**
     * Test password validation rejects passwords shorter than 8 characters
     * 
     * @return void
     */
    public function testPasswordMinLength_Rejects7Characters(): void
    {
        $this->resetSession();
        
        // Attempt to change password to 7 characters
        $response = $this->post('/home/save', [
            'employee_id' => 1,
            'username' => 'admin_casaletto',
            'current_password' => 'pointofsale',
            'password' => '1234567' // 7 characters
        ]);
        
        // Assert failure response
        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success'], 'Password with 7 chars should be rejected');
        $this->assertEquals(-1, $result['id']);
        
        // Verify password was not changed
        $employee = model(Employee::class);
        $admin = $employee->get_info(1);
        $this->assertTrue(password_verify('pointofsale', $admin->password), 
            'Password should not have been changed');
    }
    
    /**
     * Test password validation accepts passwords with exactly 8 characters
     * 
     * @return void
     */
    public function testPasswordMinLength_Accepts8Characters(): void
    {
        $this->resetSession();
        
        // Change password to exactly 8 characters
        $response = $this->post('/home/save', [
            'employee_id' => 1,
            'username' => 'admin_casaletto',
            'current_password' => 'pointofsale',
            'password' => 'pa$$w0rd' // Exactly 8 characters including special chars
        ]);
        
        // Assert success response
        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success'], 'Password with 8 chars should be accepted');
        $this->assertEquals(1, $result['id']);
        
        // Verify password was changed
        $employee = model(Employee::class);
        $admin = $employee->get_info(1);
        $this->assertTrue(password_verify('pa$$w0rd', $admin->password), 
            'Password with 8 chars should be accepted');
        
        // Restore original password
        $employee->change_password([
            'username' => 'admin_casaletto',
            'password' => password_hash('pointofsale', PASSWORD_DEFAULT),
            'hash_version' => 2
        ], 1);
    }
    
    /**
     * Test password validation rejects empty password
     * 
     * @return void
     */
    public function testPasswordMinLength_RejectsEmptyString(): void
    {
        $this->resetSession();
        
        // Attempt to set empty password
        $response = $this->post('/home/save', [
            'employee_id' => 1,
            'username' => 'admin_casaletto',
            'current_password' => 'pointofsale',
            'password' => '' // Empty string
        ]);
        
        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success'], 'Empty password should be rejected');
        $this->assertEquals(-1, $result['id']);
    }
    
    /**
     * Test password validation rejects whitespace-only passwords
     * 
     * @return void
     */
    public function testPasswordMinLength_RejectsWhitespaceOnly(): void
    {
        $this->resetSession();
        
        // Attempt to set password as only whitespace
        $response = $this->post('/home/save', [
            'employee_id' => 1,
            'username' => 'admin_casaletto',
            'current_password' => 'pointofsale',
            'password' => '        ' // 8 spaces but empty actual password
        ]);
        
        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success'], 'Whitespace only password should be rejected');
        $this->assertEquals(-1, $result['id']);
    }
    
    /**
     * Test password validation accepts passwords with special characters
     * as long as they meet minimum length
     * 
     * @return void
     */
    public function testPasswordMinLength_AcceptsSpecialCharacters(): void
    {
        $this->resetSession();
        
        $specialPassword = 'Str0ng!@#$'; // 11 characters with special chars
        
        $response = $this->post('/home/save', [
            'employee_id' => 1,
            'username' => 'admin_casaletto',
            'current_password' => 'pointofsale',
            'password' => $specialPassword
        ]);
        
        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success'], 'Password with special chars should be accepted');
        $this->assertEquals(1, $result['id']);
        
        // Verify password works
        $employee = model(Employee::class);
        $admin = $employee->get_info(1);
        $this->assertTrue(password_verify($specialPassword, $admin->password));
        
        // Restore original password
        $employee->change_password([
            'username' => 'admin_casaletto',
            'password' => password_hash('pointofsale', PASSWORD_DEFAULT),
            'hash_version' => 2
        ], 1);
    }
    
    /**
     * Regression test: Verify previous vulnerable behavior is fixed
     * 
     * Before fix: 1-character passwords like "a" were accepted because
     * code checked len(hashed_password) which is always 60 for bcrypt
     * After fix: Raw password is validated before hashing
     * 
     * @return void
     */
    public function testPasswordMinLength_RejectsPreviousBehavior(): void
    {
        $this->resetSession();
        
        // Attempt the previously vulnerable case: single character password
        $response = $this->post('/home/save', [
            'employee_id' => 1,
            'username' => 'admin_casaletto',
            'current_password' => 'pointofsale',
            'password' => 'a' // Previously allowed due to bug
        ]);
        
        // This should now fail
        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success'], 'Single character password should be rejected (CVE fix)');
        $this->assertEquals(-1, $result['id']);
        
        // Verify password was NOT changed
        $employee = model(Employee::class);
        $admin = $employee->get_info(1);
        $this->assertTrue(password_verify('pointofsale', $admin->password), 
            'Single character password should be rejected (CVE fix)');
    }
    
    /**
     * Helper method to reset session
     * 
     * @return void
     */
    protected function resetSession(): void
    {
        $session = Services::session();
        $session->destroy();
        $session->set('person_id', 1); // Admin user

        // See loginAs() below for why this is required -- FeatureTestTrait::call()
        // ignores the real session service above and rebuilds $_SESSION from its
        // own $this->session property.
        $this->withSession(['person_id' => 1]);
    }
    
    /**
     * Create a non-admin employee for testing.
     *
     * @param array $overrides Optional overrides for username, email, password, etc.
     * @return int The person_id of the created employee
     */
    protected function createNonAdminEmployee(array $overrides = []): int
    {
        // Unique default username/email: this class has $refresh=false and
        // this CI4 version's DatabaseTestTrait doesn't wrap each test in its
        // own rollback transaction, so a fixed default would collide with
        // `username`'s UNIQUE constraint every time a second test calls this.
        $unique = uniqid();

        $personData = [
            'first_name'   => $overrides['first_name'] ?? 'NonAdmin',
            'last_name'    => $overrides['last_name'] ?? 'User',
            'email'        => $overrides['email'] ?? "nonadmin_$unique@test.com",
            'phone_number' => $overrides['phone_number'] ?? '555-1234'
        ];

        $employeeData = [
            'username'      => $overrides['username'] ?? "nonadmin_$unique",
            'password'      => password_hash($overrides['password'] ?? 'password123', PASSWORD_DEFAULT),
            'hash_version'  => 2,
            'language_code' => 'en',
            'language'      => 'english'
        ];

        // 'home' is a real, separately-toggleable module (not an implicit
        // baseline every employee gets) -- without it, Secure_Controller
        // blocks this employee from the Home controller entirely, before
        // any of postSave()'s own self-vs-other password logic ever runs.
        $grantsData = [
            ['permission_id' => 'home', 'menu_group' => 'home'],
            ['permission_id' => 'customers', 'menu_group' => 'home'],
            ['permission_id' => 'sales', 'menu_group' => 'home']
        ];

        $employeeModel = model(Employee::class);
        $employeeModel->save_employee($personData, $employeeData, $grantsData, NEW_ENTRY);

        $row = db_connect()->table('employees')->where('username', $employeeData['username'])->get()->getRow();

        return (int) $row->person_id;
    }
    
    /**
     * Login as a specific user
     * 
     * @param int $personId
     * @return void
     */
    protected function loginAs(int $personId): void
    {
        $session = Services::session();
        $session->destroy();
        $session->set('person_id', $personId);
        $session->set('menu_group', 'home');

        // FeatureTestTrait::call() ignores the real session service above --
        // it unconditionally overwrites $_SESSION with its own $this->session
        // property before dispatching the request (see populateGlobals(),
        // "$_SESSION = $this->session;"). Without this, every request below
        // runs with an empty session, Secure_Controller sees an anonymous
        // user and calls exit() (a real exit(), not a redirect/exception),
        // which silently kills the whole PHPUnit process with no test output
        // and a misleading exit 0.
        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);
    }
    
    // ========== BOLA Authorization Tests ==========
    
    /**
     * Test non-admin cannot view admin password change form
     * BOLA vulnerability fix: GHSA-q58g-gg7v-f9rf
     * 
     * @return void
     */
    public function testNonAdminCannotViewAdminPasswordForm(): void
    {
        $nonAdminId = $this->createNonAdminEmployee();
        $this->loginAs($nonAdminId);
        
        $response = $this->get('/home/changePassword/1');
        
        $response->assertStatus(403);
    }
    
    /**
     * Test non-admin cannot change admin password
     * BOLA vulnerability fix: GHSA-q58g-gg7v-f9rf
     * 
     * @return void
     */
    public function testNonAdminCannotChangeAdminPassword(): void
    {
        $nonAdminId = $this->createNonAdminEmployee();
        $this->loginAs($nonAdminId);
        
        $response = $this->post('/home/save/1', [
            'username' => 'admin_casaletto',
            'current_password' => 'pointofsale',
            'password' => 'hacked123'
        ]);
        
        $response->assertStatus(403);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
        
        // Verify admin password was NOT changed
        $employee = model(Employee::class);
        $admin = $employee->get_info(1);
        $this->assertTrue(password_verify('pointofsale', $admin->password), 
            'Admin password should not have been changed by non-admin');
    }
    
    /**
     * Test user can view their own password change form
     * 
     * @return void
     */
    public function testUserCanViewOwnPasswordForm(): void
    {
        $nonAdminId = $this->createNonAdminEmployee();
        $this->loginAs($nonAdminId);
        
        $response = $this->get('/home/changePassword/' . $nonAdminId);
        
        $response->assertStatus(200);
        $response->assertSee('nonadmin'); // Username should be visible
    }
    
    /**
     * Test user can change their own password
     * 
     * @return void
     */
    public function testUserCanChangeOwnPassword(): void
    {
        $nonAdminId = $this->createNonAdminEmployee(['username' => 'selfchanger']);
        $this->loginAs($nonAdminId);

        $response = $this->post('/home/save/' . $nonAdminId, [
            'username' => 'selfchanger',
            'current_password' => 'password123',
            'password' => 'newpassword123'
        ]);
        
        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);
        
        // Verify password was changed
        $employee = model(Employee::class);
        $user = $employee->get_info($nonAdminId);
        $this->assertTrue(password_verify('newpassword123', $user->password));
    }
    
    /**
     * Test admin can view any user's password form
     * 
     * @return void
     */
    public function testAdminCanViewAnyPasswordForm(): void
    {
        $nonAdminId = $this->createNonAdminEmployee();
        $this->resetSession(); // Login as admin
        
        $response = $this->get('/home/changePassword/' . $nonAdminId);
        
        $response->assertStatus(200);
        $response->assertSee('nonadmin');
    }
    
    /**
     * Test admin can change any user's password
     * 
     * @return void
     */
    public function testAdminCanChangeAnyPassword(): void
    {
        $nonAdminId = $this->createNonAdminEmployee(['username' => 'adminwillchange']);
        $this->resetSession(); // Login as admin

        $response = $this->post('/home/save/' . $nonAdminId, [
            'username' => 'adminwillchange',
            'current_password' => 'password123',
            'password' => 'adminset123'
        ]);
        
        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);
        
        // Verify password was changed
        $employee = model(Employee::class);
        $user = $employee->get_info($nonAdminId);
        $this->assertTrue(password_verify('adminset123', $user->password));
    }
    
    /**
     * Test default employee_id parameter uses current user
     * 
     * @return void
     */
    public function testDefaultEmployeeIdUsesCurrentUser(): void
    {
        $nonAdminId = $this->createNonAdminEmployee();
        $this->loginAs($nonAdminId);
        
        // Calling without employee_id should use current user
        $response = $this->get('/home/changePassword');
        
        $response->assertStatus(200);
        $response->assertSee('nonadmin');
    }

    /**
     * Test non-admin cannot view another non-admin's password form
     * IDOR vulnerability fix: GHSA-mcc2-8rp2-q6ch
     * 
     * @return void
     */
    public function testNonAdminCannotViewOtherNonAdminPasswordForm(): void
    {
        $nonAdminId1 = $this->createNonAdminEmployee();
        $this->loginAs($nonAdminId1);
        
        $otherUserId = $this->createNonAdminEmployee([
            'username' => 'otheruser',
            'email' => 'other@test.com',
            'password' => 'password456'
        ]);
        
        $response = $this->get('/home/changePassword/' . $otherUserId);
        
        $response->assertStatus(403);
    }

    /**
     * Test non-admin cannot change another non-admin's password
     * IDOR vulnerability fix: GHSA-mcc2-8rp2-q6ch
     * 
     * @return void
     */
    public function testNonAdminCannotChangeOtherNonAdminPassword(): void
    {
        $nonAdminId1 = $this->createNonAdminEmployee();
        $this->loginAs($nonAdminId1);
        
        $victimId = $this->createNonAdminEmployee([
            'username' => 'victimuser',
            'email' => 'victim@test.com',
            'password' => 'victimpass123'
        ]);
        
        $response = $this->post('/home/save/' . $victimId, [
            'username' => 'victimuser',
            'current_password' => 'victimpass123',
            'password' => 'hacked123456'
        ]);
        
        $response->assertStatus(403);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
        
        // Verify victim's password was NOT changed
        $employeeModel = model(Employee::class);
        $victim = $employeeModel->get_info($victimId);
        $this->assertTrue(password_verify('victimpass123', $victim->password), 
            'Non-admin should not be able to change another non-admin password');
    }
}