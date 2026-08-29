<?php

namespace App\Models;

use CodeIgniter\Model;
use stdClass;

/**
 * Item_quantity class
 */
class Item_quantity extends Model
{
    protected $table = 'item_quantities';
    protected $primaryKey = 'item_id';
    protected $useAutoIncrement = false;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'quantity'
    ];

    protected $item_id;
    protected $location_id;
    protected $quantity;

    /**
     * Decimals every quantity column in the schema can physically hold:
     * item_quantities.quantity, sales_items.quantity_purchased,
     * receivings_items.quantity_purchased and inventory.trans_inventory are
     * all decimal(15,3). See initial_schema.sql.
     */
    public const STORED_QUANTITY_DECIMALS = 3;

    /**
     * The scale (number of decimals) that every computation on a quantity
     * must be done with. Pass it explicitly to bcadd()/bcmul(); never rely on
     * the ambient bcmath scale.
     *
     * Why not the ambient scale: Load_config.php sets it globally to
     * max(2, totals_decimals() + tax_decimals()), and totals_decimals()
     * returns `currency_decimals` -- a *money* setting that has nothing to do
     * with weight. A shop with currency_decimals = 0 and tax_decimals = 2
     * therefore computes every quantity at scale 2, silently dropping the
     * third decimal: bcadd('0.735', '0.740') gives 1.47 instead of 1.475.
     * Five grams per operation, with no error and no trace.
     *
     * Why not plain quantity_decimals(): that is the tenant's *display*
     * setting, and it is not a safe floor. The columns hold three decimals
     * regardless, so a tenant showing zero decimals can still legitimately
     * have 5.500 units on file; computing its stock at scale 0 would round
     * that half unit away -- a brand new defect shipped under cover of a fix.
     * Displaying fewer decimals is the formatter's job, not arithmetic's.
     *
     * See docs/Tecnico/venta-por-peso-y-hardware-de-caja.md sections 2.2/2.4.
     */
    public static function quantity_scale(): int
    {
        return max(quantity_decimals(), self::STORED_QUANTITY_DECIMALS);
    }

    /**
     * @param int $item_id
     * @param int $location_id
     * @return bool
     */
    public function exists(int $item_id, int $location_id): bool
    {
        $builder = $this->db->table('item_quantities');
        $builder->where('item_id', $item_id);
        $builder->where('location_id', $location_id);

        return ($builder->get()->getNumRows() == 1);    // TODO: ===
    }

    /**
     * @param array $location_detail
     * @param int $item_id
     * @param int $location_id
     * @return bool
     */
    public function save_value(array $location_detail, int $item_id, int $location_id): bool
    {
        if (!$this->exists($item_id, $location_id)) {
            $builder = $this->db->table('item_quantities');
            return $builder->insert($location_detail);
        }

        $builder = $this->db->table('item_quantities');
        $builder->where('item_id', $item_id);
        $builder->where('location_id', $location_id);

        return $builder->update($location_detail);
    }

    /**
     * @param int $item_id
     * @param int $location_id
     * @return array|Item_quantity|stdClass|null
     */
    public function get_item_quantity(int $item_id, int $location_id): array|Item_quantity|StdClass|null
    {
        $builder = $this->db->table('item_quantities');
        $builder->where('item_id', $item_id);
        $builder->where('location_id', $location_id);
        $result = $builder->get()->getRow();

        if (empty($result)) {
            // Get empty base parent object, as $item_id is NOT an item
            $result = model(Item_quantity::class);

            // Get all the fields from items table (TODO: to be reviewed)
            foreach ($this->db->getFieldNames('item_quantities') as $field) {
                $result->$field = '';
            }

            $result->quantity = 0;
        }

        return $result;
    }

    /**
     * changes to quantity of an item according to the given amount.
     * if $quantity_change is negative, it will be subtracted,
     * if it is positive, it will be added to the current quantity
     *
     * $quantity_change is a decimal string, not an int. It used to be typed
     * `int`, and the two callers that void a transaction to put stock back --
     * Sale::delete() and Receiving::delete_value() -- hand it a weight. PHP's
     * coercive typing turned '0.735' into a plain 0, so the stock never moved
     * while the `inventory` audit row the caller writes two lines earlier did
     * record the 735 g. The two tables disagreed with nothing to warn anyone,
     * and an inventory error is invisible because the sale still balances in
     * money. See docs/Tecnico/venta-por-peso-y-hardware-de-caja.md section 2.2.
     *
     * Callers must pass a plain decimal string. A float cast to string can
     * come out in scientific notation ("1.0E-6"), which bcadd() rejects with
     * a ValueError -- do the arithmetic in bcmath at the caller instead.
     */
    public function change_quantity(int $item_id, int $location_id, string $quantity_change): bool
    {
        $quantity_old = $this->get_item_quantity($item_id, $location_id);

        // Explicit scale: the ambient bcmath scale is derived from currency
        // settings and would truncate weights. See quantity_scale().
        $quantity_new = bcadd((string)$quantity_old->quantity, $quantity_change, self::quantity_scale());
        $location_detail = ['item_id' => $item_id, 'location_id' => $location_id, 'quantity' => $quantity_new];

        return $this->save_value($location_detail, $item_id, $location_id);
    }

    /**
     * Set to 0 all quantity in the given item
     */
    public function reset_quantity(int $item_id): bool
    {
        $builder = $this->db->table('item_quantities');
        $builder->where('item_id', $item_id);

        return $builder->update(['quantity' => 0]);
    }

    /**
     * Set to 0 all quantity in the given list of items
     */
    public function reset_quantity_list(array $item_ids): bool
    {
        $builder = $this->db->table('item_quantities');
        $builder->whereIn('item_id', $item_ids);

        return $builder->update(['quantity' => 0]);
    }
}
