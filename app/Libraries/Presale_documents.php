<?php

namespace App\Libraries;

use App\Models\Customer;
use App\Models\Presale;
use App\Models\Presale_campaign;
use App\Models\Presale_payment;

/**
 * The figures of every presale document, worked out in one place for the two readers that print them:
 * the screens (Presales::getReceipt() and its siblings) and the customer's e-mail, whose PDF is the
 * same document (docs/Tecnico/preventas-cartera-y-avisos.md K4). Two copies of these sums would be two
 * documents that disagree about what the customer paid.
 *
 * Everything returned is data for the views in app/Views/presales/receipt_*.php, without the parts that
 * only a screen has (`print`, `open_drawer`) and without `config`, which the caller supplies: the web
 * gets it as shared view data, the command passes the business's own settings.
 *
 * Every method answers null when there is no such document; the caller decides what that means.
 */
class Presale_documents
{
    private Presale $presale;

    public function __construct()
    {
        $this->presale = model(Presale::class);
    }

    /**
     * The presale document: what was agreed, the plan, what has been paid and the conditions.
     *
     * @return array<string, mixed>|null
     */
    public function presale(int $presale_id, string $today): ?array
    {
        $summary = $this->presale->get_summary($presale_id, $today);

        if ($summary === null) {
            return null;
        }

        // On paper, only the lines the register would print: a kit whose components are set not to
        // print shows as the kit.
        $lines = array_values(array_filter(
            $this->presale->get_lines($presale_id),
            static fn (array $line): bool => (int) $line['print_option'] === PRINT_YES,
        ));

        return [
            'presale'       => $summary,
            'customer'      => model(Customer::class)->get_info((int) $summary['customer_id']),
            'campaign_name' => $this->campaign_name((int) $summary['campaign_id']),
            'lines'         => $lines,
            'installments'  => $this->presale->get_installments($presale_id),
        ];
    }

    /**
     * The receipt of one instalment, as of that payment: a reprint next week says what it said the
     * day it was taken.
     *
     * @return array<string, mixed>|null
     */
    public function payment(int $payment_id): ?array
    {
        $payment = model(Presale_payment::class)->find($payment_id);

        if (! is_array($payment) || $payment['kind'] !== Presale_payment::KIND_PAYMENT) {
            return null;
        }

        $presale_id = (int) $payment['presale_id'];
        $presale    = $this->presale->get_info($presale_id);

        if ($presale === null) {
            return null;
        }

        $accumulated = '0.00';

        foreach (model(Presale_payment::class)->get_for($presale_id) as $movement) {
            if ((int) $movement['payment_id'] > $payment_id) {
                continue;
            }

            $accumulated = $movement['kind'] === Presale_payment::KIND_REFUND
                ? bcsub($accumulated, (string) $movement['amount'], 2)
                : bcadd($accumulated, (string) $movement['amount'], 2);
        }

        return [
            'presale'       => $presale + ['number' => $this->presale->number($presale_id)],
            'payment'       => $payment,
            'customer'      => model(Customer::class)->get_info((int) $presale['customer_id']),
            'campaign_name' => $this->campaign_name((int) $presale['campaign_id']),
            'employee'      => $this->names([(int) $payment['employee_id']])[(int) $payment['employee_id']] ?? '',
            'accumulated'   => $accumulated,
            'balance'       => bcsub((string) $presale['total'], $accumulated, 2),
        ];
    }

    /**
     * The cancellation document: what had been paid, what was given back and how, what the business
     * keeps, the reason. Read from the stored movements.
     *
     * @return array<string, mixed>|null
     */
    public function cancellation(int $presale_id): ?array
    {
        $presale = $this->presale->get_info($presale_id);

        if ($presale === null || $presale['status'] !== Presale::STATUS_CANCELED) {
            return null;
        }

        $paid     = '0.00';
        $refunded = '0.00';
        $refunds  = [];

        foreach (model(Presale_payment::class)->get_for($presale_id) as $movement) {
            if ($movement['kind'] === Presale_payment::KIND_REFUND) {
                $refunded  = bcadd($refunded, (string) $movement['amount'], 2);
                $refunds[] = $movement;
            } else {
                $paid = bcadd($paid, (string) $movement['amount'], 2);
            }
        }

        $canceled_by = (int) ($presale['canceled_by'] ?? 0);

        return [
            'presale'       => $presale + ['number' => $this->presale->number($presale_id)],
            'customer'      => model(Customer::class)->get_info((int) $presale['customer_id']),
            'campaign_name' => $this->campaign_name((int) $presale['campaign_id']),
            'employee'      => $this->names([$canceled_by])[$canceled_by] ?? '',
            'paid'          => $paid,
            'refunded'      => $refunded,
            'kept'          => bcsub($paid, $refunded, 2),
            'refunds'       => $refunds,
        ];
    }

    /**
     * The delivery document: the products handed over by the register's sale, and the difference a
     * product sold by weight left -- charged when it weighed more, given back when it weighed less.
     *
     * The difference is the sale's total against what the presale had collected. The sale's total is
     * what its payments kept (payment_amount - cash_refund), which is how the register itself closes
     * a sale; recomputing it from the lines would mean re-implementing its taxes and rounding.
     *
     * @return array<string, mixed>|null
     */
    public function delivery(int $presale_id): ?array
    {
        $presale = $this->presale->get_info($presale_id);

        if ($presale === null || $presale['status'] !== Presale::STATUS_DELIVERED || $presale['sale_id'] === null) {
            return null;
        }

        $sale_id = (int) $presale['sale_id'];
        $db      = db_connect();

        $lines = $db->table('sales_items AS si')
            ->select('si.line, si.quantity_purchased, si.item_unit_price, si.discount, si.discount_type, si.print_option, items.name, items.unit_of_measure')
            ->join('items', 'items.item_id = si.item_id', 'left')
            ->where('si.sale_id', $sale_id)
            ->where('si.print_option', PRINT_YES)
            ->orderBy('si.line', 'asc')
            ->get()->getResultArray();

        $sale_total = '0.00';

        foreach ($db->table('sales_payments')->where('sale_id', $sale_id)->get()->getResultArray() as $payment) {
            $sale_total = bcadd($sale_total, bcsub((string) $payment['payment_amount'], (string) ($payment['cash_refund'] ?? '0'), 2), 2);
        }

        $paid       = model(Presale_payment::class)->get_paid($presale_id);
        $difference = bcsub($sale_total, $paid, 2);
        $delivered  = $presale['delivered_by'] === null ? 0 : (int) $presale['delivered_by'];

        return [
            'presale'       => $presale + ['number' => $this->presale->number($presale_id)],
            'customer'      => model(Customer::class)->get_info((int) $presale['customer_id']),
            'campaign_name' => $this->campaign_name((int) $presale['campaign_id']),
            'employee'      => $this->names([$delivered])[$delivered] ?? '',
            'lines'         => $lines,
            'paid'          => $paid,
            'sale_total'    => $sale_total,
            // Positive: charged on the day for a heavier weight. Negative: given back for a lighter one.
            'difference' => $difference,
        ];
    }

    /**
     * First and last name of each person, in one query.
     *
     * @param list<int> $person_ids
     *
     * @return array<int, string>
     */
    public function names(array $person_ids): array
    {
        $person_ids = array_values(array_unique(array_filter($person_ids)));

        if ($person_ids === []) {
            return [];
        }

        $names = [];

        foreach (db_connect()->table('people')->select('person_id, first_name, last_name')->whereIn('person_id', $person_ids)->get()->getResultArray() as $person) {
            $names[(int) $person['person_id']] = trim($person['first_name'] . ' ' . $person['last_name']);
        }

        return $names;
    }

    private function campaign_name(int $campaign_id): string
    {
        return (string) (model(Presale_campaign::class)->get_info($campaign_id)['name'] ?? '');
    }
}
