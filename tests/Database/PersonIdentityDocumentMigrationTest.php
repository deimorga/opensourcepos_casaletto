<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\Migration_AddPersonIdentityDocument;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * people.document_type / people.document_number, and the copy of what customers and suppliers already
 * had in «Id Impuesto» (docs/Tecnico/documento-de-identidad.md IT1, IT5).
 *
 * SHARED DATABASE: only the people this file creates (last name IDDOC-MIGRATION) are touched, and
 * they are removed afterwards.
 *
 * @internal
 */
final class PersonIdentityDocumentMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    private const MARK = 'IDDOC-MIGRATION';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    protected function setUp(): void
    {
        parent::setUp();

        // Composer excludes app/Database/Migrations from the classmap: required by hand.
        require_once APPPATH . 'Database/Migrations/20261008040000_AddPersonIdentityDocument.php';

        $this->db->resetDataCache();
        $this->removeMine();
    }

    protected function tearDown(): void
    {
        $this->removeMine();

        parent::tearDown();
    }

    public function testThePeopleTableHasTheTwoColumnsAndTheirIndex(): void
    {
        $fields = [];

        foreach ($this->db->getFieldData('people') as $field) {
            $fields[$field->name] = $field;
        }

        $this->assertArrayHasKey('document_type', $fields);
        $this->assertArrayHasKey('document_number', $fields);
        $this->assertSame(8, (int) $fields['document_type']->max_length);
        $this->assertSame(32, (int) $fields['document_number']->max_length);
        $this->assertTrue((bool) $fields['document_type']->nullable);
        $this->assertTrue((bool) $fields['document_number']->nullable);

        $index = $this->db->getIndexData('people')[Migration_AddPersonIdentityDocument::INDEX] ?? null;
        $this->assertNotNull($index, 'The (document_type, document_number) index is missing.');
        $this->assertSame(['document_type', 'document_number'], $index->fields);
    }

    public function testACustomerTaxIdBecomesTheDocumentNumberWithoutType(): void
    {
        $person_id = $this->makeCustomer(' 900.123.456-8 ', 'IDDOC-ACC-1');

        $this->runQuietly();

        $person = $this->person($person_id);
        $this->assertSame('900.123.456-8', $person['document_number']);
        $this->assertNull($person['document_type']);

        $customer = $this->db->table('customers')->where('person_id', $person_id)->get()->getRowArray();
        $this->assertSame(' 900.123.456-8 ', $customer['tax_id'], 'tax_id is kept as it was: rolling back must lose nothing.');
        $this->assertSame('IDDOC-ACC-1', $customer['account_number'], 'The account number is not a document and is not touched.');
    }

    public function testASupplierTaxIdIsCopiedToo(): void
    {
        $person_id = $this->makeSupplier('800197268');

        $this->runQuietly();

        $this->assertSame('800197268', $this->person($person_id)['document_number']);
    }

    public function testADocumentAlreadyThereIsNotOverwritten(): void
    {
        $person_id = $this->makeCustomer('111', null);
        $this->db->table('people')->where('person_id', $person_id)->update(['document_type' => 'CC', 'document_number' => '222']);

        $this->runQuietly();

        $person = $this->person($person_id);
        $this->assertSame('CC', $person['document_type']);
        $this->assertSame('222', $person['document_number']);
    }

    public function testAnEmptyTaxIdLeavesTheDocumentEmpty(): void
    {
        $person_id = $this->makeCustomer('   ', null);

        $this->runQuietly();

        $this->assertNull($this->person($person_id)['document_number']);
    }

    public function testRunningItAgainChangesNothing(): void
    {
        $person_id = $this->makeCustomer('1020345678', null);

        $this->runQuietly();
        $this->runQuietly();

        $person = $this->person($person_id);
        $this->assertSame('1020345678', $person['document_number']);
        $this->assertNull($person['document_type']);
    }

    private function runQuietly(): void
    {
        ob_start();

        try {
            (new Migration_AddPersonIdentityDocument())->up();
        } finally {
            ob_end_clean();
        }
    }

    private function makePerson(): int
    {
        $this->db->table('people')->insert([
            'first_name'   => 'Persona',
            'last_name'    => self::MARK,
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

        return (int) $this->db->insertID();
    }

    private function makeCustomer(string $tax_id, ?string $account_number): int
    {
        $person_id = $this->makePerson();

        $this->db->table('customers')->insert([
            'person_id'      => $person_id,
            'taxable'        => 1,
            'deleted'        => 0,
            'employee_id'    => 1,
            'tax_id'         => $tax_id,
            'account_number' => $account_number,
        ]);

        return $person_id;
    }

    private function makeSupplier(string $tax_id): int
    {
        $person_id = $this->makePerson();

        $this->db->table('suppliers')->insert([
            'person_id'    => $person_id,
            'company_name' => self::MARK,
            'agency_name'  => '',
            'category'     => 0,
            'deleted'      => 0,
            'tax_id'       => $tax_id,
        ]);

        return $person_id;
    }

    /**
     * @return array<string, mixed>
     */
    private function person(int $person_id): array
    {
        return $this->db->table('people')->where('person_id', $person_id)->get()->getRowArray();
    }

    private function removeMine(): void
    {
        $ids = array_column($this->db->table('people')->select('person_id')->where('last_name', self::MARK)->get()->getResultArray(), 'person_id');

        if ($ids !== []) {
            $this->db->table('customers')->whereIn('person_id', $ids)->delete();
            $this->db->table('suppliers')->whereIn('person_id', $ids)->delete();
            $this->db->table('people')->whereIn('person_id', $ids)->delete();
        }
    }
}
