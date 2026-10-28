<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * The presales reports. Read-only; they never write.
 *
 * committed(): what a campaign has committed, the units promised in OPEN presales by product and
 * delivery date, against what is in stock.
 *
 * portfolio(): the money side of the same open presales -- sold, collected, still to collect, overdue
 * by age, what falls due in the next seven days and who is late (docs/Tecnico/preventas-cartera-y-avisos.md
 * §3). Every figure of one presale comes from Presale::derive() and Presale::coverage(): this class
 * only adds them up, so "an instalment due today is not overdue" is written in one place.
 *
 * brand_discounts(): the cashier's discounts the brand pays back, for the report the business sends
 * the brand at the end of the season.
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

    /**
     * Days of the "next seven days" window: today and the six that follow.
     */
    public const UPCOMING_DAYS = 7;

    /**
     * Age brackets of the overdue, by days late: key => [from, to], to null for no upper limit.
     */
    public const AGING = [
        '1_7'     => [1, 7],
        '8_30'    => [8, 30],
        '31_plus' => [31, null],
    ];

    private const MONEY_SCALE = 2;

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

        $stock = $this->stock_of(array_keys($rows), $this->locations_of($campaign_id));

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
     * The portfolio of the OPEN presales on $today, optionally of one campaign and one delivery date.
     *
     * Delivered and canceled presales are left out: their money is already somewhere else, the sale or
     * the refund. A presale paid in full but not yet delivered counts in sold and collected, with
     * nothing left to collect.
     *
     * Two queries whatever the number of presales: the presales with what was paid (net of refunds,
     * Presale_payment::net_sql()) and their customer and campaign, and their instalments.
     *
     * rows:     every open presale of the filter, by id: presale_id, customer and campaign data,
     *           delivery_date, total, paid, balance, overdue, days_late, upcoming (what is missing of
     *           the instalments due in the window), state.
     * late:     the rows with something overdue, the most days late first.
     * upcoming: the rows with something due in the window, each with due_date (the first instalment
     *           due in it) and amount, by due date.
     *
     * Amounts are strings at scale 2, rounded to the business's currency decimals.
     *
     * @return array{totals: array{sold: string, collected: string, receivable: string, overdue: string}, aging: array<string, array{amount: string, count: int}>, upcoming: array{amount: string, rows: list<array<string, mixed>>}, late: list<array<string, mixed>>, rows: list<array<string, mixed>>}
     */
    public function portfolio(string $today, ?int $campaign_id = null, ?string $delivery_date = null): array
    {
        $presales = $this->open_presales($campaign_id, $delivery_date);
        $plans    = model(Presale::class)->installments_for(array_map('intval', array_column($presales, 'presale_id')));
        $until    = date('Y-m-d', (int) strtotime($today . ' +' . self::UPCOMING_DAYS . ' days'));
        $zero     = '0.00';

        $totals   = ['sold' => $zero, 'collected' => $zero, 'receivable' => $zero, 'overdue' => $zero];
        $aging    = array_map(static fn (): array => ['amount' => $zero, 'count' => 0], self::AGING);
        $upcoming = ['amount' => $zero, 'rows' => []];
        $late     = [];
        $rows     = [];

        foreach ($presales as $presale) {
            $id      = (int) $presale['presale_id'];
            $total   = bcadd((string) $presale['total'], '0', self::MONEY_SCALE);
            $paid    = bcadd((string) $presale['paid'], '0', self::MONEY_SCALE);
            $plan    = $plans[$id] ?? [];
            $derived = Presale::derive(Presale::STATUS_OPEN, $total, $paid, $plan, $today);

            $overdue = bcsub($derived['due_to_date'], $paid, self::MONEY_SCALE);
            $overdue = bccomp($overdue, '0', self::MONEY_SCALE) > 0 ? $overdue : $zero;

            // What is still missing of the instalments due from today to the end of the window, by the
            // same accumulation as derive()'s next instalment: a payment fills the oldest first.
            $due_soon  = $zero;
            $first_due = null;

            foreach (Presale::coverage($paid, $plan) as $installment) {
                $in_window = $installment['due_date'] >= $today && $installment['due_date'] < $until;

                if ($in_window && bccomp($installment['missing'], '0', self::MONEY_SCALE) > 0) {
                    $due_soon = bcadd($due_soon, $installment['missing'], self::MONEY_SCALE);
                    $first_due ??= $installment['due_date'];
                }
            }

            $row = [
                'presale_id'    => $id,
                'customer_id'   => (int) $presale['customer_id'],
                'first_name'    => (string) ($presale['first_name'] ?? ''),
                'last_name'     => (string) ($presale['last_name'] ?? ''),
                'phone_number'  => (string) ($presale['phone_number'] ?? ''),
                'email'         => (string) ($presale['email'] ?? ''),
                'campaign_id'   => (int) $presale['campaign_id'],
                'campaign_name' => (string) ($presale['campaign_name'] ?? ''),
                'delivery_date' => (string) $presale['delivery_date'],
                'total'         => Presale_campaign::round_money($total),
                'paid'          => Presale_campaign::round_money($paid),
                'balance'       => Presale_campaign::round_money($derived['balance']),
                'overdue'       => Presale_campaign::round_money($overdue),
                'days_late'     => $derived['days_late'],
                'upcoming'      => Presale_campaign::round_money($due_soon),
                'state'         => $derived['state'],
            ];

            $rows[] = $row;

            $totals['sold']      = bcadd($totals['sold'], $total, self::MONEY_SCALE);
            $totals['collected'] = bcadd($totals['collected'], $paid, self::MONEY_SCALE);
            $totals['overdue']   = bcadd($totals['overdue'], $overdue, self::MONEY_SCALE);

            if (bccomp($overdue, '0', self::MONEY_SCALE) > 0) {
                $late[] = $row;

                $bracket = self::bracket($derived['days_late']);

                if ($bracket !== null) {
                    $aging[$bracket]['amount'] = bcadd($aging[$bracket]['amount'], $overdue, self::MONEY_SCALE);
                    $aging[$bracket]['count']++;
                }
            }

            if ($first_due !== null) {
                $upcoming['amount'] = bcadd($upcoming['amount'], $due_soon, self::MONEY_SCALE);
                $upcoming['rows'][] = $row + ['due_date' => $first_due, 'amount' => $row['upcoming']];
            }
        }

        $totals['receivable'] = bcsub($totals['sold'], $totals['collected'], self::MONEY_SCALE);
        $totals               = array_map(static fn (string $amount): string => Presale_campaign::round_money($amount), $totals);
        $upcoming['amount']   = Presale_campaign::round_money($upcoming['amount']);

        foreach (array_keys($aging) as $key) {
            $aging[$key]['amount'] = Presale_campaign::round_money($aging[$key]['amount']);
        }

        usort($late, static fn (array $a, array $b): int => ($b['days_late'] <=> $a['days_late']) ?: ($a['presale_id'] <=> $b['presale_id']));
        usort($upcoming['rows'], static fn (array $a, array $b): int => strcmp($a['due_date'], $b['due_date']) ?: ($a['presale_id'] <=> $b['presale_id']));

        return ['totals' => $totals, 'aging' => $aging, 'upcoming' => $upcoming, 'late' => $late, 'rows' => $rows];
    }

    /**
     * What the brand owes back: the cashier's discounts marked reimbursable (venta-anticipada.md
     * §4.16), on the presales that are open or delivered -- a canceled presale gave no discount
     * (owner's decision of 2026-10-27). The discount in money is gross_total - total: what the customer
     * did not pay, taxes included, by the register's own rule.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array{count: int, gross: string, total: string, discount: string, delivered: string, open: string}}
     */
    public function brand_discounts(?int $campaign_id = null): array
    {
        $builder = $this->db->table('presales AS p')
            ->select('p.presale_id, p.status, p.created_at, p.delivered_at, p.discount_percent, p.gross_total, p.total, p.employee_id')
            ->select('people.first_name, people.last_name, c.name AS campaign_name')
            ->select('emp.first_name AS employee_first_name, emp.last_name AS employee_last_name')
            ->join('people', 'people.person_id = p.customer_id', 'left')
            ->join('people AS emp', 'emp.person_id = p.employee_id', 'left')
            ->join('presale_campaigns AS c', 'c.campaign_id = p.campaign_id', 'left')
            ->whereIn('p.status', [Presale::STATUS_OPEN, Presale::STATUS_DELIVERED])
            ->where('p.discount_reimbursable', 1)
            ->where('p.discount_percent >', 0)
            ->orderBy('p.created_at', 'asc')
            ->orderBy('p.presale_id', 'asc');

        if ($campaign_id !== null) {
            $builder->where('p.campaign_id', $campaign_id);
        }

        $totals = ['count' => 0, 'gross' => '0.00', 'total' => '0.00', 'discount' => '0.00', 'delivered' => '0.00', 'open' => '0.00'];
        $rows   = [];

        foreach ($builder->get()->getResultArray() as $row) {
            $gross           = bcadd((string) ($row['gross_total'] ?? $row['total']), '0', 2);
            $row['discount'] = bcsub($gross, (string) $row['total'], 2);
            $rows[]          = $row;

            $totals['count']++;
            $totals['gross']    = bcadd($totals['gross'], $gross, 2);
            $totals['total']    = bcadd($totals['total'], (string) $row['total'], 2);
            $totals['discount'] = bcadd($totals['discount'], $row['discount'], 2);
            $bucket             = $row['status'] === Presale::STATUS_DELIVERED ? 'delivered' : 'open';
            $totals[$bucket]    = bcadd($totals[$bucket], $row['discount'], 2);
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * The delivery dates of the open presales, ascending (Y-m-d): the portfolio's date filter.
     *
     * @return list<string>
     */
    public function open_delivery_dates(): array
    {
        return array_column(
            $this->db->table('presales')
                ->distinct()
                ->select('delivery_date')
                ->where('status', Presale::STATUS_OPEN)
                ->orderBy('delivery_date', 'asc')
                ->get()->getResultArray(),
            'delivery_date',
        );
    }

    /**
     * The age bracket (a key of AGING) of a number of days late, or null when it is not late.
     */
    public static function bracket(int $days_late): ?string
    {
        foreach (self::AGING as $key => [$from, $to]) {
            if ($days_late >= $from && ($to === null || $days_late <= $to)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The open presales of the filter, each with what was paid and its customer and campaign, by id.
     *
     * Raw SQL, bound, like Presales::search_sql(): paid is a correlated subquery the query builder
     * would try to prefix. LEFT JOINs, so a presale whose customer row went missing still counts in
     * the money.
     *
     * @return list<array<string, mixed>>
     */
    private function open_presales(?int $campaign_id, ?string $delivery_date): array
    {
        $presales  = $this->db->prefixTable('presales');
        $payments  = $this->db->prefixTable('presale_payments');
        $campaigns = $this->db->prefixTable('presale_campaigns');
        $people    = $this->db->prefixTable('people');
        $paid      = Presale_payment::net_sql('pp');

        $where = ['p.status = ?'];
        $binds = [Presale::STATUS_OPEN];

        if ($campaign_id !== null) {
            $where[] = 'p.campaign_id = ?';
            $binds[] = $campaign_id;
        }

        if ($delivery_date !== null) {
            $where[] = 'p.delivery_date = ?';
            $binds[] = $delivery_date;
        }

        $sql = "SELECT p.presale_id, p.campaign_id, p.customer_id, p.delivery_date, p.total,
                c.name AS campaign_name, pe.first_name, pe.last_name, pe.phone_number, pe.email,
                (SELECT COALESCE({$paid}, 0)
                    FROM {$payments} AS pp WHERE pp.presale_id = p.presale_id) AS paid
            FROM {$presales} AS p
            LEFT JOIN {$campaigns} AS c ON c.campaign_id = p.campaign_id
            LEFT JOIN {$people} AS pe ON pe.person_id = p.customer_id
            WHERE " . implode(' AND ', $where) . '
            ORDER BY p.presale_id ASC';

        return $this->db->query($sql, $binds)->getResultArray();
    }

    /**
     * @return list<int>
     */
    private function locations_of(int $campaign_id): array
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
    private function stock_of(array $item_ids, array $location_ids): array
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
