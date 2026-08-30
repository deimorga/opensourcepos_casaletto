<?php

namespace App\Models\Reports;

use App\Models\Item;

/**
 * What was written off, by item and by reason, over a date range, with what it cost.
 *
 * This is the report the classification exists for. Before it, the only trace of a damaged product
 * was a negative adjustment with a hand-typed comment: impossible to add up, impossible to compare
 * one week against the next, impossible to attribute to a product. In perishables that is the cost
 * that hurts most and it was invisible.
 *
 * Grouping is by item AND reason rather than by item alone: "we lost 400.000 in onions" and "we
 * lost 400.000 in onions to theft" call for two completely different conversations.
 *
 * @property item item
 */
class Inventory_write_offs extends Report
{
    /**
     * @return array[]
     */
    public function getDataColumns(): array
    {
        return [
            ['item_name'      => lang('Writeoffs.item_name')],
            ['item_number'    => lang('Writeoffs.item_number')],
            ['reason'         => lang('Writeoffs.reason')],
            ['quantity'       => lang('Writeoffs.quantity')],
            ['cost_price'     => lang('Writeoffs.cost_price'), 'sorter' => 'number_sorter'],
            ['write_off_cost' => lang('Writeoffs.write_off_cost'), 'sorter' => 'number_sorter']
        ];
    }

    /**
     * @param array $inputs start_date, end_date and location_id ('all' or an id)
     * @return array
     */
    public function getData(array $inputs): array
    {
        $item = model(Item::class);

        $builder = $this->db->table('inventory AS inventory');
        $builder->select(
            $item->get_item_name('name') . ',
            items.item_number,
            inventory.reason_code,
            items.cost_price,
            (SUM(inventory.trans_inventory) * -1) AS quantity,
            (SUM(inventory.trans_inventory * items.cost_price) * -1) AS write_off_cost'
        );
        $builder->join('items AS items', 'items.item_id = inventory.trans_items');

        $this->restrict($builder, $inputs);

        $builder->groupBy('inventory.trans_items');
        $builder->groupBy('inventory.reason_code');
        $builder->orderBy('write_off_cost', 'desc');
        $builder->orderBy('items.name', 'asc');

        return $builder->get()->getResultArray();
    }

    /**
     * The totals, plus one line per reason so the answer to "where is it going" is on the screen
     * without anybody having to add the rows up.
     *
     * @param array $inputs same inputs as getData()
     * @return array
     */
    public function getSummaryData(array $inputs): array
    {
        $builder = $this->db->table('inventory AS inventory');
        $builder->select(
            'inventory.reason_code,
            (SUM(inventory.trans_inventory) * -1) AS quantity,
            (SUM(inventory.trans_inventory * items.cost_price) * -1) AS write_off_cost'
        );
        $builder->join('items AS items', 'items.item_id = inventory.trans_items');

        $this->restrict($builder, $inputs);

        $builder->groupBy('inventory.reason_code');

        $by_reason = [];
        $total_cost = 0;
        $total_quantity = 0;

        foreach ($builder->get()->getResultArray() as $row) {
            $by_reason[$row['reason_code']] = [
                'quantity'       => $row['quantity'],
                'write_off_cost' => $row['write_off_cost']
            ];

            $total_cost += (float) $row['write_off_cost'];
            $total_quantity += (float) $row['quantity'];
        }

        return [
            'by_reason'      => $by_reason,
            'total_cost'     => $total_cost,
            'total_quantity' => $total_quantity
        ];
    }

    /**
     * The one WHERE clause both queries share.
     *
     * reason_code IS NOT NULL is what separates a write-off from every other movement in this
     * table. Sales, receivings and plain adjustments live here too and carry no reason; so does
     * everything recorded before the classification existed.
     *
     * Items flagged as deleted are deliberately NOT excluded. A product that was written off in
     * March and retired in June still cost the business money in March, and dropping it would
     * quietly shrink a past period every time somebody tidies the catalogue.
     *
     * @param object $builder
     */
    private function restrict($builder, array $inputs): void
    {
        $builder->where('inventory.reason_code IS NOT NULL');
        $builder->where('inventory.trans_date >=', $inputs['start_date']);
        $builder->where('inventory.trans_date <=', $inputs['end_date']);

        if (($inputs['location_id'] ?? 'all') !== 'all') {
            $builder->where('inventory.trans_location', $inputs['location_id']);
        }
    }
}
