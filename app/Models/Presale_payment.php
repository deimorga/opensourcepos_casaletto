<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * The money a presale moved: instalments in, refunds out. Each row carries the shift that handled it.
 *
 * Writing happens in Presale, inside the transaction that also checks the balance. This model is the
 * read side the cash-up and the reports use, so they never need to know how a presale works.
 *
 * WHY BY SHIFT AND NOT BY DATE RANGE
 *
 * Same reason as Sale::get_payments_by_cashup(): on a day with two shifts, or with the date-only
 * setting that widens a range to whole days, a range takes in money the shift never saw.
 */
class Presale_payment extends Model
{
    public const KIND_PAYMENT = 'payment';
    public const KIND_REFUND  = 'refund';

    /**
     * The only ways a presale can be paid or refunded (T10). Due, gift cards, points and deposits make
     * no sense here, and the list is closed on the server.
     */
    public const PAYMENT_CODES = ['cash', 'debit', 'credit', 'bank_transfer'];

    protected $table            = 'presale_payments';
    protected $primaryKey       = 'payment_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes   = false;

    /**
     * Must match Migration_AddPresales::WRITABLE_COLUMNS_PAYMENTS; a test compares them.
     */
    protected $allowedFields = [
        'presale_id',
        'kind',
        'payment_type_code',
        'amount',
        'payment_time',
        'employee_id',
        'cashup_id',
        'reference_code',
    ];

    /**
     * The net of a set of movements as SQL: instalments add, refunds subtract. The one place this is
     * written; the presales list (Presales::search_sql()) uses it too.
     *
     * $column is the qualified prefix of the movement's columns -- the prefixed table name, or the
     * alias of a subquery.
     */
    public static function net_sql(string $column): string
    {
        return "SUM(CASE WHEN {$column}.kind = '" . self::KIND_REFUND . "' THEN -{$column}.amount ELSE {$column}.amount END)";
    }

    /**
     * The net a shift took for presales, per payment type: instalments minus refunds.
     *
     * Same shape as Sale::get_payments_by_cashup() -- payment_type_code, payment_type, trans_amount --
     * so the cash-up can show and add it the same way.
     *
     * @return list<array{payment_type_code: string, payment_type: string, trans_amount: string}>
     */
    public function get_by_cashup(int $cashup_id): array
    {
        $table = $this->db->prefixTable($this->table);

        $rows = $this->db->table($this->table)
            ->select('payment_type_code')
            ->select(self::net_sql($table) . ' AS trans_amount', false)
            ->where('cashup_id', $cashup_id)
            ->groupBy('payment_type_code')
            ->orderBy('payment_type_code', 'asc')
            ->get()->getResultArray();

        return $this->labelled($rows);
    }

    /**
     * The same net, by payment type, for every movement whose payment_time falls in [$start, $end].
     * For the reports and the closing-screen autocomplete, which work by date.
     *
     * @return list<array{payment_type_code: string, payment_type: string, trans_amount: string}>
     */
    public function get_net_between(string $start, string $end): array
    {
        $table = $this->db->prefixTable($this->table);

        $rows = $this->db->table($this->table)
            ->select('payment_type_code')
            ->select(self::net_sql($table) . ' AS trans_amount', false)
            ->where('payment_time >=', $start)
            ->where('payment_time <=', $end)
            ->groupBy('payment_type_code')
            ->orderBy('payment_type_code', 'asc')
            ->get()->getResultArray();

        return $this->labelled($rows);
    }

    /**
     * Every movement of one presale, oldest first.
     */
    public function get_for(int $presale_id): array
    {
        return $this->db->table($this->table)
            ->where('presale_id', $presale_id)
            ->orderBy('payment_time', 'asc')
            ->orderBy('payment_id', 'asc')
            ->get()->getResultArray();
    }

    /**
     * Instalments minus refunds of one presale, at scale 2.
     */
    public function get_paid(int $presale_id): string
    {
        $table = $this->db->prefixTable($this->table);

        $row = $this->db->table($this->table)
            ->select('COALESCE(' . self::net_sql($table) . ', 0) AS paid', false)
            ->where('presale_id', $presale_id)
            ->get()->getRowArray();

        return bcadd((string) ($row['paid'] ?? '0'), '0', 2);
    }

    /**
     * Adds the label of each payment type in the language that is active now. The code is the
     * contract; the label is only for the screen.
     */
    private function labelled(array $rows): array
    {
        helper('payment_type');

        foreach ($rows as &$row) {
            $row['payment_type'] = payment_type_label($row['payment_type_code']);
            $row['trans_amount'] = bcadd((string) $row['trans_amount'], '0', 2);
        }

        return $rows;
    }
}
