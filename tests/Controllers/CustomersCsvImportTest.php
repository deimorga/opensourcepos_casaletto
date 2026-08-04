<?php

namespace Tests\Controllers;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Config\Services;
use App\Models\Customer;
use App\Models\Employee;

class CustomersCsvImportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $migrateOnce = true;
    protected $refresh = false;
    protected $namespace = 'App';

    protected Customer $customer;
    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = model(Customer::class);
        $this->employee = model(Employee::class);

        helper('test');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    protected function loginAsEmployee(): void
    {
        $session = Services::session();
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

    /**
     * Finds a customer by exact email. Email lives on `people`, not
     * `customers` (see Customer::check_email_exists() for the same join),
     * so a bare $this->customer->where('email', ...) fails with an
     * "Unknown column" error.
     */
    protected function findCustomerByEmail(string $email): ?object
    {
        $builder = $this->customer->builder();
        $builder->join('people', 'people.person_id = customers.person_id');
        $builder->where('people.email', $email);

        return $builder->get()->getRow();
    }

    /**
     * Finds a customer whose email ends with the given suffix -- used to
     * look up a row whose exact email was sanitized/changed by the import
     * (e.g. stripped of XSS/quote characters) so it can't be matched exactly.
     */
    protected function findCustomerByEmailSuffix(string $suffix): ?object
    {
        $builder = $this->customer->builder();
        $builder->join('people', 'people.person_id = customers.person_id');
        $builder->like('people.email', $suffix, 'before');

        return $builder->get()->getRow();
    }

    protected function createCsvFile(array $rows): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'csv_test_');
        
        $handle = fopen($tempFile, 'w');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return $tempFile;
    }

    public function testValidEmailIsAccepted(): void
    {
        $this->loginAsEmployee();

        $csvContent = [
            ['First Name', 'Last Name', 'Gender', 'Consent', 'Email', 'Phone', 'Address 1', 'Address 2', 'City', 'State', 'Zip', 'Country', 'Comments', 'Company', 'Account Number', 'Discount', 'Discount Type', 'Taxable'],
            ['John', 'Doe', '1', '1', 'john.doe@example.com', '555-1234', '123 Main St', '', 'Springfield', 'IL', '62701', 'US', '', '', '', '', '', '']
        ];

        $tempFile = $this->createCsvFile($csvContent);

        $_FILES['file_path'] = [
            'name' => 'test.csv',
            'type' => 'text/csv',
            'tmp_name' => $tempFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tempFile)
        ];

        $result = $this->post('/customers/importCsvFile');

        $result->assertOK();
        $result->assertJSONExact(['success' => true, 'message' => lang('Customers.csv_import_success')]);

        $importedCustomer = $this->findCustomerByEmail('john.doe@example.com');
        $this->assertNotNull($importedCustomer);

        unlink($tempFile);
    }

    public function testInvalidEmailIsRejected(): void
    {
        $this->loginAsEmployee();

        $csvContent = [
            ['First Name', 'Last Name', 'Gender', 'Consent', 'Email', 'Phone', 'Address 1', 'Address 2', 'City', 'State', 'Zip', 'Country', 'Comments', 'Company', 'Account Number', 'Discount', 'Discount Type', 'Taxable'],
            ['John', 'Doe', '1', '1', 'not-an-email', '555-1234', '123 Main St', '', 'Springfield', 'IL', '62701', 'US', '', '', '', '', '', '']
        ];

        $tempFile = $this->createCsvFile($csvContent);

        $_FILES['file_path'] = [
            'name' => 'test.csv',
            'type' => 'text/csv',
            'tmp_name' => $tempFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tempFile)
        ];

        $result = $this->post('/customers/importCsvFile');

        $result->assertOK();
        
        $resultBody = json_decode($result->getJSON(), true);
        $this->assertFalse($resultBody['success'], 'Import should fail for invalid email');
        $this->assertStringContainsString('Row 1', $resultBody['message'], 'Error message should reference failing row');
        $this->assertStringContainsString('Invalid email format', $resultBody['message'], 'Error message should mention email validation');

        $importedCustomer = $this->findCustomerByEmail('not-an-email');
        $this->assertNull($importedCustomer, 'Customer with invalid email should not be imported');

        unlink($tempFile);
    }

    public function testXssPayloadInEmailIsSanitized(): void
    {
        $this->loginAsEmployee();

        $maliciousEmail = '<script>alert("xss")</script>@example.com';

        $csvContent = [
            ['First Name', 'Last Name', 'Gender', 'Consent', 'Email', 'Phone', 'Address 1', 'Address 2', 'City', 'State', 'Zip', 'Country', 'Comments', 'Company', 'Account Number', 'Discount', 'Discount Type', 'Taxable'],
            ['John', 'Doe', '1', '1', $maliciousEmail, '555-1234', '123 Main St', '', 'Springfield', 'IL', '62701', 'US', '', '', '', '', '', '']
        ];

        $tempFile = $this->createCsvFile($csvContent);

        $_FILES['file_path'] = [
            'name' => 'test.csv',
            'type' => 'text/csv',
            'tmp_name' => $tempFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tempFile)
        ];

        $result = $this->post('/customers/importCsvFile');

        $result->assertOK();

        $importedCustomer = $this->findCustomerByEmailSuffix('example.com');

        $this->assertNotNull($importedCustomer, 'Customer should be imported after sanitization');
        $this->assertStringNotContainsString('<script>', $importedCustomer->email, 'Script tags should be removed');
        $this->assertStringNotContainsString('</script>', $importedCustomer->email, 'Script tags should be removed');

        unlink($tempFile);
    }

    public function testMixedValidAndInvalidEmails(): void
    {
        $this->loginAsEmployee();

        $csvContent = [
            ['First Name', 'Last Name', 'Gender', 'Consent', 'Email', 'Phone', 'Address 1', 'Address 2', 'City', 'State', 'Zip', 'Country', 'Comments', 'Company', 'Account Number', 'Discount', 'Discount Type', 'Taxable'],
            ['Valid', 'User', '1', '1', 'valid@example.com', '555-1111', '123 Main St', '', 'City1', 'ST', '12345', 'US', '', '', '', '', '', ''],
            ['Invalid', 'User', '1', '1', 'invalid-email', '555-2222', '456 Oak Ave', '', 'City2', 'ST', '23456', 'US', '', '', '', '', '', ''],
            ['Another', 'Valid', '1', '1', 'another@example.com', '555-3333', '789 Pine Rd', '', 'City3', 'ST', '34567', 'US', '', '', '', '', '', '']
        ];

        $tempFile = $this->createCsvFile($csvContent);

        $_FILES['file_path'] = [
            'name' => 'test.csv',
            'type' => 'text/csv',
            'tmp_name' => $tempFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tempFile)
        ];

        $result = $this->post('/customers/importCsvFile');

        $result->assertOK();

        $validCustomer1 = $this->findCustomerByEmail('valid@example.com');
        $this->assertNotNull($validCustomer1, 'Valid customer should be imported');

        $validCustomer2 = $this->findCustomerByEmail('another@example.com');
        $this->assertNotNull($validCustomer2, 'Another valid customer should be imported');

        $invalidCustomer = $this->findCustomerByEmail('invalid-email');
        $this->assertNull($invalidCustomer, 'Invalid email customer should not be imported');

        unlink($tempFile);
    }

    public function testEmailWithSpecialCharactersIsSanitized(): void
    {
        $this->loginAsEmployee();

        $emailWithSpecialChars = 'test"user@example.com';
        $csvContent = [
            ['First Name', 'Last Name', 'Gender', 'Consent', 'Email', 'Phone', 'Address 1', 'Address 2', 'City', 'State', 'Zip', 'Country', 'Comments', 'Company', 'Account Number', 'Discount', 'Discount Type', 'Taxable'],
            ['Test', 'User', '1', '1', $emailWithSpecialChars, '555-1234', '123 Main St', '', 'Springfield', 'IL', '62701', 'US', '', '', '', '', '', '']
        ];

        $tempFile = $this->createCsvFile($csvContent);

        $_FILES['file_path'] = [
            'name' => 'test.csv',
            'type' => 'text/csv',
            'tmp_name' => $tempFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tempFile)
        ];

        $result = $this->post('/customers/importCsvFile');

        $result->assertOK();

        $importedCustomer = $this->findCustomerByEmailSuffix('example.com');

        $this->assertNotNull($importedCustomer, 'Sanitized email should be imported');
        $this->assertStringNotContainsString('"', $importedCustomer->email, 'Quote characters should be sanitized');

        unlink($tempFile);
    }

    public function testEmptyEmailIsAccepted(): void
    {
        $this->loginAsEmployee();

        // Empty email should be allowed - customers may not have email addresses.
        // Distinct name from other tests in this class: DatabaseTestTrait here
        // has $refresh=false and doesn't wrap each test in its own rollback
        // transaction, so rows from earlier tests (e.g. the "John Doe" from
        // testValidEmailIsAccepted) are still present and would otherwise be
        // matched by a name-only lookup below.
        $csvContent = [
            ['First Name', 'Last Name', 'Gender', 'Consent', 'Email', 'Phone', 'Address 1', 'Address 2', 'City', 'State', 'Zip', 'Country', 'Comments', 'Company', 'Account Number', 'Discount', 'Discount Type', 'Taxable'],
            ['EmptyEmail', 'Tester', '1', '1', '', '555-1234', '123 Main St', '', 'Springfield', 'IL', '62701', 'US', '', '', '', '', '', '']
        ];

        $tempFile = $this->createCsvFile($csvContent);

        $_FILES['file_path'] = [
            'name' => 'test.csv',
            'type' => 'text/csv',
            'tmp_name' => $tempFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tempFile)
        ];

        $result = $this->post('/customers/importCsvFile');

        $result->assertOK();

        $resultBody = json_decode($result->getJSON(), true);
        $this->assertTrue($resultBody['success'], 'Import should succeed with empty email');

        // Find customer by name since email is empty
        $builder = $this->customer->builder();
        $builder->select('customers.*, people.*');
        $builder->join('people', 'people.person_id = customers.person_id');
        $builder->where('first_name', 'EmptyEmail');
        $builder->where('last_name', 'Tester');
        $importedCustomer = $builder->get()->getRow();

        $this->assertNotNull($importedCustomer, 'Customer with empty email should be imported');
        $this->assertEquals('', $importedCustomer->email, 'Email should be empty string');

        unlink($tempFile);
    }
}