<?php

namespace App\Models\Reports;

use App\Models\Reports\Summary_sales;
use Config\OSPOS;

/**
 * Income against operating expenses over the same period.
 *
 * Extends Report rather than Summary_report on purpose: Summary_report hardwires getData() and
 * getSummaryData() to the sales_items table, which is why Summary_expenses_categories extends it and
 * then overrides both methods whole. Report is the abstract class actually meant for this.
 *
 * The income side never reimplements the sale total. That figure is the expression built in
 * Summary_report::__common_select() -- percentage and value discounts, two temporary tables, the
 * cash adjustment, and a different formula depending on tax_included. Rewriting it would guarantee
 * that one day this screen and the transactions summary stop agreeing. Instead this model asks
 * Summary_sales for its daily rows and groups them here.
 *
 * Both sides are grouped in PHP by the same periodKey(), rather than one in SQL and the other in
 * PHP. That is deliberate: MySQL's YEARWEEK and PHP's ISO week would have to agree exactly, and a
 * silent disagreement between two ways of naming the same week is precisely the class of bug this
 * codebase has been paying for. One function decides, so they cannot drift.
 *
 * See docs/Tecnico/reportes-analiticos-ingresos-gastos.md.
 */
class Income_expenses extends Report
{
    public const GRANULARITIES = ['day', 'week', 'month'];

    /**
     * @return array[]
     */
    public function getDataColumns(): array
    {
        return [
            ['period'   => lang('Reports.period'), 'sortable' => false],
            ['income'   => lang('Reports.income'), 'sorter' => 'number_sorter'],
            ['expenses' => lang('Reports.expenses'), 'sorter' => 'number_sorter'],
            ['result'   => lang('Reports.result'), 'sorter' => 'number_sorter'],
            ['margin'   => lang('Reports.margin'), 'sorter' => 'number_sorter']
        ];
    }

    /**
     * One row per period, oldest first, with both sides and the result.
     *
     * A period with sales and no expenses -- or the other way round -- still appears, with a zero on
     * the missing side. Joining the two sides would hide exactly the months worth looking at.
     *
     * @param array $inputs
     * @return array
     */
    public function getData(array $inputs): array
    {
        $granularity = $this->granularity($inputs);
        $income      = $this->incomeByPeriod($inputs, $granularity);
        $expenses    = $this->expensesByPeriod($inputs, $granularity);

        $periods = array_unique(array_merge(array_keys($income), array_keys($expenses)));
        sort($periods);

        $rows = [];
        foreach ($periods as $period) {
            $income_amount   = $income[$period] ?? 0.0;
            $expenses_amount = $expenses[$period] ?? 0.0;

            $rows[] = [
                'period_key' => $period,
                'period'     => $this->periodLabel($period, $granularity),
                'income'     => $income_amount,
                'expenses'   => $expenses_amount,
                'result'     => $income_amount - $expenses_amount,
                // Null, never zero, when there is no income. A period with no sales must read as
                // "—" and not as "0%", which would say "we broke even" about a period that had no
                // sales at all.
                'margin'     => $income_amount == 0.0 ? null : (($income_amount - $expenses_amount) / $income_amount) * 100
            ];
        }

        return $rows;
    }

    /**
     * @param array $inputs
     * @return array
     */
    public function getSummaryData(array $inputs): array
    {
        $income   = 0.0;
        $expenses = 0.0;

        foreach ($this->getData($inputs) as $row) {
            $income   += $row['income'];
            $expenses += $row['expenses'];
        }

        return [
            'total_income'   => $income,
            'total_expenses' => $expenses,
            'total_result'   => $income - $expenses,
            'total_margin'   => $income == 0.0 ? null : (($income - $expenses) / $income) * 100
        ];
    }

    /**
     * Whether the report is answering "what did we invoice" or "what did we actually collect".
     *
     * Selecting a payment method switches the income side from invoiced totals to payments received,
     * because an unpaid credit sale has no payment attached and would silently vanish from a
     * filtered report. The view announces the switch; see section 6 of the technical document.
     */
    public function isCashMode(array $inputs): bool
    {
        return !empty($inputs['payment_codes']);
    }

    /**
     * @return string one of GRANULARITIES
     */
    private function granularity(array $inputs): string
    {
        // Whitelisted before it can reach a GROUP BY or a date format string.
        return in_array($inputs['granularity'] ?? '', self::GRANULARITIES, true)
            ? $inputs['granularity']
            : 'month';
    }

    /**
     * Income per period, keyed by period.
     *
     * @return array<string, float>
     */
    private function incomeByPeriod(array $inputs, string $granularity): array
    {
        return $this->isCashMode($inputs)
            ? $this->paymentsByPeriod($inputs, $granularity)
            : $this->invoicedByPeriod($inputs, $granularity);
    }

    /**
     * Accrual mode: the invoiced total, taken straight from Summary_sales so the two reports cannot
     * disagree. Summary_sales returns one row per operating day, so grouping happens here.
     *
     * @return array<string, float>
     */
    private function invoicedByPeriod(array $inputs, string $granularity): array
    {
        $summary_sales = model(Summary_sales::class);

        $rows = $summary_sales->getData([
            'start_date'  => $inputs['start_date'],
            'end_date'    => $inputs['end_date'],
            'sale_type'   => 'complete',
            'location_id' => 'all'
        ]);

        $totals = [];
        foreach ($rows as $row) {
            $key = $this->periodKey((string) $row['sale_date'], $granularity);
            $totals[$key] = ($totals[$key] ?? 0.0) + (float) $row['total'];
        }

        return $totals;
    }

    /**
     * Cash mode: money actually received, net of refunds, for the selected payment methods.
     *
     * cash_refund is subtracted the way Summary_payments does it: a refund handed back over the
     * counter is money that left the drawer and cannot count as income.
     *
     * @return array<string, float>
     */
    private function paymentsByPeriod(array $inputs, string $granularity): array
    {
        $builder = $this->db->table('sales_payments AS payments');
        $builder->select('sales.sale_time AS period_date, payments.payment_amount, payments.cash_refund');
        $builder->join('sales AS sales', 'sales.sale_id = payments.sale_id', 'inner');
        $builder->where('sales.sale_status', COMPLETED);
        $builder->whereIn('payments.payment_type_code', $inputs['payment_codes']);
        $this->applyDateRange($builder, 'sales.sale_time', $inputs);

        $totals = [];
        foreach ($builder->get()->getResultArray() as $row) {
            $key = $this->periodKey((string) $row['period_date'], $granularity);
            $totals[$key] = ($totals[$key] ?? 0.0) + ((float) $row['payment_amount'] - (float) $row['cash_refund']);
        }

        return $totals;
    }

    /**
     * @return array<string, float>
     */
    private function expensesByPeriod(array $inputs, string $granularity): array
    {
        $builder = $this->db->table('expenses AS expenses');
        $builder->select('expenses.date AS period_date, expenses.amount');
        $this->applyDateRange($builder, 'expenses.date', $inputs);

        if (empty($inputs['include_deleted'])) {
            $builder->where('expenses.deleted', 0);
        }

        if ($this->isCashMode($inputs)) {
            $builder->whereIn('expenses.payment_type_code', $inputs['payment_codes']);
        }

        $totals = [];
        foreach ($builder->get()->getResultArray() as $row) {
            $key = $this->periodKey((string) $row['period_date'], $granularity);
            $totals[$key] = ($totals[$key] ?? 0.0) + (float) $row['amount'];
        }

        return $totals;
    }

    /**
     * The same date-range branching every report model uses: whether the configured format carries a
     * time component decides if the comparison is on the date alone or the full timestamp.
     */
    private function applyDateRange(object $builder, string $column, array $inputs): void
    {
        $config = config(OSPOS::class)->settings;

        if (empty($config['date_or_time_format'])) {
            $builder->where("DATE($column) BETWEEN " . $this->db->escape($inputs['start_date']) . ' AND ' . $this->db->escape($inputs['end_date']));
        } else {
            $builder->where("$column BETWEEN " . $this->db->escape(rawurldecode($inputs['start_date'])) . ' AND ' . $this->db->escape(rawurldecode($inputs['end_date'])));
        }
    }

    /**
     * Sortable key for the period a date falls in. Weeks use the ISO year and week so they line up
     * with the calendar the business reads, starting on Monday.
     */
    private function periodKey(string $date, string $granularity): string
    {
        $timestamp = strtotime($date);

        return match ($granularity) {
            'day'   => date('Y-m-d', $timestamp),
            'week'  => date('o-\WW', $timestamp),
            default => date('Y-m', $timestamp)
        };
    }

    /**
     * What the user reads in the first column.
     */
    private function periodLabel(string $key, string $granularity): string
    {
        if ($granularity === 'day') {
            return to_date(strtotime($key));
        }

        if ($granularity === 'week') {
            [$year, $week] = explode('-W', $key);
            $monday = new \DateTime();
            $monday->setISODate((int) $year, (int) $week);
            $sunday = (clone $monday)->modify('+6 days');

            return to_date($monday->getTimestamp()) . ' – ' . to_date($sunday->getTimestamp());
        }

        [$year, $month] = explode('-', $key);
        $months = ['january', 'february', 'march', 'april', 'may', 'june',
                   'july', 'august', 'september', 'october', 'november', 'december'];

        return lang('Calendar.' . $months[(int) $month - 1]) . ' ' . $year;
    }
}
