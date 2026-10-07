<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\Cashups;
use App\Models\Item;
use App\Models\Presale;
use App\Models\Presale_campaign;
use App\Models\Presale_payment;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\OSPOS;
use Config\Services;
use ReflectionMethod;
use stdClass;

/**
 * Cancelling a presale through its real routes (docs/Funcional/venta-anticipada.md §4.10, D10):
 * presales/cancel/{id}, presales/cancelPreview/{id} and presales/cancelReceipt/{id}.
 *
 * The rules are Presale::cancel()'s and the link to the register is pinned in
 * tests/Models/PresaleDeliveryLinkTest.php. What is pinned here is what the endpoint adds: the
 * presales_manage check on the server, the amounts read in the business's format, the refund landing
 * in the open shift and lowering its expected cash, and the document.
 *
 * SHARED DATABASE: own customer, item, open shift (open_date 2099, so it is the one the model picks)
 * and campaign, removed by their ids. Person 1 is granted `presales` and `presales_manage` only if it
 * did not hold them; a test that needs it WITHOUT presales_manage takes the grant away and tearDown
 * puts it back. presales_enable and presales_terms are restored. The session is re-armed before every
 * request, or Secure_Controller exits silently.
 *
 * @internal
 */
final class PresalesCancelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private const SHIFT_OPEN  = '2099-01-01 08:00:00';
    private const SHIFT_CLOSE = '2099-01-01 20:00:00';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';
    private int $customer_id;
    private int $cashup_id;
    private int $campaign_id;
    private int $date_id;
    private int $item_id;

    /**
     * @var array<string, string|null> app_config values before the test, null = absent
     */
    private array $configBefore = [];

    /**
     * @var list<string>
     */
    private array $grantedHere = [];

    /**
     * Grants of person 1 taken away by a test, to be put back.
     *
     * @var list<array<string, mixed>>
     */
    private array $revokedHere = [];

    /**
     * @var list<int>
     */
    private array $saleIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['presales_enable', 'presales_terms'] as $key) {
            $row                      = $this->db->table('app_config')->where('key', $key)->get()->getRow();
            $this->configBefore[$key] = $row === null ? null : (string) $row->value;
        }

        $this->setConfig('presales_enable', '1');
        $this->grant('presales');
        $this->grant('presales_manage');

        $this->customer_id = $this->makeCustomer();
        $this->cashup_id   = $this->openShift();
        $this->item_id     = $this->makeItem();

        $campaigns = model(Presale_campaign::class);
        $campaign  = $campaigns->save_campaign([
            'name'                => 'PRCANCEL-TEST Navidad',
            'sale_starts'         => $this->day(-5),
            'sale_ends'           => $this->day(40),
            'discount_percent'    => '0',
            'min_initial_percent' => '10',
            'active'              => 1,
        ], NEW_ENTRY, 1);

        $this->assertIsInt($campaign);
        $this->campaign_id = $campaign;

        $this->assertTrue($campaigns->add_date($this->campaign_id, $this->day(60)));
        $this->date_id = (int) $campaigns->get_dates($this->campaign_id)[0]['date_id'];
        $this->assertTrue($campaigns->add_item($this->campaign_id, $this->item_id));
    }

    protected function tearDown(): void
    {
        $ids = array_column($this->db->table('presales')->select('presale_id')->where('campaign_id', $this->campaign_id ?? 0)->get()->getResultArray(), 'presale_id');

        if ($ids !== []) {
            foreach (['presale_events', 'presale_payments', 'presale_installments', 'presale_items', 'presales'] as $table) {
                $this->db->table($table)->whereIn('presale_id', $ids)->delete();
            }
        }

        if ($this->saleIds !== []) {
            $this->db->table('sales')->whereIn('sale_id', $this->saleIds)->delete();
        }

        $this->db->table('presale_campaign_items')->where('campaign_id', $this->campaign_id ?? 0)->delete();
        $this->db->table('presale_campaign_dates')->where('campaign_id', $this->campaign_id ?? 0)->delete();
        $this->db->table('presale_campaigns')->where('campaign_id', $this->campaign_id ?? 0)->delete();
        $this->db->table('items')->where('item_id', $this->item_id ?? 0)->delete();
        $this->db->table('cash_up')->where('cashup_id', $this->cashup_id ?? 0)->delete();
        $this->db->table('customers')->where('person_id', $this->customer_id ?? 0)->delete();
        $this->db->table('people')->where('person_id', $this->customer_id ?? 0)->delete();

        foreach ($this->grantedHere as $permission) {
            $this->db->table('grants')->where('permission_id', $permission)->where('person_id', 1)->delete();
        }

        foreach ($this->revokedHere as $grant) {
            $this->db->table('grants')->replace($grant);
        }

        foreach ($this->configBefore as $key => $value) {
            if ($value === null) {
                $this->db->table('app_config')->where('key', $key)->delete();
            } else {
                $this->db->table('app_config')->replace(['key' => $key, 'value' => $value]);
            }
        }

        config(OSPOS::class)->update_settings();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    // Who may cancel
    // ---------------------------------------------------------------------------------------------

    /**
     * The button is hidden from a cashier without presales_manage, and the server refuses a forged
     * post all the same.
     */
    public function testWithoutPresalesManageTheCancellationIsRefusedAndNothingIsWritten(): void
    {
        $id = $this->register('30000');
        $this->revoke('presales_manage');

        $response = $this->postReq('presales/cancel/' . $id, ['reason' => 'Forjado', 'refund_amount' => '30000', 'payment_type_code' => 'cash']);

        $response->assertStatus(403);
        $this->assertFalse($this->json($response)['success']);
        $this->assertSame(esc(lang('Presales.cancel_forbidden')), $this->json($response)['message']);
        $this->assertNothingWritten($id);

        $this->postReq('presales/cancelPreview/' . $id, ['refund_amount' => '1'])->assertStatus(403);
        $this->assertStringNotContainsString('id="presale_cancel"', (string) $this->getReq('presales/view/' . $id)->getBody());
    }

    public function testWithTheModuleOffTheCancellationIsRefused(): void
    {
        $id = $this->register('30000');
        $this->setConfig('presales_enable', '0');

        $response = $this->postReq('presales/cancel/' . $id, ['reason' => 'Apagado', 'refund_amount' => '0']);

        $response->assertStatus(403);
        $this->assertSame(esc(lang('Presales.disabled')), $this->json($response)['message']);
        $this->assertNothingWritten($id);
    }

    public function testTheDetailOffersTheCancellationToWhoeverMayDoIt(): void
    {
        $body = (string) $this->getReq('presales/view/' . $this->register('30000'))->getBody();

        $this->assertStringContainsString('id="presale_cancel"', $body);
        $this->assertStringNotContainsString('disabled aria-disabled="true"', $body);
        $this->assertStringContainsString('presales/cancel/', $body);
    }

    // ---------------------------------------------------------------------------------------------
    // What is refused
    // ---------------------------------------------------------------------------------------------

    public function testAReasonIsRequired(): void
    {
        $id = $this->register('30000');

        $result = $this->postJson('presales/cancel/' . $id, ['reason' => '   ', 'refund_amount' => '0']);

        $this->assertFalse($result['success']);
        $this->assertSame(esc(lang('Presales.cancel_reason_required')), $result['message']);
        $this->assertNothingWritten($id);
    }

    public function testNoMoreThanWhatWasPaidCanBeGivenBack(): void
    {
        $id = $this->register('30000');

        $result = $this->postJson('presales/cancel/' . $id, ['reason' => 'Desistió', 'refund_amount' => '30001', 'payment_type_code' => 'cash']);

        $this->assertFalse($result['success']);
        $this->assertSame(esc(lang('Presales.refund_exceeds_paid')), $result['message']);
        $this->assertNothingWritten($id);
    }

    public function testATypedAmountThatIsNotANumberComesBackEscaped(): void
    {
        $id = $this->register('30000');

        $result = $this->postJson('presales/cancel/' . $id, ['reason' => 'Desistió', 'refund_amount' => '<b>x</b>', 'payment_type_code' => 'cash']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('&lt;b&gt;', $result['message']);
        $this->assertNothingWritten($id);
    }

    /**
     * The delivery is open in the register (a tab linked to it). Cancelling would leave that tab
     * behind with its presale payment, so it is refused with what to do instead.
     */
    public function testWhileTheDeliveryIsOpenInTheRegisterTheCancellationIsRefused(): void
    {
        $id   = $this->register('100000');
        $sale = $this->makeOpenSale();
        $this->assertTrue(model(Presale::class)->attach_delivery_sale($id, $sale, null));

        $result = $this->postJson('presales/cancel/' . $id, ['reason' => 'Desistió', 'refund_amount' => '100000', 'payment_type_code' => 'cash']);

        $this->assertFalse($result['success']);
        $this->assertSame(esc(lang('Presales.cancel_open_in_register')), $result['message']);
        $this->assertNothingWritten($id);
    }

    // ---------------------------------------------------------------------------------------------
    // What is recorded
    // ---------------------------------------------------------------------------------------------

    /**
     * Paid 30,000 in cash; 12,000 is given back in cash and the business keeps the rest. The refund
     * is a row of the open shift and the shift's expected cash goes down by exactly that much.
     */
    public function testAPartialCashRefundLeavesTheDrawerOfTheOpenShift(): void
    {
        $id     = $this->register('30000');
        $before = $this->reconcile();

        $result = $this->postJson('presales/cancel/' . $id, [
            'reason'            => 'El cliente se mudó',
            'refund_amount'     => $this->typedMoney('12000'),
            'payment_type_code' => 'cash',
            'reference_code'    => '',
        ]);

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertStringContainsString('presales/cancelReceipt/' . $id, $result['receipt_url']);

        $presale = model(Presale::class)->get_info($id);
        $this->assertSame(Presale::STATUS_CANCELED, $presale['status']);
        $this->assertSame('El cliente se mudó', $presale['cancel_reason']);

        $refunds = array_values(array_filter(model(Presale_payment::class)->get_for($id), static fn (array $row): bool => $row['kind'] === Presale_payment::KIND_REFUND));
        $this->assertCount(1, $refunds);
        $this->assertSame('12000.00', $refunds[0]['amount']);
        $this->assertSame('cash', $refunds[0]['payment_type_code']);
        $this->assertSame($this->cashup_id, (int) $refunds[0]['cashup_id']);

        $after = $this->reconcile();
        $this->assertEqualsWithDelta(-12_000.0, $after['expected'] - $before['expected'], 0.001);
        $this->assertEqualsWithDelta(-12_000.0, $after['presale_payments_total'] - $before['presale_payments_total'], 0.001);
    }

    /**
     * Nothing given back: no payment type is asked for, no money moves, and the business keeps it all.
     */
    public function testARefundOfZeroNeedsNoPaymentType(): void
    {
        $id = $this->register('30000');

        $result = $this->postJson('presales/cancel/' . $id, ['reason' => 'No volvió', 'refund_amount' => '0', 'payment_type_code' => 'not-a-type']);

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertSame(Presale::STATUS_CANCELED, model(Presale::class)->get_info($id)['status']);
        $this->assertSame(0, $this->db->table('presale_payments')->where('presale_id', $id)->where('kind', Presale_payment::KIND_REFUND)->countAllResults());
        $this->assertSame('30000.00', model(Presale_payment::class)->get_paid($id));
    }

    public function testThePreviewSaysWhatTheBusinessKeeps(): void
    {
        $id = $this->register('30000');

        $result = $this->postJson('presales/cancelPreview/' . $id, ['refund_amount' => $this->typedMoney('10000')]);

        $this->assertTrue($result['success']);
        $this->assertSame(esc(to_currency('30000')), $result['paid']);
        $this->assertSame(esc(to_currency('10000')), $result['refund']);
        $this->assertSame(esc(to_currency('20000')), $result['kept']);
        $this->assertTrue($result['has_refund']);

        $over = $this->postJson('presales/cancelPreview/' . $id, ['refund_amount' => '40000']);
        $this->assertFalse($over['success']);
        $this->assertSame(esc(lang('Presales.refund_exceeds_paid')), $over['message']);
    }

    /**
     * The document says what was paid, given back and kept; the reason and the business's conditions
     * are printed as text.
     */
    public function testTheCancellationDocumentPrintsTheFiguresAndTheReasonAsText(): void
    {
        $this->setConfig('presales_terms', "<b>Uno</b>\nDos");

        $id = $this->register('30000');
        $this->assertTrue($this->postJson('presales/cancel/' . $id, [
            'reason'            => "<i>Se mudo</i>\nde ciudad",
            'refund_amount'     => '10000',
            'payment_type_code' => 'bank_transfer',
            'reference_code'    => 'TRX-9',
        ])['success']);

        $response = $this->getReq('presales/cancelReceipt/' . $id);
        $response->assertStatus(200);
        $body = (string) $response->getBody();

        $this->assertStringContainsString(esc(lang('Presales.receipt_cancel')), $body);
        $this->assertStringContainsString(model(Presale::class)->number($id), $body);
        $this->assertMatchesRegularExpression('#id="presale_cancel_refunded">' . preg_quote(esc(to_currency('10000')), '#') . '<#', $body);
        $this->assertMatchesRegularExpression('#id="presale_cancel_kept">' . preg_quote(esc(to_currency('20000')), '#') . '<#', $body);
        $this->assertStringContainsString(esc(to_currency('30000')), $body);
        $this->assertStringContainsString('TRX-9', $body);
        $this->assertStringContainsString('&lt;i&gt;Se mudo&lt;/i&gt;<br', $body);
        $this->assertStringNotContainsString('<i>Se mudo</i>', $body);
        $this->assertStringContainsString('&lt;b&gt;Uno&lt;/b&gt;<br', $body);
        $this->assertStringNotContainsString('<b>Uno</b>', $body);
    }

    public function testAnOpenPresaleHasNoCancellationDocument(): void
    {
        $this->getReq('presales/cancelReceipt/' . $this->register('30000'))->assertStatus(404);
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------

    private function assertNothingWritten(int $presale_id): void
    {
        $this->assertSame(Presale::STATUS_OPEN, model(Presale::class)->get_info($presale_id)['status']);
        $this->assertSame(0, $this->db->table('presale_payments')->where('presale_id', $presale_id)->where('kind', Presale_payment::KIND_REFUND)->countAllResults());
        $this->assertSame(0, $this->db->table('presale_events')->where('presale_id', $presale_id)->where('event_type', 'canceled')->countAllResults());
    }

    /**
     * A presale of one unit at 100,000, with $initial paid now in cash in the open shift.
     */
    private function register(string $initial): int
    {
        $plan = $initial === '100000'
            ? [['due_date' => $this->day(0), 'amount' => '100000']]
            : [['due_date' => $this->day(0), 'amount' => $initial], ['due_date' => $this->day(30), 'amount' => bcsub('100000', $initial, 0)]];

        $id = model(Presale::class)->create([
            'campaign_id'      => $this->campaign_id,
            'customer_id'      => $this->customer_id,
            'delivery_date_id' => $this->date_id,
            'location_id'      => 1,
            'lines'            => [['item_id' => $this->item_id, 'quantity' => '1']],
            'installments'     => $plan,
            'payment'          => ['payment_type_code' => 'cash', 'amount' => $initial],
        ], 1, date('Y-m-d'));

        $this->assertIsInt($id, is_string($id) ? $id : '');

        return $id;
    }

    /**
     * Cashups::_build_reconciliation() of this file's open shift, as the close would compute it.
     */
    private function reconcile(): array
    {
        $session = Services::session();
        $session->set('person_id', 1);
        $session->set('menu_group', 'office');

        $controller = new Cashups();
        $method     = new ReflectionMethod($controller, '_build_reconciliation');

        $info                       = new stdClass();
        $info->cashup_id            = $this->cashup_id;
        $info->open_date            = self::SHIFT_OPEN;
        $info->close_date           = self::SHIFT_CLOSE;
        $info->open_amount_cash     = 0.0;
        $info->closed_amount_cash   = 0.0;
        $info->transfer_amount_cash = 0;

        return $method->invoke($controller, $info);
    }

    /**
     * An amount as the cashier types it, in the business's number format.
     */
    private function typedMoney(string $amount): string
    {
        return to_currency_no_money($amount);
    }

    private function day(int $offset): string
    {
        return date('Y-m-d', strtotime(sprintf('%+d days', $offset)));
    }

    private function getReq(string $path): TestResponse
    {
        $_SESSION = ['person_id' => 1, 'menu_group' => 'home'];
        $this->withSession($_SESSION);

        return $this->get($path);
    }

    private function postReq(string $path, array $params): TestResponse
    {
        $_SESSION = ['person_id' => 1, 'menu_group' => 'home'];
        $this->withSession($_SESSION);

        return $this->post($path, $params);
    }

    /**
     * @return array<string, mixed>
     */
    private function postJson(string $path, array $params): array
    {
        $response = $this->postReq($path, $params);
        $response->assertStatus(200);

        return $this->json($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(TestResponse $response): array
    {
        return json_decode((string) $response->getJSON(), true);
    }

    private function grant(string $permission): void
    {
        $exists = $this->db->table('grants')->where('permission_id', $permission)->where('person_id', 1)->countAllResults() > 0;

        if (! $exists) {
            $this->db->table('grants')->insert(['permission_id' => $permission, 'person_id' => 1, 'menu_group' => $permission === 'presales' ? 'home' : '--']);
            $this->grantedHere[] = $permission;
        }
    }

    /**
     * Takes a grant away from person 1 for this test; tearDown puts back whatever was there.
     */
    private function revoke(string $permission): void
    {
        $row = $this->db->table('grants')->where('permission_id', $permission)->where('person_id', 1)->get()->getRowArray();

        if ($row === null) {
            return;
        }

        if (in_array($permission, $this->grantedHere, true)) {
            $this->grantedHere = array_values(array_diff($this->grantedHere, [$permission]));
        } else {
            $this->revokedHere[] = $row;
        }

        $this->db->table('grants')->where('permission_id', $permission)->where('person_id', 1)->delete();
    }

    private function setConfig(string $key, string $value): void
    {
        $this->db->table('app_config')->replace(['key' => $key, 'value' => $value]);
        config(OSPOS::class)->update_settings();
    }

    private function makeCustomer(): int
    {
        $this->db->table('people')->insert([
            'first_name'   => 'Luz',
            'last_name'    => 'PRCANCEL-TEST',
            'phone_number' => '3005550101',
            'email'        => '',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
        ]);

        $person_id = (int) $this->db->insertID();

        $this->db->table('customers')->insert(['person_id' => $person_id, 'taxable' => 1, 'deleted' => 0, 'employee_id' => 1]);

        return $person_id;
    }

    private function openShift(): int
    {
        $this->db->table('cash_up')->insert([
            'status'               => 'open',
            'open_date'            => self::SHIFT_OPEN,
            'close_date'           => null,
            'open_amount_cash'     => '0.00',
            'transfer_amount_cash' => '0.00',
            'note'                 => 0,
            'closed_amount_cash'   => '0.00',
            'closed_amount_card'   => '0.00',
            'closed_amount_check'  => '0.00',
            'closed_amount_due'    => '0.00',
            'closed_amount_total'  => '0.00',
            'description'          => 'PresalesCancelTest fixture',
            'open_employee_id'     => 1,
            'close_employee_id'    => 1,
            'deleted'              => 0,
        ]);

        return (int) $this->db->insertID();
    }

    private function makeItem(): int
    {
        $this->db->table('items')->insert([
            'name'                  => 'PRCANCEL-TEST jamón',
            'category'              => 'Test',
            'item_number'           => null,
            'description'           => '',
            'cost_price'            => '0.00',
            'unit_price'            => '100000.00',
            'unit_of_measure'       => Item::UNIT_OF_MEASURE_UNIT,
            'reorder_level'         => '0',
            'receiving_quantity'    => '1',
            'allow_alt_description' => 0,
            'is_serialized'         => 0,
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * An open register tab: a sale with status OPENED.
     */
    private function makeOpenSale(): int
    {
        $this->db->table('sales')->insert([
            'sale_time'   => date('Y-m-d H:i:s'),
            'customer_id' => null,
            'employee_id' => 1,
            'comment'     => 'PRCANCEL-TEST',
            'sale_status' => OPENED,
        ]);

        $id              = (int) $this->db->insertID();
        $this->saleIds[] = $id;

        return $id;
    }
}
