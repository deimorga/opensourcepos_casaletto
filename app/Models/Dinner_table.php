<?php

namespace App\Models;

use App\Libraries\Item_lib;
use CodeIgniter\Database\ResultInterface;
use CodeIgniter\Model;

/**
 * Dinner_table class
 */
class Dinner_table extends Model
{
    protected $table = 'dinner_tables';
    protected $primaryKey = 'dinner_table_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'name',
        'status',
        'deleted',
        'location_id'
    ];

    /**
     * @param int $dinner_table_id
     * @return bool
     */
    public function exists(int $dinner_table_id): bool
    {
        $builder = $this->db->table('dinner_tables');
        $builder->where('dinner_table_id', $dinner_table_id);

        return ($builder->get()->getNumRows() >= 1);
    }

    /**
     * Creates a new dinner table on the fly (e.g. from the Register "new
     * table" button) and returns its id, unlike save_value() which is
     * built around Config's bulk-save-all-tables form and only reports
     * success/failure.
     */
    public function create(string $name): int
    {
        return $this->create_at($name, (int) (new Item_lib())->get_item_location());
    }

    /**
     * Same as create(), with the location given instead of resolved from the session.
     *
     * create() resolves it through Item_lib::get_item_location(), which ends in
     * Stock_location::get_default_location_id('items') -- and that dereferences a row that does not
     * exist for an employee without an items location grant. An order-ticket waiter is exactly that
     * employee, so the ticket screen resolves the location itself and passes it here.
     *
     * $occupied: an open account occupies its table (Sale::save_value() does it for the register's
     * tabs). A table created for an order ticket is born with its sale, so it is born occupied --
     * otherwise it would also be offered in the register's list of free tables.
     *
     * @return int the new dinner_table_id, 0 when the insert failed
     */
    public function create_at(string $name, int $location_id, bool $occupied = false): int
    {
        $builder = $this->db->table('dinner_tables');
        $inserted = $builder->insert([
            'name'        => $name,
            'status'      => $occupied ? 1 : 0,
            'deleted'     => 0,
            'location_id' => $location_id
        ]);

        return $inserted ? (int) $this->db->insertID() : 0;
    }

    /**
     * @param array $table_data
     * @param int $dinner_table_id
     * @return bool
     */
    public function save_value(array $table_data, int $dinner_table_id): bool
    {
        $table_data_to_save = ['name' => $table_data['name'], 'deleted' => 0];

        $builder = $this->db->table('dinner_tables');
        if (!$this->exists($dinner_table_id)) {
            $table_data_to_save['location_id'] = (int) (new Item_lib())->get_item_location();

            return $builder->insert($table_data_to_save);
        }

        $builder->where('dinner_table_id', $dinner_table_id);

        return $builder->update($table_data_to_save);
    }

    /**
     * Get empty tables
     */
    public function get_empty_tables(?int $current_dinner_table_id): array
    {
        $builder = $this->db->table('dinner_tables');
        $builder->where('deleted', 0);
        // Grouped explicitly: without groupStart()/groupEnd(), AND binds
        // tighter than OR in the generated SQL (WHERE deleted = 0 AND status
        // = 0 OR dinner_table_id = ?), so any soft-deleted table with
        // status = 0 (the common case -- deleting doesn't touch status)
        // would still leak into the free-table list regardless of
        // `deleted`. See docs/Tecnico/ventas-en-paralelo-pestanas.md
        // section 12.
        $builder->groupStart();
        $builder->where('status', 0);
        $builder->orWhere('dinner_table_id', $current_dinner_table_id);
        $builder->groupEnd();

        $empty_tables = $builder->get()->getResultArray();

        $empty_tables_array = [];    // TODO: Variable names should not contain the name of the datatype.
        foreach ($empty_tables as $empty_table) {
            $empty_tables_array[$empty_table['dinner_table_id']] = $empty_table['name'];
        }

        return $empty_tables_array;
    }

    /**
     * @param int $dinner_table_id
     * @return string
     */
    public function get_name(?string $dinner_table_id): string
    {
        if (empty($dinner_table_id)) {
            return '';
        } else {    // TODO: No need for this else statement.  Just put it's contents outside of the else since the if has a return in it.
            $builder = $this->db->table('dinner_tables');
            $builder->where('dinner_table_id', $dinner_table_id);

            return $builder->get()->getRow()->name;
        }
    }

    /**
     * @param int $dinner_table_id
     * @return bool
     */
    public function is_occupied(int $dinner_table_id): bool
    {
        if (empty($dinner_table_id)) {
            return false;
        } else {    // TODO: No need for this else statement.  Just put it's contents outside of the else since the if has a return in it.
            $builder = $this->db->table('dinner_tables');
            $builder->where('dinner_table_id', $dinner_table_id);

            return ($builder->get()->getRow()->status == 1);    // TODO: === ?
        }
    }

    /**
     * @return ResultInterface
     */
    public function get_all(): ResultInterface
    {
        $builder = $this->db->table('dinner_tables');
        $builder->where('deleted', 0);

        return $builder->get();
    }

    /**
     * Deletes one dinner table
     */
    public function delete($dinner_table_id = null, bool $purge = false): bool
    {
        $builder = $this->db->table('dinner_tables');
        $builder->where('dinner_table_id', $dinner_table_id);

        return $builder->update(['deleted' => 1]);
    }

    /**
     * Occupy table
     * Ignore the Delivery and Takeaway "tables".  They should never be occupied.
     */
    public function occupy(int $dinner_table_id): bool
    {
        if ($dinner_table_id > 2) {
            $builder = $this->db->table('dinner_tables');
            $builder->where('dinner_table_id', $dinner_table_id);
            return $builder->update(['status' => 1]);
        } else {    // TODO: THIS ELSE STATEMENT ISN'T NEEDED.  JUST DO THE IF AND THEN RETURN true AFTER IT.
            return true;
        }
    }

    /**
     * Release table
     */
    public function release(int $dinner_table_id): bool
    {
        if ($dinner_table_id > 2) {
            $builder = $this->db->table('dinner_tables');
            $builder->where('dinner_table_id', $dinner_table_id);
            return $builder->update(['status' => 0]);
        } else {
            return true;
        }
    }

    /**
     * Swap tables
     */
    public function swap_tables(int $release_dinner_table_id, int $occupy_dinner_table_id): bool
    {
        return $this->release($release_dinner_table_id) && $this->occupy($occupy_dinner_table_id);
    }
}
