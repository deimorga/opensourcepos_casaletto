<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * What a campaign has committed: the units promised in OPEN presales, by product and delivery date,
 * against what is in stock. Read-only; it never writes.
 *
 * Kit lines are stored as their components (docs/Tecnico/venta-anticipada.md), so counting the rows of
 * presale_items is already "what has to be bought". Delivered and canceled presales are not
 * commitments any more and are left out.
 *
 * Stock is added up over the locations the campaign's open presales deliver from, because that is
 * where the units have to be when the day comes.
 *
 * Quantities are strings at three decimals (the scale of presale_items.quantity) and are compared with
 * bcmath at that explicit scale, never the ambient one.
 */
class Presale_report extends Model
{
    public const SCALE = 3;

    protected $table      = 'presales';
    protected $primaryKey = 'presale_id';

    /**
     * @return array{dates: list<string>, rows: list<array<string, mixed>>}
     *
     * dates: the delivery dates that have commitments, ascending (Y-m-d).
     * rows:  one per product, by name: item_id, name, item_number, unit_of_measure,
     *        by_date (date => quantity), total, stock, shortfall (zero when covered).
     */
    public function committed(int $campaign_id): array
    {
        $lines = $this->db->table('presale_items AS pi')
            ->select('pi.item_id, i.name, i.item_number, i.unit_of_measure, p.delivery_date, SUM(pi.quantity) AS committed', false)
            ->join('presales AS p', "p.presale_id = pi.presale_id AND p.status = 'open'", 'inner')
            ->join('items AS i', 'i.item_id = pi.item_id', 'inner')
            ->where('p.campaign_id', $campaign_id)
            // A kit is stored as its own line followed by its components (Presale::price_lines()).
            // What has to be bought is the components; counting the kit's line too would ask for a
            // product that is only a recipe.
            ->where('pi.item_type !=', ITEM_KIT)
            ->groupBy('pi.item_id, i.name, i.item_number, i.unit_of_measure, p.delivery_date')
            ->orderBy('i.name', 'asc')
            ->orderBy('pi.item_id', 'asc')
            ->orderBy('p.delivery_date', 'asc')
            ->get()->getResultArray();

        $zero  = bcadd('0', '0', self::SCALE);
        $dates = [];
        $rows  = [];

        foreach ($lines as $line) {
            $id   = (int) $line['item_id'];
            $date = (string) $line['delivery_date'];

            $dates[$date] = true;

            if (! isset($rows[$id])) {
                $rows[$id] = [
                    'item_id'         => $id,
                    'name'            => (string) $line['name'],
                    'item_number'     => (string) ($line['item_number'] ?? ''),
                    'unit_of_measure' => (string) ($line['unit_of_measure'] ?? ''),
                    'by_date'         => [],
                    'total'           => $zero,
                ];
            }

            $quantity                    = bcadd((string) $line['committed'], '0', self::SCALE);
            $rows[$id]['by_date'][$date] = $quantity;
            $rows[$id]['total']          = bcadd($rows[$id]['total'], $quantity, self::SCALE);
        }

        $stock = $this->stockOf(array_keys($rows), $this->locationsOf($campaign_id));

        foreach ($rows as $id => &$row) {
            $row['stock']     = $stock[$id] ?? $zero;
            $missing          = bcsub($row['total'], $row['stock'], self::SCALE);
            $row['shortfall'] = bccomp($missing, '0', self::SCALE) > 0 ? $missing : $zero;
        }
        unset($row);

        $dates = array_keys($dates);
        sort($dates);

        return ['dates' => $dates, 'rows' => array_values($rows)];
    }

    /**
     * @return list<int>
     */
    private function locationsOf(int $campaign_id): array
    {
        $rows = $this->db->table('presales')
            ->select('location_id')
            ->distinct()
            ->where('campaign_id', $campaign_id)
            ->where('status', 'open')
            ->get()->getResultArray();

        return array_map(static fn (array $r): int => (int) $r['location_id'], $rows);
    }

    /**
     * @param list<int> $item_ids
     * @param list<int> $location_ids
     *
     * @return array<int, string> item_id => quantity
     */
    private function stockOf(array $item_ids, array $location_ids): array
    {
        if ($item_ids === [] || $location_ids === []) {
            return [];
        }

        $rows = $this->db->table('item_quantities')
            ->select('item_id, SUM(quantity) AS stock', false)
            ->whereIn('item_id', $item_ids)
            ->whereIn('location_id', $location_ids)
            ->groupBy('item_id')
            ->get()->getResultArray();

        $stock = [];

        foreach ($rows as $row) {
            $stock[(int) $row['item_id']] = bcadd((string) $row['stock'], '0', self::SCALE);
        }

        return $stock;
    }
}
