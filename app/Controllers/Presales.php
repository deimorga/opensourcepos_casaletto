<?php

namespace App\Controllers;

use App\Libraries\Sale_lib;
use App\Models\Cashup;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Presale;
use App\Models\Presale_campaign;
use App\Models\Presale_event;
use App\Models\Presale_payment;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\ResponseInterface;
use Config\OSPOS;

/**
 * The presales screens a cashier uses: the list, registering a presale, taking instalments, printing
 * the documents and sending a paid presale to the register to be delivered.
 *
 * Campaigns live in PresaleCampaigns, behind the presales_manage subpermission. Two controllers and not
 * one so the two pieces of work can be built in parallel without touching the same file.
 *
 * THE SWITCH
 *
 * presales_enable is read with ?? '0' on every request: an absent switch is an off switch. Off, the
 * module is not usable; the menu tile can still show for a granted employee, so the screen says plainly
 * that the business has it switched off instead of answering with an error. Every action below asks
 * before doing anything.
 *
 * Every rule about money, dates and prices is enforced in App\Models\Presale, never here. This
 * controller reads the form, normalises numbers with parse_decimals() and dates with
 * parse_typed_datetime(), and hands over. Prices are never read from the form.
 *
 * Messages that go back as JSON are escaped here: the screens show them with $.notify(), which renders
 * HTML, and some of them repeat what the cashier typed.
 *
 * Every view here, and every partial they include, is rendered with saveData => false. CodeIgniter's
 * renderer keeps view data for the rest of the request by default, and a nested partial saves its
 * parent's data too: a `customer` left behind by the detail made the register's view, rendered later
 * in the same test process, believe a customer was selected and fail on an undefined $customer_id.
 *
 * See docs/Funcional/venta-anticipada.md and docs/Tecnico/venta-anticipada.md.
 */
class Presales extends Secure_Controller
{
    /**
     * Session key that lets the next instalment receipt open the drawer, once. See postAddPayment().
     */
    private const DRAWER_FLASH = 'presales_open_drawer';

    /**
     * What the drawer mark says after a cash refund, in place of a payment id. See postCancel().
     */
    private const DRAWER_CANCEL = 'cancel';

    protected Presale $presale;

    public function __construct()
    {
        parent::__construct('presales');

        $this->presale = model(Presale::class);

        helper('presales');
    }

    // ---------------------------------------------------------------------------------------------
    // The list
    // ---------------------------------------------------------------------------------------------

    public function getIndex(): string
    {
        if (! self::is_enabled()) {
            return view('presales/disabled');
        }

        return view('presales/manage', [
            'table_headers' => get_presales_manage_table_headers(),
            'campaigns'     => model(Presale_campaign::class)->get_all(),
            'dates'         => $this->delivery_dates(),
            'states'        => self::state_options(),
        ], ['saveData' => false]);
    }

    /**
     * The rows of the list, a page at a time.
     *
     * Three queries whatever the page size: the page itself with each presale's paid amount and what
     * was due by today worked out in SQL (so the state filter, the count and the paging agree), the
     * count, and the instalments of the presales on the page. The state shown is then Presale::derive()'s,
     * the one definition of it; the SQL only mirrors it for filtering.
     */
    public function getSearch(): ResponseInterface
    {
        if (! self::is_enabled()) {
            return $this->response->setJSON(['total' => 0, 'rows' => []]);
        }

        $today = date('Y-m-d');

        $filters = [
            'search'        => trim((string) $this->request->getGet('search')),
            'campaign_id'   => (int) $this->request->getGet('campaign_id'),
            'delivery_date' => (string) $this->request->getGet('delivery_date'),
            'states'        => array_values(array_intersect((array) ($this->request->getGet('states') ?? []), array_keys(self::state_options()))),
        ];

        $limit  = max(1, min(500, (int) ($this->request->getGet('limit') ?? 25)));
        $offset = max(0, (int) $this->request->getGet('offset'));
        $sort   = $this->sanitizeSortColumn(presales_headers(), $this->request->getGet('sort'), 'number');
        $order  = strtolower((string) $this->request->getGet('order')) === 'asc' ? 'ASC' : 'DESC';

        [$sql, $binds] = $this->search_sql($filters, $today);

        $total = (int) ($this->db()->query("SELECT COUNT(*) AS n FROM ({$sql}) AS t", $binds)->getRow()->n ?? 0);

        $order_by = match ($sort) {
            'customer'      => "t.last_name {$order}, t.first_name {$order}",
            'campaign'      => "t.campaign_name {$order}",
            'delivery_date' => "t.delivery_date {$order}",
            'total'         => "t.total {$order}",
            'paid'          => "t.paid {$order}",
            'balance'       => "(t.total - t.paid) {$order}",
            default         => "t.presale_id {$order}",
        };

        $rows = $this->db()->query(
            "SELECT t.* FROM ({$sql}) AS t ORDER BY {$order_by}, t.presale_id DESC LIMIT ? OFFSET ?",
            [...$binds, $limit, $offset],
        )->getResultArray();

        $installments = $this->installments_for(array_map('intval', array_column($rows, 'presale_id')));

        $data_rows = [];

        foreach ($rows as $row) {
            $id      = (int) $row['presale_id'];
            $derived = Presale::derive((string) $row['status'], (string) $row['total'], bcadd((string) $row['paid'], '0', 2), $installments[$id] ?? [], $today);

            $data_rows[] = get_presale_data_row($row, $derived, $this->presale->number($id));
        }

        return $this->response->setJSON(['total' => $total, 'rows' => $data_rows]);
    }

    // ---------------------------------------------------------------------------------------------
    // Registering
    // ---------------------------------------------------------------------------------------------

    public function getNew(): string
    {
        if (! self::is_enabled()) {
            return view('presales/disabled');
        }

        $person_id = (int) $this->employee->get_logged_in_employee_info()->person_id;

        helper('payment_type');

        return view('presales/form', [
            'campaigns'        => $this->selling_campaigns(date('Y-m-d')),
            'payment_types'    => self::payment_options(),
            'can_add_customer' => $this->employee->has_grant('customers', $person_id),
            'has_open_shift'   => model(Cashup::class)->get_open_cashup_id() !== null,
            'today'            => date(config(OSPOS::class)->settings['dateformat']),
        ], ['saveData' => false]);
    }

    /**
     * Customers for the registration form. Its own endpoint because customers/suggest needs the
     * `customers` permission, and a presales cashier does not necessarily have it.
     */
    public function getSuggestCustomer(): ResponseInterface
    {
        if (! self::is_enabled()) {
            return $this->response->setJSON([]);
        }

        $term = trim((string) $this->request->getGet('term'));

        if ($term === '') {
            return $this->response->setJSON([]);
        }

        $suggestions = model(Customer::class)->get_search_suggestions($term, 25, true);

        return $this->response->setJSON(array_slice($suggestions, 0, 25));
    }

    /**
     * The label of one customer, for the registration form after a customer is created in place.
     */
    public function getCustomer(int $customer_id): ResponseInterface
    {
        $customer = self::is_enabled() ? model(Customer::class)->get_info($customer_id) : null;

        if ($customer === null || empty($customer->person_id) || (int) ($customer->deleted ?? 0) === 1) {
            return $this->response->setStatusCode(404)->setJSON(['success' => false]);
        }

        $label = trim($customer->first_name . ' ' . $customer->last_name) . ($customer->phone_number !== '' && $customer->phone_number !== null ? ' [' . $customer->phone_number . ']' : '');

        return $this->response->setJSON(['success' => true, 'id' => (int) $customer->person_id, 'label' => $label]);
    }

    /**
     * What the form adds up to so far, worked out by the server: amounts are typed in the business's
     * number format and parse_decimals() is the only thing that reads them right, so the screen does
     * no arithmetic of its own. Same approach as the cash-up's closing form.
     *
     * The total is the one Presale::create() will store: what the register will charge at delivery,
     * taxes and rounding included (Presale::register_charge()). It depends on the customer (taxable or
     * not), so the form asks again when the customer changes; with no customer yet it is worked out
     * as for a walk-in customer, as the register would.
     */
    public function postPreview(): ResponseInterface
    {
        if (! self::is_enabled()) {
            return $this->response->setJSON(['success' => false]);
        }

        $campaign = model(Presale_campaign::class)->get_info((int) $this->request->getPost('campaign_id'));
        $lines    = $this->read_lines();

        if ($campaign === null || is_string($lines) || $lines === []) {
            return $this->response->setJSON(['success' => true, 'total' => to_currency('0'), 'lines' => [], 'plan' => null, 'minimum' => null]);
        }

        // The campaign's products, read once for every pricing below.
        $campaign_items = model(Presale_campaign::class)->get_items_by_id((int) $campaign['campaign_id']);
        $priced         = $this->presale->price_lines((int) $campaign['campaign_id'], $lines, $campaign_items);

        if (is_string($priced)) {
            return $this->response->setJSON(['success' => false, 'message' => esc(lang($priced))]);
        }

        // Each requested line's own amount, kit components included, in the order the form sent them.
        // For display: the total below is the register's, not the sum of these.
        $amounts = [];

        foreach ($lines as $index => $line) {
            $one = $this->presale->price_lines((int) $campaign['campaign_id'], [$line], $campaign_items);
            $sum = '0.00';

            foreach (is_array($one) ? $one : [] as $part) {
                $sum = bcadd($sum, $part['amount'], 2);
            }

            $amounts[$index] = to_currency($sum);
        }

        $customer_id = (int) $this->request->getPost('customer_id');
        $charge      = $this->presale->register_charge($priced, $customer_id > 0 ? $customer_id : NEW_ENTRY);

        if ($charge === null) {
            return $this->response->setJSON(['success' => false, 'message' => esc(lang('Presales.total_unavailable'))]);
        }

        $total = $charge['charge'];

        $installments = $this->read_installments();
        $plan         = null;

        if (is_array($installments) && $installments !== []) {
            $sum = '0.00';

            foreach ($installments as $installment) {
                $sum = bcadd($sum, $installment['amount'], 2);
            }

            $difference = bcsub($total, $sum, 2);
            $comparison = bccomp($difference, '0', 2);

            $plan = [
                'sum'     => to_currency($sum),
                'matches' => $comparison === 0,
                'message' => esc(match ($comparison) {
                    0       => lang('Presales.plan_matches'),
                    1       => lang('Presales.plan_missing', [to_currency($difference)]),
                    default => lang('Presales.plan_over', [to_currency(bcmul($difference, '-1', 2))]),
                }),
            ];
        } elseif (is_string($installments)) {
            $plan = ['sum' => '', 'matches' => false, 'message' => esc($installments)];
        }

        return $this->response->setJSON([
            'success' => true,
            'total'   => to_currency($total),
            'taxes'   => to_currency($charge['taxes']),
            'lines'   => $amounts,
            'plan'    => $plan,
            'minimum' => esc(lang('Presales.minimum_initial', [to_currency(Presale::minimum_initial($total, (string) $campaign['min_initial_percent']))])),
        ]);
    }

    /**
     * Registers the presale and takes its first instalment. All the rules are the model's.
     *
     * The parameter is only there because Secure_Controller declares postSave() with one: a presale
     * is never edited through here, so it is ignored.
     */
    public function postSave(int $data_item_id = NEW_ENTRY): ResponseInterface
    {
        if (! self::is_enabled()) {
            return $this->refuse(lang('Presales.disabled'));
        }

        $lines = $this->read_lines();

        if (is_string($lines)) {
            return $this->refuse($lines);
        }

        $installments = $this->read_installments();

        if (is_string($installments)) {
            return $this->refuse($installments);
        }

        $amount = $this->read_money((string) $this->request->getPost('payment_amount'));

        if ($amount === false) {
            return $this->refuse(lang('Presales.amount_invalid', [(string) $this->request->getPost('payment_amount')]));
        }

        $employee_id = (int) $this->employee->get_logged_in_employee_info()->person_id;

        $result = $this->presale->create([
            'campaign_id'      => (int) $this->request->getPost('campaign_id'),
            'customer_id'      => (int) $this->request->getPost('customer_id'),
            'delivery_date_id' => (int) $this->request->getPost('delivery_date_id'),
            'location_id'      => $this->sale_location($employee_id),
            'comment'          => (string) $this->request->getPost('comment'),
            'lines'            => $lines,
            'installments'     => $installments,
            'payment'          => [
                'payment_type_code' => (string) $this->request->getPost('payment_type_code'),
                'amount'            => $amount,
                'reference_code'    => (string) $this->request->getPost('reference_code'),
            ],
        ], $employee_id, date('Y-m-d'));

        if (is_string($result)) {
            return $this->refuse(lang($result));
        }

        $this->arm_drawer($result, null);

        return $this->response->setJSON([
            'success'     => true,
            'id'          => $result,
            'message'     => esc(lang('Presales.registered', [$this->presale->number($result)])),
            'receipt_url' => site_url('presales/receipt/' . $result) . '?print=1',
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // One presale
    // ---------------------------------------------------------------------------------------------

    /**
     * The detail, opened as a dialog from the list: lines, plan and state, payments, history, and the
     * actions that apply to it now.
     */
    public function getView(int $presale_id = NEW_ENTRY): string
    {
        if (! self::is_enabled()) {
            return '<div class="alert alert-info" role="status">' . esc(lang('Presales.disabled')) . '</div>';
        }

        $today   = date('Y-m-d');
        $summary = $this->presale->get_summary($presale_id, $today);

        if ($summary === null) {
            return '<div class="alert alert-warning" role="alert">' . esc(lang('Presales.not_found')) . '</div>';
        }

        helper('payment_type');

        $payments = model(Presale_payment::class)->get_for($presale_id);
        $events   = model(Presale_event::class)->get_for($presale_id);

        $people = $this->names(array_merge(
            [(int) $summary['customer_id'], (int) $summary['employee_id']],
            array_map('intval', array_column($payments, 'employee_id')),
            array_map('intval', array_column($events, 'employee_id')),
        ));

        $customer = model(Customer::class)->get_info((int) $summary['customer_id']);
        $campaign = model(Presale_campaign::class)->get_info((int) $summary['campaign_id']);

        return view('presales/detail', [
            'presale'        => $summary,
            'customer'       => $customer,
            'campaign_name'  => $campaign['name'] ?? '',
            'lines'          => $this->presale->get_lines($presale_id),
            'installments'   => self::plan_rows($this->presale->get_installments($presale_id), $summary['paid'], $today),
            'payments'       => $payments,
            'events'         => $events,
            'people'         => $people,
            'payment_types'  => self::payment_options(),
            'has_open_shift' => model(Cashup::class)->get_open_cashup_id() !== null,
            'can_manage'     => $this->can_manage(),
        ], ['saveData' => false]);
    }

    /**
     * Takes an instalment. The balance, the payment type, the open shift and the lock against two tills
     * at once are the model's (Presale::add_payment()).
     */
    public function postAddPayment(int $presale_id): ResponseInterface
    {
        if (! self::is_enabled()) {
            return $this->refuse(lang('Presales.disabled'));
        }

        $raw    = (string) $this->request->getPost('amount');
        $amount = $this->read_money($raw);

        if ($amount === false || $amount === '') {
            return $this->refuse(lang($amount === '' ? 'Presales.payment_amount_invalid' : 'Presales.amount_invalid', [$raw]));
        }

        $code = (string) $this->request->getPost('payment_type_code');

        $result = $this->presale->add_payment(
            $presale_id,
            $code,
            $amount,
            (int) $this->employee->get_logged_in_employee_info()->person_id,
            (string) $this->request->getPost('reference_code'),
        );

        if (is_string($result)) {
            return $this->refuse(lang($result));
        }

        $this->arm_drawer($presale_id, $result);

        return $this->response->setJSON([
            'success'     => true,
            'id'          => $presale_id,
            'message'     => esc(lang('Presales.payment_saved')),
            'receipt_url' => site_url('presales/paymentReceipt/' . $result) . '?print=1',
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // Cancelling (D10)
    // ---------------------------------------------------------------------------------------------

    /**
     * What the cancellation form adds up to: paid, the refund typed and what the business keeps,
     * worked out here because amounts are typed in the business's number format (see postPreview()).
     */
    public function postCancelPreview(int $presale_id): ResponseInterface
    {
        $denied = $this->deny_cancel();

        if ($denied !== null) {
            return $denied;
        }

        $presale = $this->presale->get_info($presale_id);

        if ($presale === null || $presale['status'] !== Presale::STATUS_OPEN) {
            return $this->refuse(lang('Presales.not_open'));
        }

        $paid   = model(Presale_payment::class)->get_paid($presale_id);
        $raw    = (string) $this->request->getPost('refund_amount');
        $refund = $this->read_money($raw);

        if ($refund === false) {
            return $this->refuse(lang('Presales.amount_invalid', [$raw]));
        }

        $refund = $refund === '' ? '0.00' : $refund;

        if (bccomp($refund, '0', 2) < 0) {
            return $this->refuse(lang('Presales.refund_invalid'));
        }

        if (bccomp($refund, $paid, 2) > 0) {
            return $this->refuse(lang('Presales.refund_exceeds_paid'));
        }

        return $this->response->setJSON([
            'success'    => true,
            'paid'       => esc(to_currency($paid)),
            'refund'     => esc(to_currency($refund)),
            'kept'       => esc(to_currency(bcsub($paid, $refund, 2))),
            'has_refund' => bccomp($refund, '0', 2) > 0,
        ]);
    }

    /**
     * Cancels a presale and records what was agreed with the customer: the reason, the refund (zero
     * to everything paid) and its payment type. Needs presales_manage, checked here and not only by
     * hiding the button. Every rule -- the reason, the limits, the open shift, the lock, a delivery
     * open in the register -- is Presale::cancel()'s.
     */
    public function postCancel(int $presale_id): ResponseInterface
    {
        $denied = $this->deny_cancel();

        if ($denied !== null) {
            return $denied;
        }

        $raw    = (string) $this->request->getPost('refund_amount');
        $refund = $this->read_money($raw);

        if ($refund === false) {
            return $this->refuse(lang('Presales.amount_invalid', [$raw]));
        }

        $refund = $refund === '' ? '0.00' : $refund;
        $code   = bccomp($refund, '0', 2) > 0 ? (string) $this->request->getPost('payment_type_code') : null;

        $result = $this->presale->cancel(
            $presale_id,
            (int) $this->employee->get_logged_in_employee_info()->person_id,
            (string) $this->request->getPost('reason'),
            $refund,
            $code,
            (string) $this->request->getPost('reference_code'),
        );

        if (is_string($result)) {
            return $this->refuse(lang($result));
        }

        if ($code === 'cash') {
            $this->arm_drawer($presale_id, null, self::DRAWER_CANCEL);
        }

        return $this->response->setJSON([
            'success'     => true,
            'id'          => $presale_id,
            'message'     => esc(lang('Presales.canceled', [$this->presale->number($presale_id)])),
            'receipt_url' => site_url('presales/cancelReceipt/' . $presale_id) . '?print=1',
        ]);
    }

    /**
     * The refusal of a cancellation endpoint, or null when it may go on: the module must be on and
     * the employee must hold presales_manage. 403, because these are called by script.
     */
    private function deny_cancel(): ?ResponseInterface
    {
        if (! self::is_enabled()) {
            return $this->refuse(lang('Presales.disabled'))->setStatusCode(403);
        }

        if (! $this->can_manage()) {
            return $this->refuse(lang('Presales.cancel_forbidden'))->setStatusCode(403);
        }

        return null;
    }

    // ---------------------------------------------------------------------------------------------
    // Documents
    // ---------------------------------------------------------------------------------------------

    /**
     * The presale document: what was agreed, the plan, what has been paid and the conditions. It is
     * the customer's "contract" on paper (docs/Funcional/venta-anticipada.md §4.3).
     */
    public function getReceipt(int $presale_id): ResponseInterface|string
    {
        $summary = self::is_enabled() ? $this->presale->get_summary($presale_id, date('Y-m-d')) : null;

        if ($summary === null) {
            return $this->response->setStatusCode(404)->setBody(esc(lang('Presales.not_found')));
        }

        $campaign = model(Presale_campaign::class)->get_info((int) $summary['campaign_id']);

        // On the paper, only the lines the register would print: a kit whose components are set not
        // to print shows as the kit.
        $lines = array_values(array_filter(
            $this->presale->get_lines($presale_id),
            static fn (array $line): bool => (int) $line['print_option'] === PRINT_YES,
        ));

        return view('presales/receipt_presale', [
            'presale'       => $summary,
            'customer'      => model(Customer::class)->get_info((int) $summary['customer_id']),
            'campaign_name' => $campaign['name'] ?? '',
            'lines'         => $lines,
            'installments'  => $this->presale->get_installments($presale_id),
            'print'         => $this->request->getGet('print') === '1',
            'open_drawer'   => $this->take_drawer($presale_id, null),
        ], ['saveData' => false]);
    }

    /**
     * The receipt of one instalment: this payment, what was paid up to and including it, and the
     * balance left after it. Worked out as of the payment, so a reprint next week says what it said
     * the day it was taken.
     */
    public function getPaymentReceipt(int $payment_id): ResponseInterface|string
    {
        $payment = self::is_enabled() ? model(Presale_payment::class)->find($payment_id) : null;

        if (! is_array($payment) || $payment['kind'] !== Presale_payment::KIND_PAYMENT) {
            return $this->response->setStatusCode(404)->setBody(esc(lang('Presales.not_found')));
        }

        $presale_id = (int) $payment['presale_id'];
        $presale    = $this->presale->get_info($presale_id);

        if ($presale === null) {
            return $this->response->setStatusCode(404)->setBody(esc(lang('Presales.not_found')));
        }

        $accumulated = '0.00';

        foreach (model(Presale_payment::class)->get_for($presale_id) as $movement) {
            if ((int) $movement['payment_id'] > $payment_id) {
                continue;
            }

            $accumulated = $movement['kind'] === Presale_payment::KIND_REFUND
                ? bcsub($accumulated, (string) $movement['amount'], 2)
                : bcadd($accumulated, (string) $movement['amount'], 2);
        }

        helper('payment_type');

        $campaign = model(Presale_campaign::class)->get_info((int) $presale['campaign_id']);

        return view('presales/receipt_payment', [
            'presale'       => $presale + ['number' => $this->presale->number($presale_id)],
            'payment'       => $payment,
            'customer'      => model(Customer::class)->get_info((int) $presale['customer_id']),
            'campaign_name' => $campaign['name'] ?? '',
            'employee'      => $this->names([(int) $payment['employee_id']])[(int) $payment['employee_id']] ?? '',
            'accumulated'   => $accumulated,
            'balance'       => bcsub((string) $presale['total'], $accumulated, 2),
            'print'         => $this->request->getGet('print') === '1',
            'open_drawer'   => $this->take_drawer($presale_id, $payment_id),
        ], ['saveData' => false]);
    }

    /**
     * The cancellation document: what had been paid, what was given back and how, what the business
     * keeps, the reason and the conditions. Read from the stored movements, so a reprint says what it
     * said on the day.
     */
    public function getCancelReceipt(int $presale_id): ResponseInterface|string
    {
        $presale = self::is_enabled() ? $this->presale->get_info($presale_id) : null;

        if ($presale === null || $presale['status'] !== Presale::STATUS_CANCELED) {
            return $this->response->setStatusCode(404)->setBody(esc(lang('Presales.not_found')));
        }

        $paid     = '0.00';
        $refunded = '0.00';
        $refunds  = [];

        foreach (model(Presale_payment::class)->get_for($presale_id) as $movement) {
            if ($movement['kind'] === Presale_payment::KIND_REFUND) {
                $refunded  = bcadd($refunded, (string) $movement['amount'], 2);
                $refunds[] = $movement;
            } else {
                $paid = bcadd($paid, (string) $movement['amount'], 2);
            }
        }

        helper('payment_type');

        $campaign    = model(Presale_campaign::class)->get_info((int) $presale['campaign_id']);
        $canceled_by = (int) ($presale['canceled_by'] ?? 0);

        return view('presales/receipt_cancel', [
            'presale'       => $presale + ['number' => $this->presale->number($presale_id)],
            'customer'      => model(Customer::class)->get_info((int) $presale['customer_id']),
            'campaign_name' => $campaign['name'] ?? '',
            'employee'      => $this->names([$canceled_by])[$canceled_by] ?? '',
            'paid'          => $paid,
            'refunded'      => $refunded,
            'kept'          => bcsub($paid, $refunded, 2),
            'refunds'       => $refunds,
            'print'         => $this->request->getGet('print') === '1',
            'open_drawer'   => $this->take_cancel_drawer($presale_id, $refunds),
        ], ['saveData' => false]);
    }

    // ---------------------------------------------------------------------------------------------
    // Shared answers
    // ---------------------------------------------------------------------------------------------

    /**
     * Whether the business has presales switched on. Absent reads as off.
     *
     * Public and static because the register, the cash-up and the reports ask the same question before
     * doing anything presale-related, and there must be one answer.
     */
    public static function is_enabled(): bool
    {
        return (string) (config(OSPOS::class)->settings['presales_enable'] ?? '0') === '1';
    }

    /**
     * Whether the logged-in employee holds presales_manage: campaigns and cancellations.
     */
    protected function can_manage(): bool
    {
        $employee = $this->employee->get_logged_in_employee_info();

        return is_object($employee) && $this->employee->has_grant('presales_manage', (int) $employee->person_id);
    }

    // ---------------------------------------------------------------------------------------------
    // Reading the forms
    // ---------------------------------------------------------------------------------------------

    /**
     * The product lines as the model wants them: item and quantity, nothing else. A quantity is read
     * with three decimals, the precision of the weight the register keeps.
     *
     * @return list<array{item_id: int, quantity: string}>|string
     */
    private function read_lines(): array|string
    {
        $lines = [];

        foreach ((array) ($this->request->getPost('lines') ?? []) as $line) {
            if (! is_array($line) || (int) ($line['item_id'] ?? 0) <= 0) {
                continue;
            }

            $raw      = trim((string) ($line['quantity'] ?? ''));
            $quantity = $raw === '' ? false : parse_decimals($raw, 3);

            if ($quantity === false || ! is_numeric($quantity)) {
                return lang('Presales.quantity_invalid');
            }

            $lines[] = ['item_id' => (int) $line['item_id'], 'quantity' => self::decimal_string((float) $quantity, 3)];
        }

        return $lines;
    }

    /**
     * The plan as the model wants it: due dates as Y-m-d and amounts normalised. Rows left completely
     * empty are skipped; a row half filled is an error the cashier has to see.
     *
     * @return list<array{due_date: string, amount: string}>|string
     */
    private function read_installments(): array|string
    {
        $plan = [];

        foreach ((array) ($this->request->getPost('installments') ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $typed_date   = trim((string) ($row['due_date'] ?? ''));
            $typed_amount = trim((string) ($row['amount'] ?? ''));

            if ($typed_date === '' && $typed_amount === '') {
                continue;
            }

            $date = parse_typed_datetime($typed_date, false);

            if ($date === false) {
                return typed_date_error($typed_date);
            }

            $amount = $this->read_money($typed_amount);

            if ($amount === false || $amount === '') {
                return lang('Presales.amount_invalid', [$typed_amount]);
            }

            $plan[] = ['due_date' => $date->format('Y-m-d'), 'amount' => $amount];
        }

        return $plan;
    }

    /**
     * An amount typed in the business's number format, as a plain decimal string; '' when nothing was
     * typed, false when it is not a number.
     */
    private function read_money(string $typed): false|string
    {
        $typed = trim($typed);

        if ($typed === '') {
            return '';
        }

        $value = parse_decimals($typed);

        if ($value === false || ! is_numeric($value)) {
            return false;
        }

        return self::decimal_string((float) $value, 2);
    }

    /**
     * A float from parse_decimals() as the plain decimal string bcmath and the models accept. Never
     * (string) $float: that gives "1.0E-5" for small numbers.
     */
    private static function decimal_string(float $value, int $scale): string
    {
        return number_format($value, $scale, '.', '');
    }

    // ---------------------------------------------------------------------------------------------
    // Data for the screens
    // ---------------------------------------------------------------------------------------------

    /**
     * The campaigns taking presales today, with what the form needs of each: its products with the
     * price a unit costs (a kit's components included) and its delivery dates.
     *
     * @return list<array<string, mixed>>
     */
    private function selling_campaigns(string $today): array
    {
        $campaigns = model(Presale_campaign::class);
        $result    = [];

        foreach ($campaigns->get_selling($today) as $campaign) {
            $campaign_id    = (int) $campaign['campaign_id'];
            $campaign_items = $campaigns->get_items_by_id($campaign_id);
            $items          = [];

            foreach ($campaign_items as $item) {
                if ((int) $item['item_deleted'] === 1) {
                    continue;
                }

                $unit = $this->presale->price_lines($campaign_id, [['item_id' => (int) $item['item_id'], 'quantity' => '1']], $campaign_items);

                // A kit that cannot be expanded is not offered: registering it would be refused anyway.
                if (is_string($unit)) {
                    continue;
                }

                $price = '0.00';

                foreach ($unit as $part) {
                    $price = bcadd($price, $part['amount'], 2);
                }

                $items[] = [
                    'item_id'   => (int) $item['item_id'],
                    'name'      => (string) $item['name'],
                    'number'    => (string) ($item['item_number'] ?? ''),
                    'price'     => to_currency($price),
                    'is_weight' => Item::unit_of_measure_is_weight($item['unit_of_measure'] ?? null),
                    'is_kit'    => (int) $item['item_type'] === ITEM_KIT,
                ];
            }

            $dates = [];

            foreach ($campaigns->get_dates($campaign_id) as $date) {
                // A day that has gone is not offered: Presale::create() refuses it.
                if ((string) $date['delivery_date'] < $today) {
                    continue;
                }

                $dates[] = ['date_id' => (int) $date['date_id'], 'label' => to_date(strtotime((string) $date['delivery_date']))];
            }

            $result[] = [
                'campaign_id' => $campaign_id,
                'name'        => (string) $campaign['name'],
                'items'       => $items,
                'dates'       => $dates,
            ];
        }

        return $result;
    }

    /**
     * Each instalment of the plan with whether what has been paid covers it, for the detail screen.
     * The same cumulative comparison as Presale::derive(): payments are not assigned to an instalment.
     *
     * @return list<array<string, mixed>>
     */
    private static function plan_rows(array $installments, string $paid, string $today): array
    {
        $cumulative = '0.00';
        $rows       = [];

        foreach ($installments as $installment) {
            $cumulative = bcadd($cumulative, (string) $installment['amount'], 2);

            $status = match (true) {
                bccomp($paid, $cumulative, 2) >= 0 => 'covered',
                $installment['due_date'] < $today  => 'overdue',
                default                            => 'pending',
            };

            $rows[] = $installment + ['coverage' => $status];
        }

        return $rows;
    }

    /**
     * The delivery dates presales were agreed for, for the list's filter.
     *
     * @return list<string> Y-m-d
     */
    private function delivery_dates(): array
    {
        return array_column(
            $this->db()->table('presales')->distinct()->select('delivery_date')->orderBy('delivery_date', 'asc')->get()->getResultArray(),
            'delivery_date',
        );
    }

    /**
     * @return array<string, string> state => label, for the list's filter
     */
    private static function state_options(): array
    {
        $options = [];

        foreach ([Presale::STATE_UP_TO_DATE, Presale::STATE_LATE, Presale::STATE_PAID, Presale::STATUS_DELIVERED, Presale::STATUS_CANCELED] as $state) {
            $options[$state] = lang('Presales.state_' . $state);
        }

        return $options;
    }

    /**
     * The payment types a presale accepts (T10), labelled in the active language.
     *
     * @return array<string, string>
     */
    private static function payment_options(): array
    {
        helper('payment_type');

        $options = [];

        foreach (Presale_payment::PAYMENT_CODES as $code) {
            $options[$code] = payment_type_label($code);
        }

        return $options;
    }

    /**
     * First and last name of each person, in one query.
     *
     * @param list<int> $person_ids
     *
     * @return array<int, string>
     */
    private function names(array $person_ids): array
    {
        $person_ids = array_values(array_unique(array_filter($person_ids)));

        if ($person_ids === []) {
            return [];
        }

        $names = [];

        foreach ($this->db()->table('people')->select('person_id, first_name, last_name')->whereIn('person_id', $person_ids)->get()->getResultArray() as $person) {
            $names[(int) $person['person_id']] = trim($person['first_name'] . ' ' . $person['last_name']);
        }

        return $names;
    }

    /**
     * The instalments of several presales, in one query, grouped by presale.
     *
     * @param list<int> $presale_ids
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function installments_for(array $presale_ids): array
    {
        if ($presale_ids === []) {
            return [];
        }

        $grouped = [];

        foreach ($this->db()->table('presale_installments')->whereIn('presale_id', $presale_ids)->get()->getResultArray() as $row) {
            $grouped[(int) $row['presale_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * The list's query, as SQL and its bindings, for the count and the page.
     *
     * paid and due_to_date are computed exactly as Presale::derive() reads them, and the state filter
     * mirrors derive()'s order: delivered and canceled are stored; an open presale with nothing left to
     * pay is paid; one that has paid less than what was due before today is late; otherwise up to date.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function search_sql(array $filters, string $today): array
    {
        $db = $this->db();

        $presales     = $db->prefixTable('presales');
        $payments     = $db->prefixTable('presale_payments');
        $installments = $db->prefixTable('presale_installments');
        $campaigns    = $db->prefixTable('presale_campaigns');
        $people       = $db->prefixTable('people');

        $refund = Presale_payment::KIND_REFUND;

        $where = ['1 = 1'];
        $binds = [$today];

        if ($filters['campaign_id'] > 0) {
            $where[] = 'p.campaign_id = ?';
            $binds[] = $filters['campaign_id'];
        }

        if (Presale_campaign::is_date($filters['delivery_date'])) {
            $where[] = 'p.delivery_date = ?';
            $binds[] = $filters['delivery_date'];
        }

        if ($filters['search'] !== '') {
            // Bound, never concatenated. escapeLikeString() makes % and _ literal, with ! as the escape.
            $like  = '%' . $db->escapeLikeString($filters['search']) . '%';
            $terms = [
                "pe.first_name LIKE ? ESCAPE '!'",
                "pe.last_name LIKE ? ESCAPE '!'",
                "CONCAT(pe.first_name, ' ', pe.last_name) LIKE ? ESCAPE '!'",
                "pe.phone_number LIKE ? ESCAPE '!'",
            ];
            array_push($binds, $like, $like, $like, $like);

            // A number as printed for the customer (prefix, zeros and all) or just its digits.
            if (preg_match('/^\D*0*(\d{1,9})$/', $filters['search'], $match) === 1) {
                $terms[] = 'p.presale_id = ?';
                $binds[] = (int) $match[1];
            }

            $where[] = '(' . implode(' OR ', $terms) . ')';
        }

        $inner = "SELECT p.presale_id, p.campaign_id, p.customer_id, p.delivery_date, p.status, p.total, p.created_at,
                c.name AS campaign_name, pe.first_name, pe.last_name, pe.phone_number,
                (SELECT COALESCE(SUM(CASE WHEN pp.kind = '{$refund}' THEN -pp.amount ELSE pp.amount END), 0)
                    FROM {$payments} AS pp WHERE pp.presale_id = p.presale_id) AS paid,
                (SELECT COALESCE(SUM(pi.amount), 0)
                    FROM {$installments} AS pi WHERE pi.presale_id = p.presale_id AND pi.due_date < ?) AS due_to_date
            FROM {$presales} AS p
            JOIN {$campaigns} AS c ON c.campaign_id = p.campaign_id
            JOIN {$people} AS pe ON pe.person_id = p.customer_id
            WHERE " . implode(' AND ', $where);

        $state_sql = [
            Presale::STATUS_DELIVERED => "t.status = '" . Presale::STATUS_DELIVERED . "'",
            Presale::STATUS_CANCELED  => "t.status = '" . Presale::STATUS_CANCELED . "'",
            Presale::STATE_PAID       => "t.status = '" . Presale::STATUS_OPEN . "' AND t.total - t.paid <= 0",
            Presale::STATE_LATE       => "t.status = '" . Presale::STATUS_OPEN . "' AND t.total - t.paid > 0 AND t.paid < t.due_to_date",
            Presale::STATE_UP_TO_DATE => "t.status = '" . Presale::STATUS_OPEN . "' AND t.total - t.paid > 0 AND t.paid >= t.due_to_date",
        ];

        $sql = $inner;

        if ($filters['states'] !== []) {
            $conditions = array_map(static fn (string $state): string => '(' . $state_sql[$state] . ')', $filters['states']);
            $sql        = "SELECT t.* FROM ({$inner}) AS t WHERE " . implode(' OR ', $conditions);
        }

        return [$sql, $binds];
    }

    // ---------------------------------------------------------------------------------------------
    // The cash drawer
    // ---------------------------------------------------------------------------------------------

    /**
     * Lets the receipt that follows a cash payment open the drawer -- once. The mark lives in the
     * session for the next request only, so reprinting the receipt later never opens it again.
     */
    private function arm_drawer(int $presale_id, ?int $payment_id, string $what = 'initial'): void
    {
        $this->session->setFlashdata(self::DRAWER_FLASH, $presale_id . ':' . ($payment_id ?? $what));
    }

    /**
     * Whether this cancellation document is the one right after a CASH refund, and the business wants
     * the drawer opened for cash. Same one-shot mark as the instalments' (arm_drawer()).
     *
     * @param list<array<string, mixed>> $refunds
     */
    private function take_cancel_drawer(int $presale_id, array $refunds): bool
    {
        if ($this->session->getFlashdata(self::DRAWER_FLASH) !== $presale_id . ':' . self::DRAWER_CANCEL) {
            return false;
        }

        $last = end($refunds);

        if ($last === false || $last['payment_type_code'] !== 'cash') {
            return false;
        }

        return (new Sale_lib())->should_open_cash_drawer([['payment_type' => lang('Sales.cash')]]);
    }

    /**
     * Whether this receipt is the one right after a cash payment and the business wants the drawer
     * opened for cash. The decision is the register's (Sale_lib::should_open_cash_drawer()).
     */
    private function take_drawer(int $presale_id, ?int $payment_id): bool
    {
        if ($this->session->getFlashdata(self::DRAWER_FLASH) !== $presale_id . ':' . ($payment_id ?? 'initial')) {
            return false;
        }

        $payments = model(Presale_payment::class)->get_for($presale_id);
        $payment  = null;

        foreach ($payments as $movement) {
            if ($payment_id === null ? $movement['kind'] === Presale_payment::KIND_PAYMENT : (int) $movement['payment_id'] === $payment_id) {
                $payment = $movement;

                break;
            }
        }

        if ($payment === null || $payment['payment_type_code'] !== 'cash') {
            return false;
        }

        return (new Sale_lib())->should_open_cash_drawer([['payment_type' => lang('Sales.cash')]]);
    }

    /**
     * The stock location the delivery will take the products from: the register's active one.
     *
     * Same answer as Sale_lib::get_sale_location() -- the location the till has chosen in this
     * session, else the first one the employee may sell from -- without its failure: that method
     * reads a property of null, a 500, for an employee with no sales location, and a presales cashier
     * need not have the register at all. Then the first location of the business.
     */
    private function sale_location(int $employee_id): int
    {
        $chosen = (int) ($this->session->get('sales_location') ?? 0);

        if ($chosen > 0) {
            return $chosen;
        }

        $row = $this->db()->table('stock_locations AS locations')
            ->select('locations.location_id')
            ->join('permissions', 'permissions.location_id = locations.location_id')
            ->join('grants', 'grants.permission_id = permissions.permission_id')
            ->where('grants.person_id', $employee_id)
            ->like('permissions.permission_id', 'sales', 'after')
            ->where('locations.deleted', 0)
            ->orderBy('locations.location_id', 'asc')
            ->limit(1)
            ->get()->getRow();

        $row ??= $this->db()->table('stock_locations')->select('location_id')->where('deleted', 0)->orderBy('location_id', 'asc')->limit(1)->get()->getRow();

        return $row === null ? 0 : (int) $row->location_id;
    }

    private function refuse(string $message): ResponseInterface
    {
        return $this->response->setJSON(['success' => false, 'message' => esc($message)]);
    }

    private function db(): BaseConnection
    {
        return db_connect();
    }
}
