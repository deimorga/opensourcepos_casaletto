<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\Sales;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\OSPOS;
use ReflectionMethod;

/**
 * The identity document on paper (docs/Tecnico/documento-de-identidad.md IT6): «CC 1020345678» or
 * «NIT 890903938-8» where «Id Impuesto: …» used to be; an old customer without type prints the number
 * alone; no number, no line.
 *
 * Receipts, invoices, quotes and work orders all take the customer block from
 * Sales::_load_customer_data(), so that is what is checked; the presales documents print it
 * themselves, and the payment receipt is rendered to check it.
 *
 * SHARED DATABASE: only the customers this file creates (last name Iddocimpreso) are removed.
 *
 * @internal
 */
final class IdentityDocumentPrintTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    private const MARK = 'Iddocimpreso';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    protected function setUp(): void
    {
        parent::setUp();

        db_connect()->resetDataCache();
        config(OSPOS::class)->update_settings();
        $this->removeMine();
    }

    protected function tearDown(): void
    {
        $this->removeMine();

        parent::tearDown();
    }

    public function testTheCustomerBlockCarriesTheTypeAndNumber(): void
    {
        $data = $this->customerBlock($this->makeCustomer('CC', '1020345630'));

        $this->assertSame('CC 1020345630', $data['customer_document']);
        $this->assertStringContainsString("\nCC 1020345630", $data['customer_info']);
        $this->assertStringNotContainsString(lang('Sales.tax_id'), $data['customer_info'], '«Id Impuesto» is no longer printed.');
    }

    public function testANitIsPrintedWithItsCheckDigit(): void
    {
        $data = $this->customerBlock($this->makeCustomer('NIT', '890903938'));

        $this->assertStringContainsString('NIT 890903938-8', $data['customer_info']);
    }

    public function testAnOldCustomerWithoutTypePrintsTheNumberAlone(): void
    {
        $data = $this->customerBlock($this->makeCustomer(null, '900.123.456-8'));

        $this->assertSame('900.123.456-8', $data['customer_document']);
        $this->assertStringContainsString("\n900.123.456-8", $data['customer_info']);
    }

    public function testNoDocumentPrintsNoLine(): void
    {
        $data = $this->customerBlock($this->makeCustomer(null, null));

        $this->assertSame('', $data['customer_document']);
        // Name, address and location, as before: nothing is added after them.
        $this->assertSame('Cliente ' . self::MARK . "\n\n", $data['customer_info']);
    }

    public function testTheReceiptsShowTheDocumentUnderTheCustomer(): void
    {
        foreach (['receipt_default', 'receipt_short', 'receipt_email'] as $receipt) {
            $source = (string) file_get_contents(APPPATH . 'Views/sales/' . $receipt . '.php');

            $this->assertStringContainsString('<div id="customer_document"><?= esc($customer_document) ?></div>', $source, $receipt);
        }
    }

    public function testThePresalePaymentReceiptPrintsTheDocument(): void
    {
        $this->assertStringContainsString('<td class="r">CC 1020345631</td>', $this->presaleReceipt('CC', '1020345631'));
        $this->assertStringContainsString('<td class="r">900.123.456-8</td>', $this->presaleReceipt(null, '900.123.456-8'));
        $this->assertStringNotContainsString('<th>' . esc(lang('Common.document')) . '</th>', $this->presaleReceipt(null, null));
    }

    public function testEveryPresaleDocumentPrintsTheDocument(): void
    {
        foreach (['receipt_presale', 'receipt_payment', 'receipt_cancel'] as $receipt) {
            $source = (string) file_get_contents(APPPATH . 'Views/presales/' . $receipt . '.php');

            $this->assertStringContainsString('Identity_document::format($customer->document_type ?? null, $customer->document_number ?? null)', $source, $receipt);
            $this->assertStringContainsString('<?= esc($customer_document) ?>', $source, $receipt);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function customerBlock(int $customer_id): array
    {
        $session = Services::session();
        $session->set('person_id', 1);
        $session->set('menu_group', 'home');

        $controller = new Sales();
        $method     = new ReflectionMethod($controller, '_load_customer_data');
        $data       = [];
        $method->invokeArgs($controller, [$customer_id, &$data]);

        return $data;
    }

    private function presaleReceipt(?string $type, ?string $number): string
    {
        helper(['locale', 'payment_type', 'url', 'form']);

        $customer = (object) ['first_name' => 'Cliente', 'last_name' => self::MARK, 'phone_number' => '', 'document_type' => $type, 'document_number' => $number];

        return view('presales/receipt_payment', [
            'presale'       => ['number' => 'PV-1', 'delivery_date' => '2026-12-20', 'total' => '100.00'],
            'payment'       => ['payment_time' => '2026-10-08 10:00:00', 'amount' => '50.00', 'payment_type_code' => 'cash', 'reference_code' => ''],
            'customer'      => $customer,
            'campaign_name' => 'Navidad',
            'employee'      => '',
            'accumulated'   => '50.00',
            'balance'       => '50.00',
            'print'         => false,
            'open_drawer'   => false,
            'config'        => config(OSPOS::class)->settings,
        ], ['saveData' => false]);
    }

    private function makeCustomer(?string $type, ?string $number): int
    {
        $this->db->table('people')->insert([
            'first_name'      => 'Cliente',
            'last_name'       => self::MARK,
            'document_type'   => $type,
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

    private function removeMine(): void
    {
        $ids = array_column($this->db->table('people')->select('person_id')->where('last_name', self::MARK)->get()->getResultArray(), 'person_id');

        if ($ids !== []) {
            $this->db->table('customers')->whereIn('person_id', $ids)->delete();
            $this->db->table('people')->whereIn('person_id', $ids)->delete();
        }
    }
}
