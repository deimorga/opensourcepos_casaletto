<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Models\Customer;
use App\Models\Employee;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\OSPOS;

/**
 * Type and number of document of customers, employees and suppliers, through the real screens
 * (docs/Tecnico/documento-de-identidad.md §5).
 *
 * SHARED DATABASE: every person created here carries the last name Iddocprueba and is removed with its
 * role rows and grants; the grants and settings this file needs are put back as they were. No table
 * is truncated.
 *
 * @internal
 */
final class PersonIdentityDocumentTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private const MARK         = 'Iddocprueba';
    private const TOUCHED_KEYS = ['presales_enable'];

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    /**
     * @var list<string>
     */
    private array $granted_here = [];

    /**
     * @var array<string, string|null>
     */
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();

        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();

        foreach (self::TOUCHED_KEYS as $key) {
            $row                  = $this->db->table('app_config')->where('key', $key)->get()->getRow();
            $this->previous[$key] = $row === null ? null : (string) $row->value;
        }

        $this->removeMine();

        foreach (['customers', 'employees', 'suppliers'] as $permission) {
            $this->grant($permission);
        }
    }

    protected function tearDown(): void
    {
        $this->removeMine();

        foreach ($this->granted_here as $permission) {
            $this->db->table('grants')->where('permission_id', $permission)->where('person_id', 1)->delete();
        }

        foreach ($this->previous as $key => $value) {
            if ($value === null) {
                $this->db->table('app_config')->where('key', $key)->delete();
            } else {
                $this->db->table('app_config')->replace(['key' => $key, 'value' => $value]);
            }
        }

        config(OSPOS::class)->update_settings();

        parent::tearDown();
    }

    // ---- Customers: required, cleaned, unique among customers -----------------------------------

    public function testACustomerWithoutDocumentIsRefused(): void
    {
        $result = $this->saveCustomer('Sin', ['document_type' => '', 'document_number' => '']);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Common.document_type_required'), $result['message']);
        $this->assertNull($this->personByFirstName('Sin'), 'Nothing is written when the document is missing.');
    }

    public function testACustomerWithANumberButNoTypeIsRefused(): void
    {
        $result = $this->saveCustomer('Sintipo', ['document_type' => '', 'document_number' => '1020345678']);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Common.document_type_required'), $result['message']);
    }

    public function testACedulaTypedWithDotsIsStoredClean(): void
    {
        $result = $this->saveCustomer('Puntos', ['document_type' => 'CC', 'document_number' => '1.020.345.601']);

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $person = $this->personByFirstName('Puntos');
        $this->assertSame('CC', $person['document_type']);
        $this->assertSame('1020345601', $person['document_number']);
    }

    public function testAPassportWithLettersIsAccepted(): void
    {
        $result = $this->saveCustomer('Pasaporte', ['document_type' => 'PA', 'document_number' => 'ab123602']);

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertSame('AB123602', $this->personByFirstName('Pasaporte')['document_number']);
    }

    public function testACedulaWithLettersIsRefused(): void
    {
        $result = $this->saveCustomer('Letras', ['document_type' => 'CC', 'document_number' => '10203A5603']);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Common.document_number_invalid'), $result['message']);
    }

    public function testANitWithItsRightCheckDigitIsStoredWithoutIt(): void
    {
        $result = $this->saveCustomer('Nitbueno', ['document_type' => 'NIT', 'document_number' => '890.903.938-8']);

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertSame('890903938', $this->personByFirstName('Nitbueno')['document_number']);
    }

    public function testANitWithAWrongCheckDigitIsRefused(): void
    {
        $result = $this->saveCustomer('Nitmalo', ['document_type' => 'NIT', 'document_number' => '890903938-5']);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Common.document_nit_check_digit_wrong'), $result['message']);
    }

    public function testASecondCustomerWithTheSameDocumentIsRefusedWhateverTheDots(): void
    {
        $this->assertTrue($this->saveCustomer('Primero', ['document_type' => 'CC', 'document_number' => '1020345604'])['success']);

        $result = $this->saveCustomer('Segundo', ['document_type' => 'CC', 'document_number' => '1.020.345.604']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Primero', $result['message'], 'The refusal names who already holds it.');
        $this->assertNull($this->personByFirstName('Segundo'));
    }

    public function testTheSameNumberWithAnotherTypeIsAnotherDocument(): void
    {
        $this->assertTrue($this->saveCustomer('Cedula', ['document_type' => 'CC', 'document_number' => '1020345605'])['success']);

        $this->assertTrue($this->saveCustomer('Tarjeta', ['document_type' => 'TI', 'document_number' => '1020345605'])['success']);
    }

    public function testEditingACustomerKeepsItsOwnDocument(): void
    {
        $this->saveCustomer('Edita', ['document_type' => 'CC', 'document_number' => '1020345606']);
        $person_id = (int) $this->personByFirstName('Edita')['person_id'];

        $result = $this->saveCustomer('Edita', ['document_type' => 'CC', 'document_number' => '1020345606', 'phone_number' => '3001234567'], $person_id);

        $this->assertTrue($result['success'], $result['message'] ?? '');
    }

    public function testADeletedCustomerDoesNotBlockItsDocument(): void
    {
        $this->saveCustomer('Borrado', ['document_type' => 'CC', 'document_number' => '1020345607']);
        $this->db->table('customers')->where('person_id', $this->personByFirstName('Borrado')['person_id'])->update(['deleted' => 1]);

        $result = $this->saveCustomer('Nuevo', ['document_type' => 'CC', 'document_number' => '1020345607']);

        $this->assertTrue($result['success'], $result['message'] ?? '');
    }

    public function testAnOldCustomerWithoutTypeIsAskedForItWhenEdited(): void
    {
        $person_id = $this->makeOldCustomer('Antiguo', '1.020.345.608');

        $result = $this->saveCustomer('Antiguo', ['document_type' => '', 'document_number' => '1.020.345.608'], $person_id);
        $this->assertFalse($result['success']);
        $this->assertSame(lang('Common.document_type_required'), $result['message']);

        $result = $this->saveCustomer('Antiguo', ['document_type' => 'CC', 'document_number' => '1.020.345.608'], $person_id);
        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertSame('1020345608', $this->personByFirstName('Antiguo')['document_number']);
    }

    public function testAnOldCustomerWithoutTypeStillHoldsItsNumber(): void
    {
        $this->makeOldCustomer('Viejo', '1.020.345.609');

        $result = $this->saveCustomer('Copia', ['document_type' => 'CC', 'document_number' => '1020345609']);

        $this->assertFalse($result['success'], 'An old customer with the same number, saved before types existed, is the same customer.');
        $this->assertStringContainsString('Viejo', $result['message']);
    }

    public function testTheCustomerFormAsksForTheDocumentAndNoLongerForTheTaxId(): void
    {
        $body = (string) $this->send('get', 'customers/view')->getBody();

        $this->assertMatchesRegularExpression('/<select[^>]*name="document_type"[^>]*required/', $body);
        $this->assertMatchesRegularExpression('/<input[^>]*name="document_number"[^>]*required/', $body);
        $this->assertStringNotContainsString('name="tax_id"', $body);
        $this->assertStringContainsString('customers/checkDocument', $body);
    }

    // ---- Across roles: allowed, with a warning ---------------------------------------------------

    public function testACustomerWithTheDocumentOfAnEmployeeIsSavedWithAWarning(): void
    {
        $this->makeEmployee('Empleada', 'CC', '1020345610');

        $result = $this->saveCustomer('Clienta', ['document_type' => 'CC', 'document_number' => '1020345610']);

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertSame(lang('Common.document_also_employees', ['Empleada ' . self::MARK]), $result['warning']);
        $this->assertNotNull($this->personByFirstName('Clienta'));
    }

    public function testTheWarningEscapesTheName(): void
    {
        $this->makeEmployee('<b>Xss</b>', 'CC', '1020345611');

        $result = $this->saveCustomer('Escapada', ['document_type' => 'CC', 'document_number' => '1020345611']);

        $this->assertStringNotContainsString('<b>', $result['warning']);
        $this->assertStringContainsString('&lt;b&gt;', $result['warning']);
    }

    public function testTheCheckEndpointRefusesARepeatAndWarnsAcrossRoles(): void
    {
        $this->saveCustomer('Chequeo', ['document_type' => 'CC', 'document_number' => '1020345612']);
        $this->makeEmployee('Chequeado', 'CC', '1020345613');

        $repeat = $this->send_json('post', 'customers/checkDocument', ['person_id' => '-1', 'document_type' => 'CC', 'document_number' => '1.020.345.612']);
        $this->assertFalse($repeat['valid']);
        $this->assertStringContainsString('Chequeo', $repeat['message']);

        $other = $this->send_json('post', 'customers/checkDocument', ['person_id' => '-1', 'document_type' => 'CC', 'document_number' => '1020345613']);
        $this->assertTrue($other['valid']);
        $this->assertStringContainsString('Chequeado', $other['warning']);

        $own = $this->send_json('post', 'customers/checkDocument', ['person_id' => (string) $this->personByFirstName('Chequeo')['person_id'], 'document_type' => 'CC', 'document_number' => '1020345612']);
        $this->assertTrue($own['valid'], 'The customer being edited does not collide with itself.');
    }

    // ---- Suppliers: optional --------------------------------------------------------------------

    public function testASupplierWithoutDocumentIsAccepted(): void
    {
        $result = $this->saveSupplier('Proveedor', ['document_type' => '', 'document_number' => '']);

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $person = $this->personByFirstName('Proveedor');
        $this->assertNull($person['document_type']);
        $this->assertNull($person['document_number']);
    }

    public function testASupplierWithANitIsUniqueAmongSuppliers(): void
    {
        $this->assertTrue($this->saveSupplier('Distribuidor', ['document_type' => 'NIT', 'document_number' => '800197268'])['success']);

        $result = $this->saveSupplier('Repetido', ['document_type' => 'NIT', 'document_number' => '800.197.268-4']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Distribuidor', $result['message']);
    }

    public function testSavingASupplierDoesNotTouchItsOldTaxId(): void
    {
        $this->saveSupplier('Impuesto', ['document_type' => 'NIT', 'document_number' => '899999068']);
        $person_id = (int) $this->personByFirstName('Impuesto')['person_id'];
        $this->db->table('suppliers')->where('person_id', $person_id)->update(['tax_id' => '899999068-1']);

        $this->saveSupplier('Impuesto', ['document_type' => 'NIT', 'document_number' => '899999068'], $person_id);

        $this->assertSame('899999068-1', $this->db->table('suppliers')->where('person_id', $person_id)->get()->getRow()->tax_id);
    }

    // ---- Employees: required, support exempt ------------------------------------------------------

    public function testANewEmployeeWithoutDocumentIsRefused(): void
    {
        $result = $this->saveEmployee('Nuevoempleado', ['document_type' => '', 'document_number' => '']);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Common.document_type_required'), $result['message']);
        $this->assertNull($this->personByFirstName('Nuevoempleado'));
    }

    public function testAnExistingEmployeeWithoutDocumentIsAskedForItWhenEdited(): void
    {
        $person_id = $this->makeEmployee('Existente', null, null);

        $result = $this->saveEmployee('Existente', ['document_type' => '', 'document_number' => ''], $person_id);
        $this->assertFalse($result['success']);

        $result = $this->saveEmployee('Existente', ['document_type' => 'CE', 'document_number' => '456614'], $person_id);
        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertSame('CE', $this->personByFirstName('Existente')['document_type']);
    }

    public function testTwoEmployeesCannotShareADocument(): void
    {
        $this->makeEmployee('Uno', 'CC', '1020345615');

        $result = $this->saveEmployee('Dos', ['document_type' => 'CC', 'document_number' => '1020345615']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Uno', $result['message']);
    }

    public function testThePlatformSupportEmployeeNeedsNoDocument(): void
    {
        $person_id = $this->makeEmployee('Soporte', null, null);
        $this->db->table('employees')->where('person_id', $person_id)->update(['is_platform_support' => 1]);

        $result = $this->saveEmployee('Soporte', ['document_type' => '', 'document_number' => ''], $person_id);

        $this->assertTrue($result['success'], $result['message'] ?? '');
    }

    public function testChangingTheOwnPasswordNeedsNoDocument(): void
    {
        $person_id = $this->makeEmployee('Clave', null, null, 'password123');

        $session = Services::session();
        $session->destroy();
        $session->set('person_id', $person_id);
        $session->set('menu_group', 'home');
        $this->withSession(['person_id' => $person_id, 'menu_group' => 'home']);

        $response = $this->post('home/save/' . $person_id, [
            'username'         => 'iddoc_clave',
            'current_password' => 'password123',
            'password'         => 'newpassword123',
        ]);

        $result = json_decode((string) $response->getJSON(), true);
        $this->assertTrue($result['success'], $result['message'] ?? '');
    }

    // ---- Search by number ---------------------------------------------------------------------------

    public function testACustomerIsFoundByItsNumberWithOrWithoutDots(): void
    {
        $this->saveCustomer('Buscable', ['document_type' => 'CC', 'document_number' => '1020345616']);
        $person_id = (int) $this->personByFirstName('Buscable')['person_id'];
        $customer  = model(Customer::class);

        $this->assertContains($person_id, array_map('intval', array_column($customer->get_search_suggestions('1020345616'), 'value')));
        $this->assertContains($person_id, array_map('intval', array_column($customer->get_search_suggestions('1.020.345.616'), 'value')));
        $this->assertContains($person_id, array_map('intval', array_column($customer->search('1.020.345.616')->getResultArray(), 'person_id')));
        $this->assertSame(1, $customer->get_found_rows('1.020.345.616'));
    }

    public function testAnOldCustomerIsFoundByItsNumberTypedWithoutDots(): void
    {
        $person_id = $this->makeOldCustomer('Buscaviejo', '1.020.345.617');

        $found = array_map('intval', array_column(model(Customer::class)->get_search_suggestions('1020345617'), 'value'));

        $this->assertContains($person_id, $found);
    }

    public function testTheRegisterAndPresalesFindTheCustomerByNumber(): void
    {
        $this->saveCustomer('Caja', ['document_type' => 'CC', 'document_number' => '1020345618']);
        $person_id = (string) $this->personByFirstName('Caja')['person_id'];

        $register = $this->send_json('get', 'customers/suggest?term=1.020.345.618');
        $this->assertContains($person_id, array_map('strval', array_column($register, 'value')));

        $this->db->table('app_config')->replace(['key' => 'presales_enable', 'value' => '1']);
        config(OSPOS::class)->update_settings();
        $this->grant('presales');

        $presales = $this->send_json('get', 'presales/suggestCustomer?term=1020345618');
        $this->assertContains($person_id, array_map('strval', array_column($presales, 'value')));
    }

    // ---- Customers CSV import ---------------------------------------------------------------------

    public function testTheCsvTemplateEndsWithTheTwoDocumentColumns(): void
    {
        $header = str_getcsv(strtok((string) file_get_contents(WRITEPATH . 'uploads/importCustomers.csv'), "\n"), ',', '"', '\\');

        $this->assertSame('Taxable', $header[17], 'The columns a business already fills keep their positions.');
        $this->assertSame('Document Type', $header[18]);
        $this->assertSame('Document Number', $header[19]);
    }

    public function testTheCsvImportTakesGoodRowsAndRefusesTheRestWithTheirReason(): void
    {
        $this->saveCustomer('Existia', ['document_type' => 'CC', 'document_number' => '1020345620']);

        $result = $this->importCsv([
            $this->csvRow('Csvbueno', 'cc', '1.020.345.621'),
            $this->csvRow('Csvsin', '', ''),
            $this->csvRow('Csvexistia', 'CC', '1020345620'),
            $this->csvRow('Csvrepetido', 'CC', '1020345621'),
            $this->csvRow('Csvnit', 'NIT', '890903938-5'),
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString(lang('Customers.csv_row_error', [2, lang('Common.document_type_required')]), $result['message']);
        $this->assertStringContainsString(lang('Customers.csv_row_error', [3, lang('Common.document_duplicate_customers', ['Existia ' . self::MARK])]), $result['message']);
        $this->assertStringContainsString(lang('Customers.csv_row_error', [4, lang('Common.document_duplicate_customers', ['Csvbueno ' . self::MARK])]), $result['message'], 'Two rows of one file with the same document: the second is refused.');
        $this->assertStringContainsString(lang('Customers.csv_row_error', [5, lang('Common.document_nit_check_digit_wrong')]), $result['message']);

        $good = $this->personByFirstName('Csvbueno');
        $this->assertNotNull($good);
        $this->assertSame('CC', $good['document_type']);
        $this->assertSame('1020345621', $good['document_number']);

        foreach (['Csvsin', 'Csvexistia', 'Csvrepetido', 'Csvnit'] as $refused) {
            $this->assertNull($this->personByFirstName($refused), $refused . ' should not have been imported.');
        }
    }

    /**
     * @return list<string>
     */
    private function csvRow(string $first_name, string $type, string $number): array
    {
        return [$first_name, self::MARK, '1', '1', '', '', '', '', '', '', '', '', '', '', '', '', '', '', $type, $number];
    }

    /**
     * @param list<list<string>> $rows
     *
     * @return array<string, mixed>
     */
    private function importCsv(array $rows): array
    {
        $file   = tempnam(sys_get_temp_dir(), 'iddoc_csv_');
        $handle = fopen($file, 'w');
        fputcsv($handle, ['First Name', 'Last Name', 'Gender', 'Consent', 'Email', 'Phone', 'Address 1', 'Address 2', 'City', 'State', 'Zip', 'Country', 'Comments', 'Company', 'Account Number', 'Discount', 'Discount Type', 'Taxable', 'Document Type', 'Document Number'], ',', '"', '\\');

        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }

        fclose($handle);

        $_FILES['file_path'] = ['name' => 'clientes.csv', 'type' => 'text/csv', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)];

        try {
            return $this->send_json('post', 'customers/importCsvFile');
        } finally {
            unset($_FILES['file_path']);
            unlink($file);
        }
    }

    // ---- Helpers ------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, mixed>
     */
    private function saveCustomer(string $first_name, array $overrides, int $person_id = NEW_ENTRY): array
    {
        $config = config(OSPOS::class)->settings;

        return $this->send_json('post', 'customers/save' . ($person_id === NEW_ENTRY ? '' : '/' . $person_id), array_merge([
            'first_name'     => $first_name,
            'last_name'      => self::MARK,
            'email'          => '',
            'phone_number'   => '',
            'address_1'      => '',
            'address_2'      => '',
            'city'           => '',
            'state'          => '',
            'zip'            => '',
            'country'        => '',
            'comments'       => '',
            'account_number' => '',
            'company_name'   => '',
            'discount'       => '',
            'discount_type'  => (string) PERCENT,
            'consent'        => '1',
            'date'           => date($config['dateformat'] . ' ' . $config['timeformat']),
            'employee_id'    => '1',
        ], $overrides));
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, mixed>
     */
    private function saveSupplier(string $first_name, array $overrides, int $person_id = NEW_ENTRY): array
    {
        return $this->send_json('post', 'suppliers/save' . ($person_id === NEW_ENTRY ? '' : '/' . $person_id), array_merge([
            'company_name'   => $first_name . ' SAS',
            'agency_name'    => '',
            'category'       => '0',
            'first_name'     => $first_name,
            'last_name'      => self::MARK,
            'email'          => '',
            'phone_number'   => '',
            'address_1'      => '',
            'address_2'      => '',
            'city'           => '',
            'state'          => '',
            'zip'            => '',
            'country'        => '',
            'comments'       => '',
            'account_number' => '',
        ], $overrides));
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, mixed>
     */
    private function saveEmployee(string $first_name, array $overrides, int $person_id = NEW_ENTRY): array
    {
        return $this->send_json('post', 'employees/save' . ($person_id === NEW_ENTRY ? '' : '/' . $person_id), array_merge([
            'first_name'   => $first_name,
            'last_name'    => self::MARK,
            'email'        => '',
            'phone_number' => '',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
            'username'     => 'iddoc_' . strtolower(preg_replace('/\W/', '', $first_name)) . '_' . substr(uniqid(), -6),
            'language'     => 'es-MX:spanish',
        ], $overrides));
    }

    /**
     * An employee written straight through the model, as the provisioning and the support command do.
     */
    private function makeEmployee(string $first_name, ?string $type, ?string $number, string $password = 'password123'): int
    {
        $person   = ['first_name' => $first_name, 'last_name' => self::MARK, 'email' => '', 'phone_number' => '', 'document_type' => $type, 'document_number' => $number];
        $employee = ['username' => 'iddoc_' . strtolower(preg_replace('/\W/', '', $first_name)), 'password' => password_hash($password, PASSWORD_DEFAULT), 'hash_version' => 2, 'language_code' => 'es-MX', 'language' => 'spanish'];
        $grants   = [];

        model(Employee::class)->save_employee($person, $employee, $grants, NEW_ENTRY);

        return (int) $person['person_id'];
    }

    /**
     * A customer as the migration leaves the ones created before document types: a number, no type.
     */
    private function makeOldCustomer(string $first_name, string $number): int
    {
        $this->db->table('people')->insert([
            'first_name'      => $first_name,
            'last_name'       => self::MARK,
            'document_number' => $number,
            'phone_number'    => '',
            'email'           => '',
            'address_1'       => '',
            'address_2'       => '',
            'city'            => '',
            'state'           => '',
            'zip'             => '',
            'country'         => '',
            'comments'        => '',
        ]);
        $person_id = (int) $this->db->insertID();

        $this->db->table('customers')->insert(['person_id' => $person_id, 'taxable' => 1, 'deleted' => 0, 'employee_id' => 1]);

        return $person_id;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function personByFirstName(string $first_name): ?array
    {
        return $this->db->table('people')->where('first_name', $first_name)->where('last_name', self::MARK)->get()->getRowArray();
    }

    /**
     * Re-arms the session before every request: without it Secure_Controller calls a real exit() and
     * the PHPUnit process dies with no output (see PresaleCertificationFixesTest::get_as()).
     *
     * @param array<string, string> $data
     */
    private function send(string $method, string $uri, array $data = [])
    {
        $session = Services::session();
        $session->destroy();
        $session->set('person_id', 1);
        $session->set('menu_group', 'office');
        $this->withSession(['person_id' => 1, 'menu_group' => 'office']);

        $response = $method === 'post' ? $this->post($uri, $data) : $this->get($uri);
        $response->assertStatus(200);

        return $response->response();
    }

    /**
     * @param array<string, string> $data
     *
     * @return array<mixed>
     */
    private function send_json(string $method, string $uri, array $data = []): array
    {
        $decoded = json_decode((string) $this->send($method, $uri, $data)->getBody(), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function grant(string $permission): void
    {
        $exists = $this->db->table('grants')->where('permission_id', $permission)->where('person_id', 1)->countAllResults() > 0;

        if (! $exists) {
            $this->db->table('grants')->insert(['permission_id' => $permission, 'person_id' => 1, 'menu_group' => 'both']);
            $this->granted_here[] = $permission;
        }
    }

    private function removeMine(): void
    {
        $ids = array_column($this->db->table('people')->select('person_id')->where('last_name', self::MARK)->get()->getResultArray(), 'person_id');

        if ($ids === []) {
            return;
        }

        $this->db->table('grants')->whereIn('person_id', $ids)->delete();
        $this->db->table('customers')->whereIn('person_id', $ids)->delete();
        $this->db->table('employees')->whereIn('person_id', $ids)->delete();
        $this->db->table('suppliers')->whereIn('person_id', $ids)->delete();
        $this->db->table('people')->whereIn('person_id', $ids)->delete();
    }
}
