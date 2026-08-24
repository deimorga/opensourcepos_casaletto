<?php

namespace App\Models;

use App\Libraries\Item_lib;
use CodeIgniter\Database\ResultInterface;
use CodeIgniter\Model;
use Config\OSPOS;
use stdClass;

/**
 * Cashup class
 * Cashups are used to report actual cash on hand, expenses and transactions at the end of a period.
 */
class Cashup extends Model
{
    protected $table = 'cash_up';
    protected $primaryKey = 'cashup_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'open_date',
        'close_date',
        'open_amount_cash',
        'transfer_amount_cash',
        'note',
        'closed_amount_cash',
        'closed_amount_card',
        'closed_amount_check',
        'closed_amount_total',
        'description',
        'open_employee_id',
        'close_employee_id',
        'deleted',
        'closed_amount_due',
        'status',
        'location_id'
    ];

    /**
     * Determines if a given Cashup_id is a Cashup
     */
    public function exists(int $cashup_id): bool
    {
        $builder = $this->db->table('cash_up');
        $builder->where('cashup_id', $cashup_id);

        return ($builder->get()->getNumRows() == 1);    // TODO: ===
    }

    /**
     * Gets employee info
     */
    public function get_employee(int $cashup_id): object    // TODO: This function is never called and if it were called, would not yield proper results.  There is no employee_id field in the cash_up table.
    {
        $builder = $this->db->table('cash_up');
        $builder->where('cashup_id', $cashup_id);

        $employee = model(Employee::class);

        return $employee->get_info($builder->get()->getRow()->employee_id);
    }

    /**
     * @param string $cashup_ids
     * @return ResultInterface
     */
    public function get_multiple_info(string $cashup_ids): ResultInterface
    {
        $builder = $this->db->table('cash_up');
        $builder->whereIn('cashup_id', $cashup_ids);
        $builder->orderBy('cashup_id', 'asc');

        return $builder->get();
    }

    /**
     * Gets rows
     */
    public function get_found_rows(string $search, array $filters): int
    {
        return $this->search($search, $filters, 0, 0, 'cashup_id', 'asc', true);
    }

    /**
     * Searches cashups
     */
    public function search(string $search, array $filters, ?int $rows = 0, ?int $limit_from = 0, ?string $sort = 'cashup_id', ?string $order = 'asc', ?bool $count_only = false)
    {
        // Set default values
        if ($rows == null) $rows = 0;
        if ($limit_from == null) $limit_from = 0;
        if ($sort == null) $sort = 'cashup_id';
        if ($order == null) $order = 'asc';
        if ($count_only == null) $count_only = false;

        $config = config(OSPOS::class)->settings;
        $builder = $this->db->table('cash_up AS cash_up');

        // get_found_rows case
        if ($count_only) {
            $builder->select('COUNT(cash_up.cashup_id) as count');
        } else {
            $builder->select('
            cash_up.cashup_id,
            MAX(cash_up.status) AS status,
            MAX(cash_up.open_date) AS open_date,
            MAX(cash_up.close_date) AS close_date,
            MAX(cash_up.open_amount_cash) AS open_amount_cash,
            MAX(cash_up.transfer_amount_cash) AS transfer_amount_cash,
            MAX(cash_up.closed_amount_cash) AS closed_amount_cash,
            MAX(cash_up.closed_amount_due) AS closed_amount_due,
            MAX(cash_up.closed_amount_card) AS closed_amount_card,
            MAX(cash_up.closed_amount_check) AS closed_amount_check,
            MAX(cash_up.closed_amount_total) AS closed_amount_total,
            MAX(cash_up.description) AS description,
            MAX(cash_up.note) AS note,
            MAX(cash_up.open_employee_id) AS open_employee_id,
            MAX(cash_up.close_employee_id) AS close_employee_id,
            MAX(open_employees.first_name) AS open_first_name,
            MAX(open_employees.last_name) AS open_last_name,
            MAX(close_employees.first_name) AS close_first_name,
            MAX(close_employees.last_name) AS close_last_name
        ');

            // A correlated subquery rather than a join, because the count_only branch above returns
            // before the grouping and a join would multiply its rows -- one shift with three
            // collections would be counted three times.
            //
            // An open shift has no real close_date: opening writes the opening date into it as a
            // placeholder, so bounding by it would report zero for the shift a cashier is standing
            // at right now. Open shifts take no upper bound, the same rule
            // Cash_collection::get_total_collected_between() follows with a null end.
            $collections = $this->db->prefixTable('cash_collections');

            $builder->select("(
                SELECT IFNULL(SUM(collected.amount), 0)
                FROM $collections AS collected
                WHERE collected.deleted = 0
                  AND collected.collected_at >= cash_up.open_date
                  AND (cash_up.status = 'open' OR collected.collected_at <= cash_up.close_date)
            ) AS collected_amount", false);
        }

        $builder->join('people AS open_employees', 'open_employees.person_id = cash_up.open_employee_id', 'LEFT');
        $builder->join('people AS close_employees', 'close_employees.person_id = cash_up.close_employee_id', 'LEFT');

        $builder->groupStart();
        $builder->like('cash_up.open_date', $search);
        $builder->orLike('open_employees.first_name', $search);
        $builder->orLike('open_employees.last_name', $search);
        $builder->orLike('close_employees.first_name', $search);
        $builder->orLike('close_employees.last_name', $search);
        $builder->orLike('cash_up.closed_amount_total', $search);
        $builder->orLike('CONCAT(open_employees.first_name, " ", open_employees.last_name)', $search);
        $builder->orLike('CONCAT(close_employees.first_name, " ", close_employees.last_name)', $search);
        $builder->groupEnd();

        $builder->where('cash_up.deleted', $filters['is_deleted']);

        if (empty($config['date_or_time_format'])) {    // TODO: convert this to ternary notation.
            $builder->where('DATE_FORMAT(cash_up.open_date, "%Y-%m-%d") BETWEEN ' . $this->db->escape($filters['start_date']) . ' AND ' . $this->db->escape($filters['end_date']));
        } else {
            $builder->where('cash_up.open_date BETWEEN ' . $this->db->escape(rawurldecode($filters['start_date'])) . ' AND ' . $this->db->escape(rawurldecode($filters['end_date'])));
        }

        // get_found_rows case
        if ($count_only) {
            return $builder->get()->getRow()->count;
        } else {
            $builder->groupBy('cashup_id');
        }

        $builder->orderBy($sort, $order);

        if ($rows > 0) {
            $builder->limit($rows, $limit_from);
        }

        return $builder->get();
    }

    /**
     * Gets information about a particular cashup
     */
    public function get_info(int $cashup_id): object
    {
        $builder = $this->db->table('cash_up AS cash_up');
        $builder->select('
            cash_up.cashup_id AS cashup_id,
            cash_up.status AS status,
            cash_up.open_date AS open_date,
            cash_up.close_date AS close_date,
            cash_up.open_amount_cash AS open_amount_cash,
            cash_up.transfer_amount_cash AS transfer_amount_cash,
            cash_up.closed_amount_cash AS closed_amount_cash,
            cash_up.closed_amount_due AS closed_amount_due,
            cash_up.closed_amount_card AS closed_amount_card,
            cash_up.closed_amount_check AS closed_amount_check,
            cash_up.closed_amount_total AS closed_amount_total,
            cash_up.description AS description,
            cash_up.note AS note,
            cash_up.open_employee_id AS open_employee_id,
            cash_up.close_employee_id AS close_employee_id,
            cash_up.deleted AS deleted,
            open_employees.first_name AS open_first_name,
            open_employees.last_name AS open_last_name,
            close_employees.first_name AS close_first_name,
            close_employees.last_name AS close_last_name
        ');
        $builder->join('people AS open_employees', 'open_employees.person_id = cash_up.open_employee_id', 'LEFT');
        $builder->join('people AS close_employees', 'close_employees.person_id = cash_up.close_employee_id', 'LEFT');
        $builder->where('cashup_id', $cashup_id);

        $query = $builder->get();
        if ($query->getNumRows() == 1) {    // TODO: ===
            return $query->getRow();
        } else {
            return $this->getEmptyObject('cash_up');
        }
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
     * The shift that is open right now, or null when none is.
     *
     * Lives here rather than on Sale because three callers need the same answer -- the sale being
     * sealed, the restriction that refuses a second open shift, and the reconciliation -- and three
     * copies of one query is how the expense grid ended up disagreeing with its own totals.
     *
     * Only one shift is supposed to be open at a time, and postSave() now refuses to open a second.
     * The history says otherwise, though: shift 32 was opened while 31 was still running, and
     * add_cashup_status.sql only marked a shift closed when it had a non-zero closing amount, so
     * old shifts can still be sitting here marked open. This picks the one opened most recently,
     * which is the drawer the cashier is actually standing at, and says out loud that it had to
     * choose.
     */
    public function get_open_cashup_id(): ?int
    {
        $builder = $this->db->table('cash_up');
        $builder->select('cashup_id');
        $builder->where('status', 'open');
        $builder->where('deleted', 0);
        $builder->orderBy('open_date', 'desc');
        $builder->orderBy('cashup_id', 'desc');

        $rows = $builder->get()->getResultArray();

        if ($rows === []) {
            return null;
        }

        if (count($rows) > 1) {
            // Error rather than warning: the production log threshold is 4, which throws warnings
            // away, and money landing in the wrong drawer is not something to find out later.
            log_message('error', count($rows) . ' cash-up shifts are open at once. Sales are being sealed with the most recently opened one, ' . $rows[0]['cashup_id'] . '. Close the others.');
        }

        return (int) $rows[0]['cashup_id'];
    }

    /**
     * Inserts or updates a cashup
     */
    public function save_value(array &$cash_up_data, $cashup_id = NEW_ENTRY): bool
    {
        if (!$cashup_id == NEW_ENTRY || !$this->exists($cashup_id)) {
            $cash_up_data['location_id'] = (int) (new Item_lib())->get_item_location();

            $builder = $this->db->table('cash_up');
            if ($builder->insert($cash_up_data)) {
                $cash_up_data['cashup_id'] = $this->db->insertID();

                return true;
            }

            return false;
        }

        $builder = $this->db->table('cash_up');
        $builder->where('cashup_id', $cashup_id);

        return $builder->update($cash_up_data);
    }

    /**
     * Deletes a list of cashups
     */
    public function delete_list(array $cashup_ids): bool
    {
        // Run these queries as a transaction, we want to make sure we do all or nothing
        $this->db->transStart();
        $builder = $this->db->table('cash_up');
        $builder->whereIn('cashup_id', $cashup_ids);
        $success = $builder->update(['deleted' => 1]);
        $this->db->transComplete();

        return $success;
    }

    /**
     * Reopens a list of closed cashups, resetting their closing amounts so the
     * sales auto-fill in Cashups::getView() recalculates fresh next time they're
     * edited. Only affects cashups that are actually closed.
     */
    public function reopen_list(array $cashup_ids): bool
    {
        $this->db->transStart();
        $builder = $this->db->table('cash_up');
        $builder->whereIn('cashup_id', $cashup_ids);
        $builder->where('status', 'closed');
        $success = $builder->update([
            'status'               => 'open',
            'closed_amount_cash'   => 0,
            'closed_amount_due'    => 0,
            'closed_amount_card'   => 0,
            'closed_amount_check'  => 0,
            'closed_amount_total'  => 0
        ]);
        $this->db->transComplete();

        return $success;
    }
}
