<?php

namespace App\Models;

use App\Libraries\Item_lib;
use CodeIgniter\Database\ResultInterface;
use CodeIgniter\Model;
use Config\OSPOS;
use stdClass;

/**
 * Expense class
 */
class Expense extends Model
{
    protected $table = 'expenses';
    protected $primaryKey = 'expense_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'date',
        'amount',
        'payment_type',
        'payment_type_code',
        // CodeIgniter drops any field that is not listed here without a word. That is how
        // payment_type_code was silently discarded on its first attempt, and the cash source is the
        // one column the cash-up is going to subtract with.
        'cash_source',
        'expense_category_id',
        'description',
        'employee_id',
        'deleted',
        'supplier_tax_code',
        'tax_amount',
        'supplier_id',
        'location_id'
    ];

    /**
     * Determines if a given Expense_id is an Expense
     */
    public function exists(int $expense_id): bool
    {
        $builder = $this->db->table('expenses');
        $builder->where('expense_id', $expense_id);

        return ($builder->get()->getNumRows() == 1);    // TODO: ===
    }

    /**
     * Gets category info
     */
    public function get_expense_category(int $expense_id): object    // TODO: This function is never called in the code
    {
        $builder = $this->db->table('expenses');
        $builder->where('expense_id', $expense_id);

        $expense_category = model(Expense_category::class);
        return $expense_category->get_info($builder->get()->getRow()->expense_category_id);    // TODO: refactor out the nested function call.
    }

    /**
     * Gets employee info
     */
    public function get_employee(int $expense_id): object    // TODO: This function is never called in the code
    {
        $builder = $this->db->table('expenses');
        $builder->where('expense_id', $expense_id);

        $employee = model(Employee::class);

        return $employee->get_info($builder->get()->getRow()->employee_id);    // TODO: refactor out the nested function call.
    }

    /**
     * @param array $expense_ids
     * @return ResultInterface
     */
    public function get_multiple_info(array $expense_ids): ResultInterface
    {
        $builder = $this->db->table('expenses');
        $builder->whereIn('expenses.expense_id', $expense_ids);
        $builder->orderBy('expense_id', 'asc');

        return $builder->get();
    }

    /**
     * Gets rows
     */
    public function get_found_rows(string $search, array $filters): int
    {
        return $this->search($search, $filters, 0, 0, 'expense_id', 'asc', true);
    }

    /**
     * Searches expenses
     *
     * @param string $search
     * @param array $filters
     * @param int|null $rows
     * @param int|null $limit_from
     * @param string|null $sort
     * @param string|null $order
     * @param bool|null $count_only
     * @return ResultInterface|false|string
     */
    public function search(string $search, array $filters, ?int $rows = 0, ?int $limit_from = 0, ?string $sort = 'expense_id', ?string $order = 'asc', ?bool $count_only = false): false|string|ResultInterface
    {
        // Set default values
        if ($rows == null) $rows = 0;
        if ($limit_from == null) $limit_from = 0;
        if ($sort == null) $sort = 'expense_id';
        if ($order == null) $order = 'asc';
        if ($count_only == null) $count_only = false;

        $config = config(OSPOS::class)->settings;
        $builder = $this->db->table('expenses AS expenses');

        // get_found_rows case
        if ($count_only) {
            $builder->select('COUNT(DISTINCT expenses.expense_id) as count');
        } else {
            $builder->select('
                expenses.expense_id,
                MAX(expenses.date) AS date,
                MAX(suppliers.company_name) AS supplier_name,
                MAX(expenses.supplier_tax_code) AS supplier_tax_code,
                MAX(expenses.amount) AS amount,
                MAX(expenses.tax_amount) AS tax_amount,
                MAX(expenses.payment_type) AS payment_type,
                MAX(expenses.cash_source) AS cash_source,
                MAX(expenses.description) AS description,
                MAX(employees.first_name) AS first_name,
                MAX(employees.last_name) AS last_name,
                MAX(expense_categories.category_name) AS category_name
            ');
        }

        $builder->join('people AS employees', 'employees.person_id = expenses.employee_id', 'LEFT');
        $builder->join('expense_categories AS expense_categories', 'expense_categories.expense_category_id = expenses.expense_category_id', 'LEFT');
        $builder->join('suppliers AS suppliers', 'suppliers.person_id = expenses.supplier_id', 'LEFT');

        $builder->groupStart();
            $builder->like('employees.first_name', $search);
            $builder->orLike('expenses.date', $search);
            $builder->orLike('employees.last_name', $search);
            $builder->orLike('expenses.payment_type', $search);
            $builder->orLike('expenses.amount', $search);
            $builder->orLike('expense_categories.category_name', $search);
            $builder->orLike('CONCAT(employees.first_name, " ", employees.last_name)', $search);
        $builder->groupEnd();

        $builder->where('expenses.deleted', $filters['is_deleted']);

        if (empty($config['date_or_time_format'])) {
            $builder->where('DATE_FORMAT(expenses.date, "%Y-%m-%d") BETWEEN ' . $this->db->escape($filters['start_date']) . ' AND ' . $this->db->escape($filters['end_date']));
        } else {
            $builder->where('expenses.date BETWEEN ' . $this->db->escape(rawurldecode($filters['start_date'])) . ' AND ' . $this->db->escape(rawurldecode($filters['end_date'])));
        }

        $this->apply_payment_type_filters($builder, $filters, 'expenses.payment_type_code', 'expenses.payment_type');

        $this->apply_cash_source_filters($builder, $filters, 'expenses.cash_source');

        if ($count_only) {    // TODO: replace this with `if ($count_only)`
            return $builder->get()->getRow()->count;
        }

        $builder->groupBy('expense_id');

        $builder->orderBy($sort, $order);

        if ($rows > 0) {
            $builder->limit($rows, $limit_from);
        }

        return $builder->get();
    }

    /**
     * What was paid out of the drawer between two moments, for the cash-up reconciliation.
     *
     * Deliberately not routed through get_payments_summary(). That one honours the
     * date_or_time_format setting, and when the setting is empty it compares
     * DATE_FORMAT(date, '%Y-%m-%d') against the bounds it is handed. Give it a bound carrying a
     * time and the comparison is between strings of different lengths: '2026-08-23' sorts before
     * '2026-08-23 15:49:52', so nothing from that very day is ever inside the range and the drawer
     * comes up with zero expenses no matter how many were paid from it.
     *
     * That setting is about how dates are displayed and filtered on the grids. A drawer is
     * reconciled over a window of time, and a shift that opened at 15:49 did not pay for what was
     * bought at 09:00 that morning, so the comparison here is always on the full timestamp.
     */
    public function get_register_total_between(string $start_date, string $end_date): float
    {
        $builder = $this->db->table('expenses');
        $builder->select('IFNULL(SUM(amount), 0) AS total');
        $builder->where('deleted', 0);
        $builder->where('cash_source', 'register');
        $builder->where('date >=', $start_date);
        $builder->where('date <=', $end_date);

        $row = $builder->get()->getRow();

        return (float)($row->total ?? 0);
    }

    /**
     * Narrows a query down to the ticked payment types, matched on the stable code.
     *
     * These filters used to compare the stored label against the Expenses.* keys while the form
     * saved the label from the Sales.* keys -- in es-MX "Adeudo" was stored and "A Crédito" was
     * searched, so only_due never matched a single row.
     *
     * Shared because search() and get_payments_summary() had drifted apart: the summary handled
     * five of the seven types and left out bank transfer and wallet, and it also left out the null
     * payment type that counts as cash below. Ticking either of those filters gave a grid of rows
     * whose totals underneath described a different set. Reading the two lists side by side is what
     * it takes to notice, so there is now only one list.
     *
     * A row saved before the payment type existed has payment_type NULL and counts as cash: that is
     * what it was, and the code column cannot say so retroactively.
     *
     * $column_code and $column_label are passed in because the two callers build their query
     * differently: search() aliases the table and has to qualify the columns, this one does not.
     *
     * @param mixed $builder
     */
    private function apply_payment_type_filters($builder, array $filters, string $column_code, string $column_label): void
    {
        $filter_codes = [
            'only_cash'          => 'cash',
            'only_debit'         => 'debit',
            'only_credit'        => 'credit',
            'only_due'           => 'due',
            'only_check'         => 'check',
            'only_bank_transfer' => 'bank_transfer',
            'only_wallet'        => 'wallet',
        ];

        foreach ($filter_codes as $filter => $code) {
            if (empty($filters[$filter])) {
                continue;
            }

            if ($filter === 'only_cash') {
                $builder->groupStart();
                $builder->where($column_code, 'cash');
                $builder->orWhere($column_label . ' IS NULL');
                $builder->groupEnd();
            } else {
                $builder->where($column_code, $code);
            }
        }
    }

    /**
     * Narrows a query down to one or both cash sources, matched on the stored code.
     *
     * Unlike the payment type filters above, picking both of these returns the union rather than
     * nothing: "register or collected" is the natural reading of ticking both boxes, and it is also
     * the useful one -- it isolates the cash expenses from the transfers.
     *
     * $column is passed in because the two callers build their query differently: search() aliases
     * the table and has to qualify the column, get_payments_summary() does not.
     *
     * @param mixed $builder
     */
    private function apply_cash_source_filters($builder, array $filters, string $column): void
    {
        $cash_sources = [];

        foreach (['only_register' => 'register', 'only_collected' => 'collected'] as $filter => $code) {
            if (!empty($filters[$filter])) {
                $cash_sources[] = $code;
            }
        }

        if ($cash_sources !== []) {
            $builder->whereIn($column, $cash_sources);
        }
    }

    /**
     * Gets information about a particular expense
     */
    public function get_info(int $expense_id): object
    {
        $builder = $this->db->table('expenses AS expenses');
        $builder->select('
            expenses.expense_id AS expense_id,
            expenses.date AS date,
            suppliers.company_name AS supplier_name,
            expenses.supplier_id AS supplier_id,
            expenses.supplier_tax_code AS supplier_tax_code,
            expenses.amount AS amount,
            expenses.tax_amount AS tax_amount,
            expenses.payment_type AS payment_type,
            expenses.cash_source AS cash_source,
            expenses.description AS description,
            expenses.employee_id AS employee_id,
            expenses.deleted AS deleted,
            employees.first_name AS first_name,
            employees.last_name AS last_name,
            expense_categories.expense_category_id AS expense_category_id,
            expense_categories.category_name AS category_name
        ');

        $builder->join('people AS employees', 'employees.person_id = expenses.employee_id', 'LEFT');
        $builder->join('expense_categories AS expense_categories', 'expense_categories.expense_category_id = expenses.expense_category_id', 'LEFT');
        $builder->join('suppliers AS suppliers', 'suppliers.person_id = expenses.supplier_id', 'LEFT');
        $builder->where('expense_id', $expense_id);

        $query = $builder->get();

        if ($query->getNumRows() == 1) {    // TODO: ===
            return $query->getRow();
        }

        $empty_obj = $this->getEmptyObject('expenses');
        $empty_obj->supplier_name = null;
        $empty_obj->first_name = null;
        $empty_obj->last_name = null;

        return $empty_obj;
    }

    /**
     * Initializes an empty object based on database definitions
     * @param string $table_name
     * @return object
     */
    private function getEmptyObject(string $table_name): object
    {
        // Return an empty base parent object, as $item_id is NOT an item
        $empty_obj = new stdClass();

        // Iterate through field definitions to determine how the fields should be initialized
        foreach ($this->db->getFieldData($table_name) as $field) {
            $field_name = $field->name;

            if (in_array($field->type, ['int', 'tinyint', 'decimal'])) {
                $empty_obj->$field_name = ($field->primary_key == 1) ? NEW_ENTRY : 0;
            } else {
                $empty_obj->$field_name = null;
            }
        }

        return $empty_obj;
    }

    /**
     * Inserts or updates an expense
     */
    public function save_value(array &$expense_data, int $expense_id = NEW_ENTRY): bool
    {
        $builder = $this->db->table('expenses');

        if ($expense_id == NEW_ENTRY || !$this->exists($expense_id)) {
            $expense_data['location_id'] = (int) (new Item_lib())->get_item_location();

            if ($builder->insert($expense_data)) {
                $expense_data['expense_id'] = $this->db->insertID();

                return true;
            }

            return false;
        }

        $builder->where('expense_id', $expense_id);

        return $builder->update($expense_data);
    }

    /**
     * Deletes a list of expense_category
     */
    public function delete_list(array $expense_ids): bool
    {
        $builder = $this->db->table('expenses');

        $this->db->transStart();
        $builder->whereIn('expense_id', $expense_ids);
        $success = $builder->update(['deleted' => 1]);
        $this->db->transComplete();

        $success &= $this->db->transStatus();

        return $success;
    }

    /**
     * Gets the payment summary for the expenses (expenses/manage) view
     */
    public function get_payments_summary(string $search, array $filters): array    // TODO: $search is passed but never used in the function
    {
        $config = config(OSPOS::class)->settings;

        // get payment summary
        $builder = $this->db->table('expenses');
        $builder->select('payment_type, COUNT(amount) AS count, SUM(amount) AS amount');
        $builder->where('deleted', $filters['is_deleted']);

        if (empty($config['date_or_time_format'])) {
            $builder->where('DATE_FORMAT(date, "%Y-%m-%d") BETWEEN ' . $this->db->escape($filters['start_date']) . ' AND ' . $this->db->escape($filters['end_date']));
        } else {
            $builder->where('date BETWEEN ' . $this->db->escape(rawurldecode($filters['start_date'])) . ' AND ' . $this->db->escape(rawurldecode($filters['end_date'])));
        }

        // Both of these run through the same two helpers search() uses, so the totals under the grid
        // cannot describe a different set of rows than the grid is showing.
        $this->apply_payment_type_filters($builder, $filters, 'payment_type_code', 'payment_type');

        $this->apply_cash_source_filters($builder, $filters, 'cash_source');

        $builder->groupBy('payment_type');

        return $builder->get()->getResultArray();
    }

    /**
     * Gets the payment options to show in the expense forms
     */
    public function get_payment_options(): array
    {
        return get_payment_options();
    }

    /**
     * Gets the expense payment
     */
    public function get_expense_payment(int $expense_id): ResultInterface
    {
        $builder = $this->db->table('expenses');
        $builder->where('expense_id', $expense_id);

        return $builder->get();
    }
}
