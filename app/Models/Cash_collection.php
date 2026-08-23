<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\ResultInterface;
use CodeIgniter\Model;
use stdClass;

/**
 * Cash_collection class
 *
 * A cash collection is money taken out of the drawer to the bank or to a reserve. It is a transfer
 * between pockets, not an expense: nothing was spent, so it must never reach any expense total nor
 * the income-vs-expenses report. Its only effect is on the cash expected in the drawer.
 *
 * A collection belongs to whichever shift was open at collected_at. The row stores no cashup_id on
 * purpose -- see the migration and docs/Tecnico/cuadre-de-caja-y-origen-del-efectivo.md section 4.
 */
class Cash_collection extends Model
{
    protected $table = 'cash_collections';
    protected $primaryKey = 'collection_id';
    protected $useAutoIncrement = true;

    /**
     * Deleting is done by hand through the deleted column, the way the rest of this codebase does
     * it, so CodeIgniter's own soft deletes stay off.
     */
    protected $useSoftDeletes = false;

    /**
     * Every writable column has to be listed here. CodeIgniter drops anything missing from this
     * list without raising, which has already cost this project data twice, and here the value it
     * would drop is money or the time the money left.
     */
    protected $allowedFields = [
        'amount',
        'collected_at',
        'collected_by',
        'registered_by',
        'note',
        'deleted'
    ];

    /**
     * Determines if a given collection_id is a cash collection
     *
     * Deleted rows still exist, matching Expense_category: save_value relies on this to tell an
     * update from an insert, and a deleted row must be updated rather than duplicated.
     */
    public function exists(int $collection_id): bool
    {
        $builder = $this->db->table('cash_collections');
        $builder->where('collection_id', $collection_id);

        return ($builder->get()->getNumRows() === 1);
    }

    /**
     * Gets information about a particular cash collection
     */
    public function get_info(int $collection_id): object
    {
        $builder = $this->db->table('cash_collections');
        $builder->where('collection_id', $collection_id);
        $builder->where('deleted', 0);
        $query = $builder->get();

        if ($query->getNumRows() === 1) {
            return $query->getRow();
        }

        // Get an empty base object, as $collection_id is NOT a cash collection
        $collection_obj = new stdClass();

        foreach ($this->db->getFieldNames('cash_collections') as $field) {
            $collection_obj->$field = '';
        }

        return $collection_obj;
    }

    /**
     * Inserts or updates a cash collection
     *
     * $collection_data is taken by reference so the caller gets the new collection_id back on an
     * insert, the same way the other models in this codebase hand it over.
     */
    public function save_value(array &$collection_data, int $collection_id = NEW_ENTRY): bool
    {
        $builder = $this->db->table('cash_collections');

        if ($collection_id === NEW_ENTRY || !$this->exists($collection_id)) {
            if ($builder->insert($collection_data)) {
                $collection_data['collection_id'] = $this->db->insertID();

                return true;
            }

            return false;
        }

        $builder->where('collection_id', $collection_id);

        return $builder->update($collection_data);
    }

    /**
     * Deletes a list of cash collections
     *
     * Logical, never physical: a collection that turns out to be wrong still has to be auditable,
     * because it moved real money out of a drawer that was counted afterwards.
     */
    public function delete_list(array $collection_ids): bool
    {
        $builder = $this->db->table('cash_collections');
        $builder->whereIn('collection_id', $collection_ids);

        return $builder->update(['deleted' => 1]);
    }

    /**
     * Gets the total collected inside a window of time, ignoring deleted collections
     *
     * This is what the shift reconciliation subtracts:
     *
     *     expected = opening + cash sales - expenses paid from the drawer - collections
     *
     * The caller passes the shift's own window, so a cashup reads:
     *
     *     $collected = $cash_collection->get_total_collected_between($cashup->open_date, $cashup->close_date);
     *
     * Both ends are inclusive, which is what "the shift that was open at collected_at" means for a
     * collection recorded at the very second a shift opened or closed. It also matters in practice:
     * shift 39 in production lasted 44 seconds, so a window that dropped its edges would have
     * nothing left.
     *
     * $end_date is nullable because an open shift has no close_date yet, and the reconciliation is
     * shown while the cashier is still closing. Null means no upper bound rather than "now", so a
     * collection accidentally dated into the future shows up in the shift it was entered against
     * instead of disappearing until the clock catches up.
     *
     * Returns 0.0 when nothing was collected -- never null. The reconciliation subtracts this value
     * directly, and a null there would silently turn the expected cash into nothing.
     *
     * @param string      $start_date Inclusive lower bound, 'Y-m-d H:i:s'
     * @param string|null $end_date   Inclusive upper bound, 'Y-m-d H:i:s', or null for no upper bound
     */
    public function get_total_collected_between(string $start_date, ?string $end_date = null): float
    {
        $builder = $this->db->table('cash_collections AS cash_collections');
        $builder->select('SUM(cash_collections.amount) AS total');

        $this->apply_window($builder, $start_date, $end_date);

        $row = $builder->get()->getRow();

        return (float)($row->total ?? 0);
    }

    /**
     * Gets the cash collections inside a window of time, ignoring deleted ones
     *
     * Same window and same exclusions as get_total_collected_between, so the list a shift shows and
     * the total it subtracts can never tell two different stories.
     *
     * The employee names come along because every caller needs them and the alternative is a query
     * per row. The join is LEFT so that a collection never vanishes from the shift it belongs to
     * just because the person row behind it changed.
     *
     * @param string      $start_date Inclusive lower bound, 'Y-m-d H:i:s'
     * @param string|null $end_date   Inclusive upper bound, 'Y-m-d H:i:s', or null for no upper bound
     */
    public function get_collected_between(string $start_date, ?string $end_date = null): ResultInterface
    {
        $builder = $this->db->table('cash_collections AS cash_collections');

        $builder->select('
            cash_collections.collection_id,
            cash_collections.amount,
            cash_collections.collected_at,
            cash_collections.collected_by,
            cash_collections.registered_by,
            cash_collections.note,
            collectors.first_name AS collected_by_first_name,
            collectors.last_name AS collected_by_last_name,
            registrars.first_name AS registered_by_first_name,
            registrars.last_name AS registered_by_last_name
        ');

        $builder->join('people AS collectors', 'collectors.person_id = cash_collections.collected_by', 'LEFT');
        $builder->join('people AS registrars', 'registrars.person_id = cash_collections.registered_by', 'LEFT');

        $this->apply_window($builder, $start_date, $end_date);

        $builder->orderBy('cash_collections.collected_at', 'asc');

        return $builder->get();
    }

    /**
     * Restricts a builder to the collections that count inside a window of time
     *
     * Both readers go through here so the window can only ever be defined once. A total and a list
     * that each built their own WHERE clause would eventually drift apart, and the shift would show
     * collections that its own arithmetic did not subtract.
     */
    private function apply_window(BaseBuilder $builder, string $start_date, ?string $end_date): void
    {
        $builder->where('cash_collections.deleted', 0);
        $builder->where('cash_collections.collected_at >=', $start_date);

        if ($end_date !== null) {
            $builder->where('cash_collections.collected_at <=', $end_date);
        }
    }
}
