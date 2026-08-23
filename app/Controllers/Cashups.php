<?php

namespace App\Controllers;

use App\Models\Cash_collection;
use App\Models\Cashup;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\Reports\Summary_payments;
use CodeIgniter\HTTP\ResponseInterface;
use Config\OSPOS;
use Config\Services;

class Cashups extends Secure_Controller
{
    private Cash_collection $cash_collection;
    private Cashup $cashup;
    private Expense $expense;
    private Sale $sale;
    private Summary_payments $summary_payments;
    private array $config;

    public function __construct()
    {
        parent::__construct('cashups');

        $this->cash_collection = model(Cash_collection::class);
        $this->cashup = model(Cashup::class);
        $this->expense = model(Expense::class);
        $this->sale = model(Sale::class);
        $this->summary_payments = model(Summary_payments::class);
        $this->config = config(OSPOS::class)->settings;
    }

    /**
     * @return string
     */
    public function getIndex(): string
    {
        $data['table_headers'] = get_cashups_manage_table_headers();
        $current_person_id = (int) $this->employee->get_logged_in_employee_info()->person_id;
        $data['can_delete'] = $this->employee->has_grant('cashups_delete', $current_person_id);
        $data['can_reopen'] = $this->employee->has_grant('cashups_reopen', $current_person_id);

        // filters that will be loaded in the multiselect dropdown
        $data['filters'] = ['is_deleted' => lang('Cashups.is_deleted')];

        // Restore filters from URL
        $data = array_merge($data, restoreTableFilters($this->request));

        return view('cashups/manage', $data);
    }

    /**
     * @return void
     */
    public function getSearch(): ResponseInterface
    {
        $search = $this->request->getGet('search');
        $limit = $this->request->getGet('limit', FILTER_SANITIZE_NUMBER_INT);
        $offset = $this->request->getGet('offset', FILTER_SANITIZE_NUMBER_INT);
        $sort = $this->sanitizeSortColumn(cashup_headers(), $this->request->getGet('sort', FILTER_SANITIZE_FULL_SPECIAL_CHARS), 'cashup_id');
        $order = $this->request->getGet('order', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $filters = [
            'start_date' => $this->request->getGet('start_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS),    // TODO: Is this the best way to filter dates
            'end_date'   => $this->request->getGet('end_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'is_deleted' => false
        ];

        // Check if any filter is set in the multiselect dropdown
        $request_filters = array_fill_keys($this->request->getGet('filters', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? [], true);
        $filters = array_merge($filters, $request_filters);
        $cash_ups = $this->cashup->search($search, $filters, $limit, $offset, $sort, $order);
        $total_rows = $this->cashup->get_found_rows($search, $filters);
        $data_rows = [];
        foreach ($cash_ups->getResult() as $cash_up) {
            $data_rows[] = get_cash_up_data_row($cash_up);
        }

        return $this->response->setJSON(['total' => $total_rows, 'rows' => $data_rows]);
    }

    /**
     * @param int $cashup_id
     * @return string
     */
    public function getView(int $cashup_id = NEW_ENTRY): string
    {
        $data = [];

        $data['employees'] = [];
        foreach ($this->employee->get_all()->getResult() as $employee) {
            foreach (get_object_vars($employee) as $property => $value) {
                $employee->$property = $value;
            }

            $data['employees'][$employee->person_id] = $employee->first_name . ' ' . $employee->last_name;
        }

        $cash_ups_info = $this->cashup->get_info($cashup_id);

        foreach (get_object_vars($cash_ups_info) as $property => $value) {
            $cash_ups_info->$property = $value;
        }

        // Open cashup
        if ($cash_ups_info->cashup_id == NEW_ENTRY) {
            $cash_ups_info->open_date = date('Y-m-d H:i:s');
            $cash_ups_info->close_date = $cash_ups_info->open_date;
            $cash_ups_info->open_employee_id = $this->employee->get_logged_in_employee_info()->person_id;
            $cash_ups_info->close_employee_id = $this->employee->get_logged_in_employee_info()->person_id;
        }
        // An open cashup being edited is the close-in-progress phase
        elseif ($cash_ups_info->status === 'open') {
            // Set the close date and time to the actual as this is a close session
            $cash_ups_info->close_date = date('Y-m-d H:i:s');

            // The closed amount starts with the open amount -/+ any trasferred amount
            $cash_ups_info->closed_amount_cash = $cash_ups_info->open_amount_cash + $cash_ups_info->transfer_amount_cash;

            // If it's date mode only and not date & time truncate the open and end date to date only
            if (empty($this->config['date_or_time_format'])) {
                if ($cash_ups_info->open_date != null) {
                    $start_date = substr($cash_ups_info->open_date, 0, 10);
                } else {
                    $start_date = null;
                }
                if ($cash_ups_info->close_date != null) {
                    $end_date = substr($cash_ups_info->close_date, 0, 10);
                } else {
                    $end_date = null;
                }
                // Search for all the payments given the time range
                $inputs = [
                    'start_date'  => $start_date,
                    'end_date'    => $end_date,
                    'sale_type'   => 'complete',
                    'location_id' => 'all'
                ];
            } else {
                // Search for all the payments given the time range
                $inputs = [
                    'start_date'  => $cash_ups_info->open_date,
                    'end_date'    => $cash_ups_info->close_date,
                    'sale_type'   => 'complete',
                    'location_id' => 'all'
                ];
            }

            // Get all the transactions payment summaries
            $reports_data = $this->summary_payments->getData($inputs);

            // Casaletto only reconciles Efectivo/Datafono/Banco at close time: Due
            // (Adeudo) is intentionally never counted here, and closed_amount_check
            // (labeled "Banco" in the UI) sums Bank Transfer sales, not Check sales.
            foreach ($reports_data as $row) {
                if ($row['trans_group'] == lang('Reports.trans_payments')) {
                    if ($row['trans_type'] == lang('Sales.cash')) {
                        $cash_ups_info->closed_amount_cash += $row['trans_amount'];
                    } elseif (
                        $row['trans_type'] == lang('Sales.debit') ||
                        $row['trans_type'] == lang('Sales.credit')
                    ) {
                        $cash_ups_info->closed_amount_card += $row['trans_amount'];
                    } elseif ($row['trans_type'] == lang('Sales.bank_transfer')) {
                        $cash_ups_info->closed_amount_check += $row['trans_amount'];
                    }
                }
            }

            // Expenses paid out of the drawer. Narrowed to cash_source 'register' because money
            // paid from cash already collected never sat in this drawer, so subtracting it here
            // would report the shift short by that much. Every one of the 55 cash expenses on
            // record backfills to 'register', so no historical total moves.
            $filters = [
                'only_cash'     => true,
                'only_register' => true,
                'only_due'      => false,
                'only_check'    => false,
                'only_credit'   => false,
                'only_debit'    => false,
                'is_deleted'    => false
            ];

            $payments = $this->expense->get_payments_summary('', array_merge($inputs, $filters));

            foreach ($payments as $row) {
                $cash_ups_info->closed_amount_cash -= $row['amount'];
            }

            $cash_ups_info->closed_amount_total = $this->_calculate_total($cash_ups_info->open_amount_cash, $cash_ups_info->transfer_amount_cash, $cash_ups_info->closed_amount_cash, $cash_ups_info->closed_amount_due, $cash_ups_info->closed_amount_card, $cash_ups_info->closed_amount_check);
        }

        $data['cash_ups_info'] = $cash_ups_info;

        // Everyone who can reach this screen may write down a collection, but only an
        // administrator can be named as the one who took the money: a cashier does not have the
        // keys to the safe or the bank, so naming one would record something that cannot have
        // happened.
        $data['collectors'] = [];

        foreach ($this->employee->get_all()->getResult() as $employee) {
            if ($this->employee->has_grant('config', (int)$employee->person_id)) {
                $data['collectors'][$employee->person_id] = $employee->first_name . ' ' . $employee->last_name;
            }
        }

        $data['is_open'] = $cash_ups_info->cashup_id != NEW_ENTRY && $cash_ups_info->status === 'open';

        // The grid's hand-over-cash action links here with ?tab=collections. Reading it rather than
        // always opening on the shift tab is what lets that action be one click instead of two.
        $data['active_tab'] = $this->request->getGet('tab') === 'collections' ? 'collections' : 'shift';
        $data['collections'] = [];
        $data['reconciliation'] = null;

        if ($cash_ups_info->cashup_id != NEW_ENTRY) {
            $data['collections'] = $this->cash_collection
                ->get_collected_between($cash_ups_info->open_date, $cash_ups_info->close_date)
                ->getResultArray();

            $data['reconciliation'] = $this->_build_reconciliation($cash_ups_info);
        }

        return view("cashups/form", $data);
    }

    /**
     * The read-only figures under the closing fields: what the shift took in, what left the drawer,
     * and what should therefore be sitting in it.
     *
     * Income is read from the sales sealed with this shift, not from a date range. The two are not
     * the same: the autocomplete above still uses a range, and with the date-only setting it widens
     * that range to whole days, so on 31 July -- the one day with two shifts -- each of them would
     * count the other's sales. Nothing here writes to the cash-up, and the Total is left exactly as
     * it has been calculated for forty shifts.
     */
    private function _build_reconciliation(object $cash_ups_info): array
    {
        $income = $this->sale->get_payments_by_cashup((int)$cash_ups_info->cashup_id);
        $income_cash = 0.0;
        $income_total = 0.0;

        foreach ($income as $row) {
            $income_total += (float)$row['trans_amount'];

            if ($row['payment_type_code'] === 'cash') {
                $income_cash += (float)$row['trans_amount'];
            }
        }

        $expense_filters = [
            'only_cash'     => true,
            'only_register' => true,
            'is_deleted'    => false,
            'start_date'    => $cash_ups_info->open_date,
            'end_date'      => $cash_ups_info->close_date
        ];

        $register_expenses = 0.0;

        foreach ($this->expense->get_payments_summary('', $expense_filters) as $row) {
            $register_expenses += (float)$row['amount'];
        }

        $collections = $this->cash_collection->get_total_collected_between(
            $cash_ups_info->open_date,
            $cash_ups_info->close_date
        );

        $expected = (float)$cash_ups_info->open_amount_cash
            + $income_cash
            - $register_expenses
            - $collections;

        $counted = (float)$cash_ups_info->closed_amount_cash;

        return [
            'income'            => $income,
            'income_cash'       => $income_cash,
            'income_total'      => $income_total,
            'open_amount_cash'  => (float)$cash_ups_info->open_amount_cash,
            'register_expenses' => $register_expenses,
            'collections'       => $collections,
            'expected'          => $expected,
            'counted'           => $counted,
            'discrepancy'       => $counted - $expected,
            'sealed_sales'      => $income !== []
        ];
    }

    /**
     * @param int $row_id
     * @return ResponseInterface
     */
    public function getRow(int $row_id): ResponseInterface
    {
        $cash_ups_info = $this->cashup->get_info($row_id);
        $data_row = get_cash_up_data_row($cash_ups_info);

        return $this->response->setJSON($data_row);
    }

    /**
     * @param int $cashup_id
     * @return ResponseInterface
     */
    public function postSave(int $cashup_id = NEW_ENTRY): ResponseInterface
    {
        $is_new = ($cashup_id === NEW_ENTRY);

        if (!$is_new) {
            $existing = $this->cashup->get_info($cashup_id);

            if ($existing->status === 'closed') {
                return $this->response->setJSON(['success' => false, 'message' => lang('Cashups.cannot_edit_closed'), 'id' => $cashup_id]);
            }
        }

        // One shift open at a time. Sales, cash expenses and collections are all attributed to the
        // shift that was open when they happened, so a second open shift makes that attribution a
        // guess: two drawers would be claiming the same money. Checked before anything else because
        // no other answer on this form matters if the shift cannot be opened at all.
        if ($is_new && $this->cashup->get_open_cashup_id() !== null) {
            return $this->response->setJSON([
                'success' => false,
                'message' => lang('Cashups.already_open'),
                'id'      => NEW_ENTRY
            ]);
        }

        // Amounts are stored straight from parse_decimals(), which yields ''
        // for a blank field and false for anything it cannot parse -- both land
        // in a DECIMAL column as 0.00 without a word to the cashier. That is how
        // turno 29 (2026-08-11) was opened with 0 instead of 217,000, and since
        // getView() seeds the closing cash from the opening amount, the whole
        // close came out short by exactly that much. Refuse the save instead.
        $amount_fields = $is_new
            ? ['open_amount_cash' => true]
            : ['closed_amount_cash' => true, 'closed_amount_due' => false, 'closed_amount_card' => false, 'closed_amount_check' => false];

        foreach ($amount_fields as $field => $is_required) {
            if (!$this->_amount_is_valid($field, $is_required)) {
                return $this->response->setJSON([
                    'success' => false,
                    'message' => lang('Cashups.invalid_amount', [lang('Cashups.' . $field)]),
                    'id'      => $is_new ? NEW_ENTRY : $cashup_id
                ]);
            }
        }

        if ($is_new) {
            $open_date = $this->request->getPost('open_date');
            $open_date_formatter = date_create_from_format($this->config['dateformat'] . ' ' . $this->config['timeformat'], $open_date);

            // date_create_from_format() returns false when the posted date does
            // not match the configured format, and calling ->format() on that
            // is a fatal, not a validation failure.
            if ($open_date_formatter === false) {
                return $this->response->setJSON(['success' => false, 'message' => lang('Cashups.error_adding_updating'), 'id' => NEW_ENTRY]);
            }

            $open_employee_id = $this->request->getPost('open_employee_id', FILTER_SANITIZE_NUMBER_INT);

            $cash_up_data = [
                'open_date'            => $open_date_formatter->format('Y-m-d H:i:s'),
                'close_date'           => $open_date_formatter->format('Y-m-d H:i:s'),
                'open_amount_cash'     => parse_decimals($this->request->getPost('open_amount_cash')),
                // The cash in/out field is gone from the form; the collections register replaced
                // it. The column stays because _calculate_total() reads it and all forty shifts
                // hold zero there, so dropping it would move historical totals.
                'transfer_amount_cash' => 0,
                'closed_amount_cash'   => 0,
                'closed_amount_due'    => 0,
                'closed_amount_card'   => 0,
                'closed_amount_check'  => 0,
                'closed_amount_total'  => 0,
                'note'                 => false,
                'description'          => '',
                'open_employee_id'     => $open_employee_id,
                'close_employee_id'    => $open_employee_id,
                'deleted'              => false,
                'status'               => 'open'
            ];
        } else {
            $close_date = $this->request->getPost('close_date');
            $close_date_formatter = date_create_from_format($this->config['dateformat'] . ' ' . $this->config['timeformat'], $close_date);

            if ($close_date_formatter === false) {
                return $this->response->setJSON(['success' => false, 'message' => lang('Cashups.error_adding_updating'), 'id' => $cashup_id]);
            }

            $cash_up_data = [
                // Opening fields are preserved from the existing record, never
                // from POST -- they're disabled in the form at this stage and
                // must not be modifiable here.
                'open_date'            => $existing->open_date,
                'open_amount_cash'     => $existing->open_amount_cash,
                'transfer_amount_cash' => $existing->transfer_amount_cash,
                'open_employee_id'     => $existing->open_employee_id,
                'close_date'           => $close_date_formatter->format('Y-m-d H:i:s'),
                'closed_amount_cash'   => parse_decimals($this->request->getPost('closed_amount_cash')),
                'closed_amount_due'    => parse_decimals($this->request->getPost('closed_amount_due')),
                'closed_amount_card'   => parse_decimals($this->request->getPost('closed_amount_card')),
                'closed_amount_check'  => parse_decimals($this->request->getPost('closed_amount_check')),
                'closed_amount_total'  => parse_decimals($this->request->getPost('closed_amount_total')),
                'note'                 => $this->request->getPost('note') != null,
                // Free text read without FILTER_SANITIZE_FULL_SPECIAL_CHARS: despite what the manual
                // says, it behaves like htmlentities() and stores accented letters as named HTML
                // entities. The only place this is rendered is the cashup form's textarea, which the
                // form helper escapes. See docs/Tecnico/errores-produccion-upstream.md section 5.
                'description'          => $this->request->getPost('description'),
                'close_employee_id'    => $this->request->getPost('close_employee_id', FILTER_SANITIZE_NUMBER_INT),
                'deleted'              => $this->request->getPost('deleted') != null,
                'status'               => 'closed'
            ];
        }

        if ($this->cashup->save_value($cash_up_data, $cashup_id)) {
            if ($is_new) {
                return $this->response->setJSON(['success' => true, 'message' => lang('Cashups.successful_adding'), 'id' => $cash_up_data['cashup_id']]);
            } else { // Existing Cashup
                return $this->response->setJSON(['success' => true, 'message' => lang('Cashups.successful_updating'), 'id' => $cashup_id]);
            }
        } else { // Failure
            return $this->response->setJSON(['success' => false, 'message' => lang('Cashups.error_adding_updating'), 'id' => NEW_ENTRY]);
        }
    }

    /**
     * Reopens closed cashups so their close-phase fields become editable again.
     * Gated by the cashups_reopen sub-permission -- an employee with base
     * Turnos access does not get this unless explicitly granted it.
     *
     * @return ResponseInterface
     */
    public function postReopen(): ResponseInterface
    {
        $current_person_id = (int) $this->employee->get_logged_in_employee_info()->person_id;

        if (!$this->employee->has_grant('cashups_reopen', $current_person_id)) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Cashups.reopen_admin_only')]);
        }

        $ids = $this->request->getPost('ids', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? [];

        if ($this->cashup->reopen_list($ids)) {
            return $this->response->setJSON(['success' => true, 'message' => lang('Cashups.successful_reopen'), 'ids' => $ids]);
        }

        return $this->response->setJSON(['success' => false, 'message' => lang('Cashups.error_adding_updating'), 'ids' => $ids]);
    }

    /**
     * @return ResponseInterface
     */
    public function postDelete(): ResponseInterface
    {
        $current_person_id = (int) $this->employee->get_logged_in_employee_info()->person_id;

        if (!$this->employee->has_grant('cashups_delete', $current_person_id)) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Cashups.delete_not_allowed')]);
        }

        $cash_ups_to_delete = $this->request->getPost('ids', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if ($this->cashup->delete_list($cash_ups_to_delete)) {
            return $this->response->setJSON(['success' => true, 'message' => lang('Cashups.successful_deleted') . ' ' . count($cash_ups_to_delete) . ' ' . lang('Cashups.one_or_multiple'), 'ids' => $cash_ups_to_delete]);
        } else {
            return $this->response->setJSON(['success' => false, 'message' => lang('Cashups.cannot_be_deleted'), 'ids' => $cash_ups_to_delete]);
        }
    }

    /**
     * Calculate the total for cashups. Used in app\Views\cashups\form.php
     *
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postAjax_cashup_total(): ResponseInterface
    {
        $open_amount_cash = $this->_posted_amount('open_amount_cash');
        $transfer_amount_cash = $this->_posted_amount('transfer_amount_cash');
        $closed_amount_cash = $this->_posted_amount('closed_amount_cash');
        $closed_amount_due = $this->_posted_amount('closed_amount_due');
        $closed_amount_card = $this->_posted_amount('closed_amount_card');
        $closed_amount_check = $this->_posted_amount('closed_amount_check');

        $total = $this->_calculate_total($open_amount_cash, $transfer_amount_cash, $closed_amount_due, $closed_amount_cash, $closed_amount_card, $closed_amount_check);    // TODO: hungarian notation

        $response = ['total' => to_currency_no_money($total)];

        // The difference is worked out here rather than in the browser because the amount arrives
        // formatted for the operator's locale, and parse_decimals() is the only thing that reads it
        // correctly. Recomputing what the drawer should hold costs three queries on a keystroke
        // that is already debounced, on a screen used a few times a day.
        $cashup_id = (int) $this->request->getPost('cashup_id', FILTER_SANITIZE_NUMBER_INT);

        if ($cashup_id !== 0 && $this->cashup->exists($cashup_id)) {
            $cash_ups_info = $this->cashup->get_info($cashup_id);

            if ($cash_ups_info->status === 'open') {
                $cash_ups_info->close_date = date('Y-m-d H:i:s');
            }

            $cash_ups_info->closed_amount_cash = $closed_amount_cash;
            $reconciliation = $this->_build_reconciliation($cash_ups_info);

            $response['counted'] = to_currency($reconciliation['counted']);
            $response['discrepancy'] = to_currency($reconciliation['discrepancy']);
            $response['balanced'] = abs($reconciliation['discrepancy']) < 0.005;
        }

        return $this->response->setJSON($response);
    }

    /**
     * The collections list and the reconciliation figures, as JSON, for refreshing those two blocks
     * after one is added or removed.
     *
     * A narrow endpoint rather than re-fetching the whole form on purpose: a cashier may be part
     * way through typing the closing amounts when they write down a collection, and replacing the
     * form would throw that away. Amounts are formatted here so the browser only has to put strings
     * into cells.
     */
    public function getCollections(int $cashup_id = NEW_ENTRY): ResponseInterface
    {
        if ($cashup_id === NEW_ENTRY || !$this->cashup->exists($cashup_id)) {
            return $this->response->setJSON(['success' => false, 'collections' => [], 'reconciliation' => null]);
        }

        $cash_ups_info = $this->cashup->get_info($cashup_id);

        // An open shift is reconciled up to now: it has no real close_date yet, only the copy of
        // its open_date that postSave() writes when the shift is created.
        if ($cash_ups_info->status === 'open') {
            $cash_ups_info->close_date = date('Y-m-d H:i:s');
        }

        $collections = [];

        foreach ($this->cash_collection->get_collected_between($cash_ups_info->open_date, $cash_ups_info->close_date)->getResultArray() as $row) {
            $collections[] = [
                'collection_id' => (int)$row['collection_id'],
                'collected_at'  => $row['collected_at'],
                'collected_by'  => $row['collected_by_first_name'] . ' ' . $row['collected_by_last_name'],
                'amount'        => to_currency($row['amount']),
                'note'          => $row['note']
            ];
        }

        $reconciliation = $this->_build_reconciliation($cash_ups_info);

        return $this->response->setJSON([
            'success'        => true,
            'collections'    => $collections,
            'reconciliation' => [
                'register_expenses' => to_currency($reconciliation['register_expenses']),
                'collections'       => to_currency($reconciliation['collections']),
                'expected'          => to_currency($reconciliation['expected']),
                'expected_raw'      => $reconciliation['expected']
            ]
        ]);
    }

    /**
     * Writes down that cash left the drawer.
     *
     * A collection is not an expense: the money was not spent, it moved. It never reaches any
     * expense total or the income-versus-expenses report; its only effect is on how much cash the
     * drawer is expected to hold.
     *
     * Anyone who can reach the cash-up screen may record one -- the cashier is usually the one
     * standing there when it happens -- but the person named as having taken the money has to hold
     * the config grant. That is checked here and not merely by filtering the dropdown, because a
     * dropdown is a suggestion and a POST is whatever the sender wants it to be.
     */
    public function postSaveCollection(): ResponseInterface
    {
        if (!$this->_amount_is_valid('amount', true)) {
            return $this->response->setJSON([
                'success' => false,
                'message' => lang('Cashups.invalid_amount', [lang('Cashups.collection_amount')])
            ]);
        }

        $amount = parse_decimals($this->request->getPost('amount'));

        // Zero moves nothing and a negative would silently turn a collection into a deposit, which
        // is a different event with a different explanation behind it.
        if ($amount <= 0) {
            return $this->response->setJSON([
                'success' => false,
                'message' => lang('Cashups.invalid_amount', [lang('Cashups.collection_amount')])
            ]);
        }

        $collected_at = $this->request->getPost('collected_at');
        $collected_at_formatter = date_create_from_format($this->config['dateformat'] . ' ' . $this->config['timeformat'], $collected_at);

        // date_create_from_format() returns false on a format mismatch and calling ->format() on
        // that is a fatal. The time is the whole point of the record -- it is what decides which
        // shift the collection belongs to -- so a time we cannot read is a refusal, not a default.
        if ($collected_at_formatter === false) {
            return $this->response->setJSON([
                'success' => false,
                'message' => lang('Cashups.collection_invalid_date')
            ]);
        }

        $collected_by = (int) $this->request->getPost('collected_by', FILTER_SANITIZE_NUMBER_INT);

        if ($collected_by === 0 || !$this->employee->has_grant('config', $collected_by)) {
            return $this->response->setJSON([
                'success' => false,
                'message' => lang('Cashups.collection_collector_invalid')
            ]);
        }

        $collection_data = [
            'amount'        => $amount,
            'collected_at'  => $collected_at_formatter->format('Y-m-d H:i:s'),
            'collected_by'  => $collected_by,
            'registered_by' => (int) $this->employee->get_logged_in_employee_info()->person_id,
            // Free text read without FILTER_SANITIZE_FULL_SPECIAL_CHARS: despite what the manual
            // says it behaves like htmlentities() and stores accented letters as named HTML
            // entities. The only place this is rendered is the collections table on this form,
            // which escapes it. See docs/Tecnico/errores-produccion-upstream.md section 5.
            'note'          => (string) $this->request->getPost('note'),
            'deleted'       => 0
        ];

        if ($this->cash_collection->save_value($collection_data)) {
            return $this->response->setJSON([
                'success' => true,
                'message' => lang('Cashups.collection_saved'),
                'id'      => $collection_data['collection_id'] ?? NEW_ENTRY
            ]);
        }

        return $this->response->setJSON([
            'success' => false,
            'message' => lang('Cashups.collection_error')
        ]);
    }

    /**
     * Removes a collection that should not have been written down.
     *
     * Soft delete, like everything else that touches money here: the reconciliation stops counting
     * it, and the row stays for anyone asking later what the drawer was reconciled against.
     */
    public function postDeleteCollection(int $collection_id = NEW_ENTRY): ResponseInterface
    {
        if ($collection_id === NEW_ENTRY || !$this->cash_collection->exists($collection_id)) {
            return $this->response->setJSON([
                'success' => false,
                'message' => lang('Cashups.collection_error')
            ]);
        }

        if ($this->cash_collection->delete_list([$collection_id])) {
            return $this->response->setJSON([
                'success' => true,
                'message' => lang('Cashups.collection_deleted')
            ]);
        }

        return $this->response->setJSON([
            'success' => false,
            'message' => lang('Cashups.collection_error')
        ]);
    }

    /**
     * Checks that a posted amount is something we can store without quietly
     * turning it into zero. A blank field is only acceptable where the form
     * treats "nothing" as "none"; a value parse_decimals() rejects (unparseable
     * for the configured locale, or past MAX_PRECISION) is never acceptable.
     */
    private function _amount_is_valid(string $field, bool $is_required): bool
    {
        $raw = trim((string) $this->request->getPost($field));

        if ($raw === '') {
            return !$is_required;
        }

        return parse_decimals($raw) !== false;
    }

    /**
     * Reads a posted amount as a usable float.
     *
     * parse_decimals() returns values that are not floats in three cases, and
     * the cash-up form hits all of them: '' for an untouched field, the literal
     * '0' (empty('0') is true in PHP, so it is returned unparsed), and false for
     * a number that fails to parse or exceeds MAX_PRECISION. An empty string is
     * the only one PHP will not coerce into a float parameter, which is why
     * leaving "card" blank used to blow up _calculate_total() with a TypeError
     * (a 500 on every keystroke, silently freezing the read-only total) while
     * leaving "check" blank did not -- that argument simply had no type.
     */
    private function _posted_amount(string $field): float
    {
        return (float) (parse_decimals($this->request->getPost($field)) ?: 0);
    }

    /**
     * Calculate total
     */
    private function _calculate_total(float $open_amount_cash, float $transfer_amount_cash, float $closed_amount_due, float $closed_amount_cash, float $closed_amount_card, float $closed_amount_check): float    // TODO: need to get rid of hungarian notation here. Also, the signature is pretty long.  Perhaps they need to go into an object or array?
    {
        return ($closed_amount_cash - $open_amount_cash - $transfer_amount_cash + $closed_amount_due + $closed_amount_card + $closed_amount_check);
    }
}
