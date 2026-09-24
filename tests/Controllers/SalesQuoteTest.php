<?php

namespace Tests\Controllers;

use App\Models\Appconfig;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\OSPOS;

/**
 * Quotes, as reviewed and fixed on 2026-09-24 (docs/Tecnico/cotizaciones.md).
 *
 * Nobody had ever made a quote in production. Making one on staging showed what these tests pin:
 * a quote refuses payments instead of storing them; it is a quote (type, status, number) and not a
 * sale; its document is titled and totalled as a quote and says until when it is valid; it can be
 * opened again and downloaded as a PDF after the cashier leaves the page.
 *
 * Built like SalesControllerTest: the register keeps its cart in the session, so getReq()/postReq()
 * re-arm the session from the live $_SESSION before every request. Tables are switched OFF here --
 * with them on, changing the register's mode without a table in the form clears the cart, which is
 * what the real form never does (it always posts the selected table).
 *
 * @internal
 */
final class SalesQuoteTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private string $itemNumber = 'TEST-QUOTE-ITEM';
    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect();
        $db->resetDataCache();

        model(Appconfig::class)->save(['dinner_table_enable' => '0']);
        model(Appconfig::class)->save(['invoice_enable' => '1']);
        config(OSPOS::class)->update_settings();

        $db->table('items')->insert([
            'name'                  => 'Test Quote Item',
            'category'              => 'Test',
            'item_number'           => $this->itemNumber,
            'description'           => 'Fixture item for SalesQuoteTest',
            'cost_price'            => '1.00',
            'unit_price'            => '10.00',
            'reorder_level'         => '0',
            'receiving_quantity'    => '1',
            'allow_alt_description' => 0,
            'is_serialized'         => 0,
        ]);
        $itemId = (int) $db->insertID();
        $db->table('item_quantities')->insert(['item_id' => $itemId, 'location_id' => 1, 'quantity' => '1000']);

        $db->table('people')->insert([
            'first_name' => 'Cliente', 'last_name' => 'Cotizacion', 'phone_number' => '', 'email' => '',
            'address_1'  => '', 'address_2' => '', 'city' => '', 'state' => '', 'zip' => '', 'country' => '', 'comments' => '',
        ]);
        $this->customerId = (int) $db->insertID();
        $db->table('customers')->insert(['person_id' => $this->customerId, 'taxable' => 1, 'discount' => 0, 'discount_type' => 0, 'deleted' => 0, 'consent' => 1, 'employee_id' => 1]);

        $_SESSION = ['person_id' => 1, 'menu_group' => 'home'];
        $this->withSession($_SESSION);
        $this->getReq('sales');
    }

    public function testAQuoteIsSavedAsAQuoteWithItsDocument(): void
    {
        $response = $this->makeQuote();

        $row = db_connect()->table('sales')->where('sale_type', SALE_TYPE_QUOTE)->orderBy('sale_id', 'DESC')->get(1)->getRowArray();

        $this->assertNotNull($row, 'Saved as a quote, not as a sale.');
        $this->assertSame(SUSPENDED, (int) $row['sale_status']);
        $this->assertMatchesRegularExpression('/^Q[0-9]{8}$/', (string) $row['quote_number'], 'Q + year + sequence, Western digits.');

        $response->assertSee(lang('Sales.quote_document'));
        $response->assertSee(lang('Sales.quote_total'));
        $response->assertSee(lang('Sales.quote_valid_until'));
        $response->assertSee(esc(to_date(strtotime('+15 days'))));
        $response->assertDontSee(lang('Sales.invoice_total'));
    }

    /**
     * Sale::save_value() stores the session's payments whatever the status, and deducts gift cards
     * and reward points. A quote with a payment is refused before anything is saved or numbered.
     */
    public function testAQuoteWithAPaymentIsRefusedAndNothingIsSaved(): void
    {
        // Counted before and after: with migrateOnce the database is not reset between the tests of
        // this class, and another one leaves a quote behind.
        $quotesBefore = db_connect()->table('sales')->where('sale_type', SALE_TYPE_QUOTE)->countAllResults();
        $numberBefore = model(Appconfig::class)->get_value('last_used_quote_number');

        $this->postReq('sales/changeMode', ['mode' => 'sale_quote']);
        $this->postReq('sales/add', ['item' => $this->itemNumber]);
        $this->postReq('sales/selectCustomer', ['customer' => (string) $this->customerId]);
        $this->postReq('sales/addPayment', ['payment_type' => lang('Sales.cash'), 'amount_tendered' => '5.00']);

        $this->postReq('sales/complete', [])->assertSee(lang('Sales.quote_no_payments'));

        $this->assertSame($quotesBefore, db_connect()->table('sales')->where('sale_type', SALE_TYPE_QUOTE)->countAllResults());
        $this->assertSame($numberBefore, model(Appconfig::class)->get_value('last_used_quote_number'), 'No number used up.');
    }

    /**
     * Before, once the cashier left the quote page there was no way to see or print it again.
     */
    public function testASavedQuoteCanBeOpenedAgainAndDownloadedAsPdf(): void
    {
        $this->makeQuote();
        $saleId = (int) db_connect()->table('sales')->where('sale_type', SALE_TYPE_QUOTE)->orderBy('sale_id', 'DESC')->get(1)->getRow()->sale_id;

        $again = $this->getReq('sales/quote/' . $saleId);
        $again->assertStatus(200);
        $again->assertSee(lang('Sales.quote_document'));
        // Discard acts on the quote the session just made, not on one opened from the list.
        $again->assertDontSee('id="discard_quote_button"');

        $pdf = $this->getReq('sales/quotePdf/' . $saleId);
        $this->assertSame('application/pdf', $pdf->response()->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->response()->getBody());
    }

    public function testASaleThatIsNotAQuoteIsNotShownAsOne(): void
    {
        $this->postReq('sales/add', ['item' => $this->itemNumber]);
        $this->postReq('sales/addPayment', ['payment_type' => lang('Sales.cash'), 'amount_tendered' => '10.00']);
        $this->postReq('sales/complete', []);

        $saleId = (int) db_connect()->table('sales')->where('sale_type', SALE_TYPE_POS)->orderBy('sale_id', 'DESC')->get(1)->getRow()->sale_id;

        $this->getReq('sales/quote/' . $saleId)->assertSee(lang('Sales.quote_not_found'));
        $this->getReq('sales/quotePdf/' . $saleId)->assertSee(lang('Sales.quote_not_found'));
    }

    private function makeQuote(): TestResponse
    {
        $this->postReq('sales/changeMode', ['mode' => 'sale_quote']);
        $this->postReq('sales/add', ['item' => $this->itemNumber]);
        $this->postReq('sales/selectCustomer', ['customer' => (string) $this->customerId]);

        return $this->postReq('sales/complete', []);
    }

    private function getReq(string $path): TestResponse
    {
        $this->withSession($_SESSION);

        return $this->get($path);
    }

    /**
     * @param array<string, string> $params
     */
    private function postReq(string $path, array $params): TestResponse
    {
        $this->withSession($_SESSION);

        return $this->post($path, $params);
    }
}
