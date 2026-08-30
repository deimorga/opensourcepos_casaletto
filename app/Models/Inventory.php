<?php

namespace App\Models;

use CodeIgniter\Database\ResultInterface;
use CodeIgniter\Model;

/**
 * Inventory class
 *
 * @property employee employee
 *
 */
class Inventory extends Model
{
    /**
     * The reasons a write-off can be attributed to, as stable codes.
     *
     * Codes and not translated words, for the same reason payment_type_code and cash_source are
     * codes: the label depends on the locale the row was written under, so a report that groups or
     * filters on the label stops matching the moment the language changes. Anything reported,
     * summed or compared across weeks has to key off something that does not move.
     *
     * The human wording lives in app/Language/<locale>/Writeoffs.php under 'reason_<code>'.
     */
    public const REASON_DAMAGED = 'damaged';
    public const REASON_SHRINKAGE = 'shrinkage';
    public const REASON_THEFT = 'theft';
    public const REASON_DATA_ENTRY = 'data_entry';

    public const WRITE_OFF_REASON_CODES = [
        self::REASON_DAMAGED,
        self::REASON_SHRINKAGE,
        self::REASON_THEFT,
        self::REASON_DATA_ENTRY
    ];

    protected $table = 'inventory';
    protected $primaryKey = 'trans_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'trans_items',
        'trans_user',
        'trans_date',
        'trans_comment',
        'trans_inventory',
        'trans_location',
        'reason_code'
    ];

    /**
     * @param $comment
     * @param $inventory_data
     * @return bool
     */
    public function update($comment = null, $inventory_data = null): bool
    {
        $builder = $this->db->table('inventory');
        $builder->where('trans_comment', $comment);

        return $builder->update($inventory_data);
    }

    /**
     * Retrieves inventory data given an item_id.
     *
     * @param int $item_id
     * @param bool $location_id
     * @return ResultInterface
     */
    public function get_inventory_data_for_item(int $item_id, bool $location_id = false): ResultInterface
    {
        $builder = $this->db->table('inventory');
        $builder->where('trans_items', $item_id);

        if ($location_id) {
            $builder->where('trans_location', $location_id);
        }

        $builder->orderBy('trans_date', 'desc');

        return $builder->get();
    }

    /**
     * @param int $item_id ID number for the item to have quantity reset.
     * @return bool|int|string The row id of the inventory table on insert or false on failure
     */
    public function reset_quantity(int $item_id): bool|int|string
    {
        $inventory_sums = $this->get_inventory_sum($item_id);
        foreach ($inventory_sums as $inventory_sum) {
            if ($inventory_sum['sum'] > 0) {
                $employee = model(Employee::class);

                return $this->insert([
                    'trans_inventory' => -1 * $inventory_sum['sum'],
                    'trans_items'     => $item_id,
                    'trans_location'  => $inventory_sum['location_id'],
                    'trans_comment'   => lang('Items.is_deleted'),
                    'trans_user'      => $employee->get_logged_in_employee_info()->person_id
                ]);
            }
        }

        return true;
    }

    /**
     * True when $reason_code is one this application knows how to report on.
     *
     * Deliberately strict: an unrecognised code is refused rather than stored, because a code
     * nobody can group by is exactly the free text this feature exists to replace.
     */
    public static function is_write_off_reason(?string $reason_code): bool
    {
        return $reason_code !== null && in_array($reason_code, self::WRITE_OFF_REASON_CODES, true);
    }

    /**
     * Takes stock out of inventory and says why, in one transaction.
     *
     * $quantity is the positive amount being written off, as a plain decimal string ("0.735"), and
     * it is stored negated: a write-off is stock leaving, and `inventory` records movements with a
     * sign. Passing it as a string and never a float is not stylistic -- Item_quantity's own note
     * spells out that a float cast to string can come out as "1.0E-6", which bcmath rejects.
     *
     * All arithmetic is done at Item_quantity::quantity_scale() and never at the ambient bcmath
     * scale: that one is derived from the tenant's *currency* settings and would quietly drop the
     * third decimal of a weight. Half a kilo of cheese has to stay half a kilo.
     *
     * The audit row and the balance move together or not at all. Writing one without the other is
     * the failure mode that makes an inventory error invisible: the numbers disagree and nothing
     * says so.
     *
     * @param string $quantity positive decimal string, e.g. "0.735"
     * @return bool false when the input is refused or the transaction failed; nothing is written
     */
    public function record_write_off(int $item_id, int $location_id, string $quantity, string $reason_code, ?string $comment, int $employee_id): bool
    {
        if (!self::is_write_off_reason($reason_code)) {
            return false;
        }

        $scale = Item_quantity::quantity_scale();

        if (!self::is_decimal_string($quantity) || bccomp($quantity, '0', $scale) <= 0) {
            return false;
        }

        $signed_quantity = bcmul($quantity, '-1', $scale);

        $this->db->transStart();

        $this->insert([
            'trans_date'      => date('Y-m-d H:i:s'),
            'trans_items'     => $item_id,
            'trans_user'      => $employee_id,
            'trans_location'  => $location_id,
            'trans_comment'   => $comment ?? '',
            'trans_inventory' => $signed_quantity,
            'reason_code'     => $reason_code
        ], false);

        model(Item_quantity::class)->change_quantity($item_id, $location_id, $signed_quantity);

        $this->db->transComplete();

        return $this->db->transStatus();
    }

    /**
     * A plain decimal string bcmath can read, with no sign, no grouping and no exponent.
     *
     * bccomp() throws a ValueError on anything else, and this method is reached straight from a
     * form: an empty field or a stray letter has to come back as a refusal, not a 500.
     */
    private static function is_decimal_string(string $value): bool
    {
        return preg_match('/^\d+(?:\.\d+)?$/', $value) === 1;
    }

    /**
     * @param int $item_id
     * @return array
     */
    public function get_inventory_sum(int $item_id): array
    {
        $builder = $this->db->table('inventory');
        $builder->select('SUM(trans_inventory) AS sum, MAX(trans_location) AS location_id');
        $builder->where('trans_items', $item_id);
        $builder->groupBy('trans_location');

        return $builder->get()->getResultArray();
    }
}
