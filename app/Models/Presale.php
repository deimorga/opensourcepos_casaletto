<?php

namespace App\Models;

use App\Libraries\Presale_register;
use CodeIgniter\Model;
use Config\OSPOS;
use DateTime;
use Throwable;

/**
 * A presale: an agreement with a registered customer, inside a campaign, to deliver some products on
 * one of the campaign's dates once they are fully paid (docs/Funcional/venta-anticipada.md).
 *
 * WHAT IS STORED AND WHAT IS DERIVED
 *
 * Stored: open | delivered | canceled. Derived on every read, from the instalments and the payments:
 * up to date, late, paid. A derived state that is stored goes stale the moment somebody pays (T6).
 *
 * EVERY RULE IS HERE, ON THE SERVER
 *
 * The controller hands over what the cashier typed and gets back an id or a language key. Prices are
 * never taken from the form: they come from the campaign (T8, T14). The register's Due payment is the
 * standing example of what happens otherwise -- its "a customer is required" lives only in the view.
 *
 * WHAT THIS MODEL DOES NOT DO
 *
 * It never writes to `sales`, `sales_payments` or the stock. A presale reaches those only when it is
 * delivered, through the register (D17), and mark_delivered() is the single point where the two meet.
 *
 * See docs/Tecnico/venta-anticipada.md sections 3, 4 and 7.
 */
class Presale extends Model
{
    public const STATUS_OPEN      = 'open';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELED  = 'canceled';

    /**
     * The derived states of an open presale, plus the two stored final ones.
     */
    public const STATE_UP_TO_DATE = 'up_to_date';

    public const STATE_LATE   = 'late';
    public const STATE_PAID   = 'paid';
    private const MONEY_SCALE = 2;

    protected $table            = 'presales';
    protected $primaryKey       = 'presale_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes   = false;

    /**
     * Must match Migration_AddPresales::WRITABLE_COLUMNS_PRESALES; a test compares them.
     */
    protected $allowedFields = [
        'campaign_id',
        'customer_id',
        'employee_id',
        'location_id',
        'created_at',
        'delivery_date_id',
        'delivery_date',
        'status',
        'total',
        'comment',
        'sale_id',
        'delivered_at',
        'delivered_by',
        'canceled_at',
        'canceled_by',
        'cancel_reason',
    ];

    // ---------------------------------------------------------------------------------------------
    // Registering
    // ---------------------------------------------------------------------------------------------

    /**
     * Registers a presale and takes its first instalment, all or nothing.
     *
     * Returns the new presale_id, or a language key saying why it was refused. Nothing is written when
     * it is refused.
     *
     * $input:
     *   campaign_id, customer_id, delivery_date_id, location_id, comment
     *   lines:        list of [item_id, quantity]        -- prices come from the campaign
     *   installments: list of [due_date (Y-m-d), amount] -- the plan agreed with the customer
     *   payment:      [payment_type_code, amount, reference_code?] -- the first instalment, taken now
     *
     * @param array<string, mixed> $input
     */
    public function create(array $input, int $employee_id, string $today): int|string
    {
        $campaigns = model(Presale_campaign::class);

        $campaign = $campaigns->get_info((int) ($input['campaign_id'] ?? 0));

        if ($campaign === null) {
            return 'Presales.campaign_not_found';
        }

        if (! Presale_campaign::is_selling($campaign, $today)) {
            return 'Presales.campaign_not_selling';
        }

        $customer_id = (int) ($input['customer_id'] ?? 0);

        if (! $this->customer_exists($customer_id)) {
            return 'Presales.customer_required';
        }

        $date = $campaigns->get_date((int) $campaign['campaign_id'], (int) ($input['delivery_date_id'] ?? 0));

        if ($date === null) {
            return 'Presales.date_not_in_campaign';
        }

        $lines = $this->price_lines((int) $campaign['campaign_id'], $input['lines'] ?? []);

        if (is_string($lines)) {
            return $lines;
        }

        // The total is what the register will charge at delivery for these lines and this customer --
        // taxes and rounding included, by the register's own rule (owner's decision of 2026-10-07).
        // Not the sum of the lines' amounts: the register does not round line by line.
        $charge = $this->register_charge($lines, $customer_id);

        if ($charge === null) {
            return 'Presales.total_unavailable';
        }

        $total = $charge['charge'];

        if (bccomp($total, '0', self::MONEY_SCALE) <= 0) {
            return 'Presales.total_must_be_positive';
        }

        $installments = $this->check_plan($input['installments'] ?? [], $total, $date['delivery_date'], $campaign);

        if (is_string($installments)) {
            return $installments;
        }

        $payment = $input['payment'] ?? [];
        $amount  = self::money_or_null($payment['amount'] ?? null);
        $minimum = self::minimum_initial($total, (string) $campaign['min_initial_percent']);

        if ($amount === null || bccomp($amount, '0', self::MONEY_SCALE) <= 0) {
            return 'Presales.initial_payment_required';
        }

        if (bccomp($amount, $minimum, self::MONEY_SCALE) < 0) {
            return 'Presales.initial_below_minimum';
        }

        if (bccomp($amount, $total, self::MONEY_SCALE) > 0) {
            return 'Presales.payment_exceeds_balance';
        }

        $code = (string) ($payment['payment_type_code'] ?? '');

        if (! in_array($code, Presale_payment::PAYMENT_CODES, true)) {
            return 'Presales.payment_type_invalid';
        }

        $cashup_id = model(Cashup::class)->get_open_cashup_id();

        if ($cashup_id === null) {
            return 'Presales.no_open_cashup';
        }

        $now = date('Y-m-d H:i:s');

        $this->db->transBegin();

        $this->db->table($this->table)->insert([
            'campaign_id'      => (int) $campaign['campaign_id'],
            'customer_id'      => $customer_id,
            'employee_id'      => $employee_id,
            'location_id'      => (int) ($input['location_id'] ?? 0),
            'created_at'       => $now,
            'delivery_date_id' => (int) $date['date_id'],
            'delivery_date'    => $date['delivery_date'],
            'status'           => self::STATUS_OPEN,
            'total'            => $total,
            'comment'          => trim((string) ($input['comment'] ?? '')) ?: null,
        ]);

        $presale_id = (int) $this->db->insertID();

        foreach ($lines as $number => $line) {
            $this->db->table('presale_items')->insert([
                'presale_id'    => $presale_id,
                'line'          => $number + 1,
                'item_id'       => $line['item_id'],
                'description'   => null,
                'quantity'      => $line['quantity'],
                'unit_price'    => $line['unit_price'],
                'discount'      => 0,
                'discount_type' => 0,
                'print_option'  => $line['print_option'],
                'item_type'     => $line['item_type'],
            ]);
        }

        foreach ($installments as $installment) {
            $this->db->table('presale_installments')->insert([
                'presale_id' => $presale_id,
                'due_date'   => $installment['due_date'],
                'amount'     => $installment['amount'],
            ]);
        }

        $this->db->table('presale_payments')->insert([
            'presale_id'        => $presale_id,
            'kind'              => Presale_payment::KIND_PAYMENT,
            'payment_type_code' => $code,
            'amount'            => $amount,
            'payment_time'      => $now,
            'employee_id'       => $employee_id,
            'cashup_id'         => $cashup_id,
            'reference_code'    => self::reference($payment['reference_code'] ?? null),
        ]);

        $events = model(Presale_event::class);
        $events->log($presale_id, Presale_event::CREATED, $employee_id, ['total' => $total, 'taxes' => $charge['taxes'], 'lines' => count($lines), 'installments' => count($installments)]);
        $events->log($presale_id, Presale_event::PAYMENT, $employee_id, ['amount' => $amount, 'payment_type_code' => $code, 'cashup_id' => $cashup_id]);

        if ($this->db->transStatus() === false) {
            $this->db->transRollback();

            return 'Presales.save_failed';
        }

        $this->db->transCommit();

        return $presale_id;
    }

    /**
     * What the register will charge for priced lines sold to $customer_id (Presale_register::charge_for()),
     * or null when it cannot be worked out -- then nothing is registered.
     *
     * @param list<array<string, mixed>> $lines as price_lines() returns them
     *
     * @return array{total: string, taxes: string, charge: string}|null
     */
    public function register_charge(array $lines, int $customer_id): ?array
    {
        try {
            return (new Presale_register())->charge_for($lines, $customer_id);
        } catch (Throwable $e) {
            log_message('critical', 'Presale::register_charge: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Prices each requested line from the campaign and refuses anything the campaign does not sell.
     *
     * A kit is expanded into its components the way the register expands it (Sale_lib::add_item_kit(),
     * Sales::postAdd()), so the delivery can rebuild the cart line by line and the stock that leaves is
     * the components' (technical doc §8.6). Storing a kit as one plain line would deliver the wrong
     * stock.
     *
     * - The kit's own line comes first and carries the CAMPAIGN price, which is the price of the WHOLE
     *   kit (owner's decision of 2026-10-07).
     * - Each component follows, its quantity multiplied, at 0 whatever the kit's price_option says:
     *   the kit's price already pays for it. That is the deliberate difference with the register,
     *   which prices components by price_option (Sale_lib::add_item() in PRICE_MODE_KIT).
     * - A kit inside a kit is expanded in place and its own line is not added, as in the register.
     * - Every line carries the print_option the register would give a line at its price (a component
     *   at 0 is not "priced"), and its item_type.
     * - The kit's own discount (item_kits.kit_discount) is not applied: discounts in a presale are the
     *   campaign's (D19).
     *
     * Each line's `amount` is for display: the presale's total is NOT their sum (see create()).
     *
     * @return list<array{item_id: int, quantity: string, unit_price: string, amount: string, print_option: int, item_type: int}>|string
     */
    public function price_lines(int $campaign_id, array $requested): array|string
    {
        if ($requested === []) {
            return 'Presales.lines_required';
        }

        $campaigns = model(Presale_campaign::class);
        $lines     = [];

        foreach ($requested as $request) {
            $item_id  = (int) ($request['item_id'] ?? 0);
            $quantity = trim((string) ($request['quantity'] ?? ''));

            if (! Presale_campaign::is_decimal($quantity) || bccomp($quantity, '0', 3) <= 0) {
                return 'Presales.quantity_invalid';
            }

            $item = $campaigns->get_item($campaign_id, $item_id);

            if ($item === null || (int) $item['item_deleted'] === 1) {
                return 'Presales.item_not_in_campaign';
            }

            $quantity = bcadd($quantity, '0', 3);

            // A product that is not sold by weight is sold in whole units.
            if (! Item::unit_of_measure_is_weight($item['unit_of_measure'] ?? null) && bccomp($quantity, bcadd($quantity, '0', 0), 3) !== 0) {
                return 'Presales.quantity_must_be_whole';
            }

            if ((int) $item['item_type'] === ITEM_KIT) {
                $kit_lines = $this->kit_lines($item_id, $quantity, $item['effective_price']);

                if (is_string($kit_lines)) {
                    return $kit_lines;
                }

                array_push($lines, ...$kit_lines);

                continue;
            }

            $lines[] = self::line($item_id, $quantity, $item['effective_price'], PRINT_YES, ITEM);
        }

        return $lines;
    }

    /**
     * The lines of one kit: its own line at the campaign price, then its components.
     *
     * @return list<array{item_id: int, quantity: string, unit_price: string, amount: string, print_option: int, item_type: int}>|string
     */
    private function kit_lines(int $kit_item_id, string $quantity, string $campaign_price): array|string
    {
        $kit = $this->db->table('item_kits')
            ->select('item_kit_id, print_option')
            ->where('item_id', $kit_item_id)
            ->get()->getRowArray();

        if ($kit === null) {
            return 'Presales.kit_not_found';
        }

        $item_kit_id  = (int) $kit['item_kit_id'];
        $print_option = (int) $kit['print_option'];

        $components = $this->kit_components($item_kit_id, $quantity, $print_option, [$item_kit_id]);

        if (is_string($components)) {
            return $components;
        }

        $own = self::line($kit_item_id, $quantity, $campaign_price, self::kit_print_option($print_option, ITEM_KIT, $campaign_price), ITEM_KIT);

        return [$own, ...$components];
    }

    /**
     * The components of a kit, recursively, as Sale_lib::add_item_kit() adds them, every one at 0 (the
     * kit's campaign price pays for them): a nested kit passes on the OUTER kit's print option, exactly
     * as the register does, and a kit already being expanded higher up the chain is refused instead of
     * looping.
     *
     * @param list<int> $ancestors item_kit_ids being expanded in this chain
     *
     * @return list<array{item_id: int, quantity: string, unit_price: string, amount: string, print_option: int, item_type: int}>|string
     */
    private function kit_components(int $item_kit_id, string $multiplier, int $print_option, array $ancestors): array|string
    {
        $components = $this->db->table('item_kit_items AS kit_items')
            ->select('kit_items.item_id, kit_items.quantity, items.unit_price, items.item_type, items.deleted')
            ->join('items', 'items.item_id = kit_items.item_id', 'left')
            ->where('kit_items.item_kit_id', $item_kit_id)
            ->orderBy('kit_items.kit_sequence', 'asc')
            ->get()->getResultArray();

        if ($components === []) {
            return 'Presales.kit_not_found';
        }

        $lines = [];

        foreach ($components as $component) {
            if ($component['unit_price'] === null || (int) $component['deleted'] === 1) {
                return 'Presales.kit_component_missing';
            }

            $quantity  = bcmul((string) $component['quantity'], $multiplier, 3);
            $item_type = (int) $component['item_type'];

            if ($item_type === ITEM_KIT) {
                $nested = $this->db->table('item_kits')->select('item_kit_id')->where('item_id', (int) $component['item_id'])->get()->getRowArray();

                if ($nested !== null) {
                    $nested_id = (int) $nested['item_kit_id'];

                    if (in_array($nested_id, $ancestors, true)) {
                        return 'Presales.kit_not_found';
                    }

                    $nested_lines = $this->kit_components($nested_id, $quantity, $print_option, [...$ancestors, $nested_id]);

                    if (is_string($nested_lines)) {
                        return $nested_lines;
                    }

                    array_push($lines, ...$nested_lines);

                    continue;
                }
            }

            $price = '0.00';

            $lines[] = self::line((int) $component['item_id'], $quantity, $price, self::kit_print_option($print_option, $item_type, $price), $item_type);
        }

        return $lines;
    }

    /**
     * Whether a kit line is printed, by the register's rule (Sale_lib::add_item() in PRICE_MODE_KIT).
     */
    private static function kit_print_option(int $kit_print_option, int $item_type, string $price): int
    {
        $printed = $kit_print_option === PRINT_ALL
            || ($kit_print_option === PRINT_KIT && $item_type === ITEM_KIT)
            || ($kit_print_option === PRINT_PRICED && bccomp($price, '0', self::MONEY_SCALE) > 0);

        return $printed ? PRINT_YES : PRINT_NO;
    }

    /**
     * One priced line, ready to store.
     *
     * @return array{item_id: int, quantity: string, unit_price: string, amount: string, print_option: int, item_type: int}
     */
    private static function line(int $item_id, string $quantity, string $unit_price, int $print_option, int $item_type): array
    {
        return [
            'item_id'      => $item_id,
            'quantity'     => $quantity,
            'unit_price'   => $unit_price,
            'amount'       => Presale_campaign::round_money(bcmul($quantity, $unit_price, 6)),
            'print_option' => $print_option,
            'item_type'    => $item_type,
        ];
    }

    /**
     * Checks the plan agreed with the customer (§4.10 of the technical doc, D5, D21) and returns it
     * sorted by date, or a language key.
     *
     * - at least one instalment, every date valid and every amount positive;
     * - the amounts add up exactly to the total;
     * - none falls after the delivery date;
     * - the first one is not below the campaign's minimum.
     *
     * @return list<array{due_date: string, amount: string}>|string
     */
    private function check_plan(array $requested, string $total, string $delivery_date, array $campaign): array|string
    {
        if ($requested === []) {
            return 'Presales.installments_required';
        }

        $plan = [];
        $sum  = '0.00';

        foreach ($requested as $request) {
            $due_date = (string) ($request['due_date'] ?? '');
            $amount   = self::money_or_null($request['amount'] ?? null);

            if (! Presale_campaign::is_date($due_date)) {
                return 'Presales.date_invalid';
            }

            if ($amount === null || bccomp($amount, '0', self::MONEY_SCALE) <= 0) {
                return 'Presales.installment_amount_invalid';
            }

            if ($due_date > $delivery_date) {
                return 'Presales.installment_after_delivery';
            }

            $plan[] = ['due_date' => $due_date, 'amount' => $amount];
            $sum    = bcadd($sum, $amount, self::MONEY_SCALE);
        }

        if (bccomp($sum, $total, self::MONEY_SCALE) !== 0) {
            return 'Presales.installments_must_add_up';
        }

        usort($plan, static fn (array $a, array $b): int => strcmp($a['due_date'], $b['due_date']));

        if (bccomp($plan[0]['amount'], self::minimum_initial($total, (string) $campaign['min_initial_percent']), self::MONEY_SCALE) < 0) {
            return 'Presales.initial_below_minimum';
        }

        return $plan;
    }

    /**
     * The least first instalment: the campaign's percentage of the total, rounded UP to the business's
     * currency decimals so a minimum of 30% is never satisfied by 29.99%.
     */
    public static function minimum_initial(string $total, string $percent): string
    {
        if (bccomp($percent, '0', 2) <= 0) {
            return '0.00';
        }

        helper('locale');

        $decimals = min(self::MONEY_SCALE, max(0, totals_decimals()));
        $exact    = bcdiv(bcmul($total, $percent, 6), '100', 6);
        $floor    = bcadd($exact, '0', $decimals);

        if (bccomp($floor, $exact, 6) < 0) {
            $floor = bcadd($floor, $decimals === 0 ? '1' : '0.' . str_repeat('0', $decimals - 1) . '1', $decimals);
        }

        return bcadd($floor, '0', self::MONEY_SCALE);
    }

    // ---------------------------------------------------------------------------------------------
    // Money after registration
    // ---------------------------------------------------------------------------------------------

    /**
     * Takes an instalment. Returns the payment_id, or a language key.
     *
     * Checked inside a transaction with the presale row locked (T7): two tills taking money for the
     * same presale at once must not, between them, push the balance below zero. The campaign's selling
     * window does not apply (D16).
     */
    public function add_payment(int $presale_id, string $payment_type_code, mixed $amount, int $employee_id, ?string $reference_code = null): int|string
    {
        $amount = self::money_or_null($amount);

        if ($amount === null || bccomp($amount, '0', self::MONEY_SCALE) <= 0) {
            return 'Presales.payment_amount_invalid';
        }

        if (! in_array($payment_type_code, Presale_payment::PAYMENT_CODES, true)) {
            return 'Presales.payment_type_invalid';
        }

        $cashup_id = model(Cashup::class)->get_open_cashup_id();

        if ($cashup_id === null) {
            return 'Presales.no_open_cashup';
        }

        $this->db->transBegin();

        $presale = $this->lock($presale_id);

        if ($presale === null || $presale['status'] !== self::STATUS_OPEN) {
            $this->db->transRollback();

            return 'Presales.not_open';
        }

        $balance = bcsub((string) $presale['total'], model(Presale_payment::class)->get_paid($presale_id), self::MONEY_SCALE);

        if (bccomp($amount, $balance, self::MONEY_SCALE) > 0) {
            $this->db->transRollback();

            return 'Presales.payment_exceeds_balance';
        }

        $this->db->table('presale_payments')->insert([
            'presale_id'        => $presale_id,
            'kind'              => Presale_payment::KIND_PAYMENT,
            'payment_type_code' => $payment_type_code,
            'amount'            => $amount,
            'payment_time'      => date('Y-m-d H:i:s'),
            'employee_id'       => $employee_id,
            'cashup_id'         => $cashup_id,
            'reference_code'    => self::reference($reference_code),
        ]);

        $payment_id = (int) $this->db->insertID();

        model(Presale_event::class)->log($presale_id, Presale_event::PAYMENT, $employee_id, ['amount' => $amount, 'payment_type_code' => $payment_type_code, 'cashup_id' => $cashup_id]);

        if ($this->db->transStatus() === false) {
            $this->db->transRollback();

            return 'Presales.save_failed';
        }

        $this->db->transCommit();

        return $payment_id;
    }

    /**
     * Cancels an open presale and records whatever was agreed with the customer (D10): a refund of
     * anything from zero to everything paid, in one payment type, out of the shift that is open.
     * The rest is kept by the business. Nothing goes back to stock: nothing ever left it.
     *
     * Refused while the presale's delivery is open in the register (a tab linked by sale_id): the
     * cashier takes it back to presales from the register first.
     */
    public function cancel(int $presale_id, int $employee_id, string $reason, mixed $refund_amount = '0', ?string $refund_type_code = null, ?string $reference_code = null): bool|string
    {
        $reason = trim($reason);

        if ($reason === '') {
            return 'Presales.cancel_reason_required';
        }

        $refund = self::money_or_null($refund_amount === '' || $refund_amount === null ? '0' : $refund_amount);

        if ($refund === null || bccomp($refund, '0', self::MONEY_SCALE) < 0) {
            return 'Presales.refund_invalid';
        }

        $cashup_id = null;

        if (bccomp($refund, '0', self::MONEY_SCALE) > 0) {
            if (! in_array((string) $refund_type_code, Presale_payment::PAYMENT_CODES, true)) {
                return 'Presales.payment_type_invalid';
            }

            $cashup_id = model(Cashup::class)->get_open_cashup_id();

            if ($cashup_id === null) {
                return 'Presales.no_open_cashup';
            }
        }

        $this->db->transBegin();

        $presale = $this->lock($presale_id);

        if ($presale === null || $presale['status'] !== self::STATUS_OPEN) {
            $this->db->transRollback();

            return 'Presales.not_open';
        }

        // A delivery tab open in the register (D17) would be left behind with its presale payment
        // and could still be charged. Checked here, after the lock, so no path can skip it: the
        // register's attach_delivery_sale() writes this same row and waits for the lock.
        $stale_link = false;

        if ($presale['sale_id'] !== null) {
            if ($this->delivery_tab_is_open((int) $presale['sale_id'])) {
                $this->db->transRollback();

                return 'Presales.cancel_open_in_register';
            }

            $stale_link = true;
        }

        $paid = model(Presale_payment::class)->get_paid($presale_id);

        if (bccomp($refund, $paid, self::MONEY_SCALE) > 0) {
            $this->db->transRollback();

            return 'Presales.refund_exceeds_paid';
        }

        $now = date('Y-m-d H:i:s');

        if ($cashup_id !== null) {
            $this->db->table('presale_payments')->insert([
                'presale_id'        => $presale_id,
                'kind'              => Presale_payment::KIND_REFUND,
                'payment_type_code' => $refund_type_code,
                'amount'            => $refund,
                'payment_time'      => $now,
                'employee_id'       => $employee_id,
                'cashup_id'         => $cashup_id,
                'reference_code'    => self::reference($reference_code),
            ]);
        }

        $changes = [
            'status'        => self::STATUS_CANCELED,
            'canceled_at'   => $now,
            'canceled_by'   => $employee_id,
            'cancel_reason' => $reason,
        ];

        // A link to a tab that no longer exists (its sale was deleted or is no longer open) is
        // dropped: a canceled presale points at no sale.
        if ($stale_link) {
            $changes['sale_id'] = null;
        }

        $this->db->table($this->table)->where('presale_id', $presale_id)->update($changes);

        model(Presale_event::class)->log($presale_id, Presale_event::CANCELED, $employee_id, [
            'paid'                     => $paid,
            'refunded'                 => $refund,
            'kept'                     => bcsub($paid, $refund, self::MONEY_SCALE),
            'refund_payment_type_code' => $cashup_id === null ? null : $refund_type_code,
        ], $reason);

        if ($this->db->transStatus() === false) {
            $this->db->transRollback();

            return 'Presales.save_failed';
        }

        $this->db->transCommit();

        return true;
    }

    /**
     * Links an open presale to the register tab that delivers it (D17). Guarded so it only ever
     * writes an open presale, and only over no link or over the stale link the caller saw: two tills
     * pressing "Entregar" at once produce one tab and one refusal. sale_id is UNIQUE besides.
     *
     * Opens no transaction of its own: the register wraps creating the tab and this call in one. The
     * UPDATE waits for the row lock cancel() takes, so a presale canceled meanwhile is refused here.
     */
    public function attach_delivery_sale(int $presale_id, int $sale_id, ?int $stale_sale_id): bool
    {
        $builder = $this->db->table($this->table)
            ->where('presale_id', $presale_id)
            ->where('status', self::STATUS_OPEN);

        if ($stale_sale_id === null) {
            $builder->where('sale_id', null);
        } else {
            $builder->where('sale_id', $stale_sale_id);
        }

        $builder->update(['sale_id' => $sale_id]);

        return $this->db->affectedRows() === 1;
    }

    /**
     * Undoes attach_delivery_sale() for a presale that will not be delivered by that tab after all:
     * the cashier took it back to presales, or it was canceled. Never touches a delivered presale,
     * whose sale_id is the delivery's.
     */
    public function detach_delivery_sale(int $presale_id, int $sale_id): void
    {
        $this->db->table($this->table)
            ->where('presale_id', $presale_id)
            ->where('sale_id', $sale_id)
            ->where('status !=', self::STATUS_DELIVERED)
            ->update(['sale_id' => null]);
    }

    /**
     * Marks a fully paid presale as delivered by the sale the register just completed.
     *
     * Opens no transaction of its own on purpose: the caller (the register's completion, D17) wraps
     * Sale::save_value() and this call in one transaction, and rolls the sale back when this returns
     * false. The UPDATE is guarded by `status = open`, so two tills completing the same presale at once
     * produce one delivery and one refusal, and sale_id is UNIQUE besides.
     */
    public function mark_delivered(int $presale_id, int $sale_id, int $employee_id): bool
    {
        $presale = $this->lock($presale_id);

        if ($presale === null || $presale['status'] !== self::STATUS_OPEN) {
            return false;
        }

        $paid = model(Presale_payment::class)->get_paid($presale_id);

        if (bccomp($paid, (string) $presale['total'], self::MONEY_SCALE) !== 0) {
            return false;
        }

        $this->db->table($this->table)
            ->where('presale_id', $presale_id)
            ->where('status', self::STATUS_OPEN)
            ->update([
                'status'       => self::STATUS_DELIVERED,
                'sale_id'      => $sale_id,
                'delivered_at' => date('Y-m-d H:i:s'),
                'delivered_by' => $employee_id,
            ]);

        if ($this->db->affectedRows() !== 1) {
            return false;
        }

        return model(Presale_event::class)->log($presale_id, Presale_event::DELIVERED, $employee_id, ['sale_id' => $sale_id]);
    }

    // ---------------------------------------------------------------------------------------------
    // Reading
    // ---------------------------------------------------------------------------------------------

    public function get_info(int $presale_id): ?array
    {
        $row = $this->db->table($this->table)->where('presale_id', $presale_id)->get()->getRowArray();

        return $row ?: null;
    }

    /**
     * The presale that a sale delivered, or null when the sale did not come from a presale.
     */
    public function get_by_sale(int $sale_id): ?array
    {
        $row = $this->db->table($this->table)->where('sale_id', $sale_id)->get()->getRowArray();

        return $row ?: null;
    }

    /**
     * The agreed lines, with each product's current name and unit of measure for display.
     */
    public function get_lines(int $presale_id): array
    {
        return $this->db->table('presale_items AS pi')
            ->select('pi.*, items.name, items.item_number, items.unit_of_measure')
            ->join('items', 'items.item_id = pi.item_id', 'left')
            ->where('pi.presale_id', $presale_id)
            ->orderBy('pi.line', 'asc')
            ->get()->getResultArray();
    }

    public function get_installments(int $presale_id): array
    {
        return $this->db->table('presale_installments')
            ->where('presale_id', $presale_id)
            ->orderBy('due_date', 'asc')
            ->orderBy('installment_id', 'asc')
            ->get()->getResultArray();
    }

    /**
     * Total, paid, balance and the derived state of a presale on $today, ready for a screen or a
     * document. Null when there is no such presale.
     */
    public function get_summary(int $presale_id, string $today): ?array
    {
        $presale = $this->get_info($presale_id);

        if ($presale === null) {
            return null;
        }

        $paid = model(Presale_payment::class)->get_paid($presale_id);

        return $presale + ['number' => $this->number($presale_id)] + self::derive(
            $presale['status'],
            (string) $presale['total'],
            $paid,
            $this->get_installments($presale_id),
            $today,
        );
    }

    /**
     * The derived figures of a presale. Pure: everything it needs is passed in, so the edge cases
     * (due today, due yesterday, paid exactly) are tested without a database.
     *
     * - due_to_date: what the plan says should be paid by now, i.e. instalments due BEFORE $today. An
     *   instalment due today is not late yet.
     * - state: delivered | canceled | paid | late | up_to_date
     * - days_late: days since the oldest instalment the payments do not cover, 0 when not late.
     * - next_due_date / next_due_amount: the first instalment not fully covered, and what is missing
     *   of it.
     *
     * @param list<array{due_date: string, amount: float|string}> $installments
     *
     * @return array{paid: string, balance: string, due_to_date: string, state: string, days_late: int, next_due_date: string|null, next_due_amount: string}
     */
    public static function derive(string $status, string $total, string $paid, array $installments, string $today): array
    {
        $balance = bcsub($total, $paid, self::MONEY_SCALE);

        usort($installments, static fn (array $a, array $b): int => strcmp($a['due_date'], $b['due_date']));

        $due_to_date   = '0.00';
        $cumulative    = '0.00';
        $oldest_unpaid = null;
        $next_date     = null;
        $next_amount   = '0.00';

        foreach ($installments as $installment) {
            $amount     = bcadd((string) $installment['amount'], '0', self::MONEY_SCALE);
            $cumulative = bcadd($cumulative, $amount, self::MONEY_SCALE);

            if ($installment['due_date'] < $today) {
                $due_to_date = bcadd($due_to_date, $amount, self::MONEY_SCALE);
            }

            if ($next_date === null && bccomp($paid, $cumulative, self::MONEY_SCALE) < 0) {
                $next_date   = $installment['due_date'];
                $next_amount = bcsub($cumulative, $paid, self::MONEY_SCALE);

                if ($installment['due_date'] < $today) {
                    $oldest_unpaid = $installment['due_date'];
                }
            }
        }

        if ($status === self::STATUS_DELIVERED || $status === self::STATUS_CANCELED) {
            $state = $status;
        } elseif (bccomp($balance, '0', self::MONEY_SCALE) <= 0) {
            $state = self::STATE_PAID;
        } elseif (bccomp($paid, $due_to_date, self::MONEY_SCALE) < 0) {
            $state = self::STATE_LATE;
        } else {
            $state = self::STATE_UP_TO_DATE;
        }

        $days_late = 0;

        if ($state === self::STATE_LATE && $oldest_unpaid !== null) {
            $days_late = (int) (new DateTime($oldest_unpaid))->diff(new DateTime($today))->days;
        }

        return [
            'paid'            => $paid,
            'balance'         => $balance,
            'due_to_date'     => $due_to_date,
            'state'           => $state,
            'days_late'       => $days_late,
            'next_due_date'   => $state === self::STATE_PAID ? null : $next_date,
            'next_due_amount' => $state === self::STATE_PAID ? '0.00' : $next_amount,
        ];
    }

    /**
     * The number printed for the customer: the business's prefix and the id, zero-padded (T9).
     */
    public function number(int $presale_id): string
    {
        $prefix = (string) (config(OSPOS::class)->settings['presales_prefix'] ?? 'PV-');

        return $prefix . str_pad((string) $presale_id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * How many presales are open, for the warning before the module is switched off.
     */
    public function count_open(): int
    {
        return $this->db->table($this->table)->where('status', self::STATUS_OPEN)->countAllResults();
    }

    /**
     * Whether an item is in an open presale or in any campaign, so deleting it can be refused.
     */
    public function item_in_use(int $item_id): bool
    {
        if ($this->db->table('presale_campaign_items')->where('item_id', $item_id)->countAllResults() > 0) {
            return true;
        }

        return $this->db->table('presale_items AS pi')
            ->join('presales AS p', 'p.presale_id = pi.presale_id')
            ->where('pi.item_id', $item_id)
            ->where('p.status', self::STATUS_OPEN)
            ->countAllResults() > 0;
    }

    /**
     * Whether a customer has an open presale, so deleting the customer can be refused.
     */
    public function customer_has_open(int $customer_id): bool
    {
        return $this->db->table($this->table)
            ->where('customer_id', $customer_id)
            ->where('status', self::STATUS_OPEN)
            ->countAllResults() > 0;
    }

    /**
     * The presale row, locked for the rest of the current transaction.
     *
     * Raw SQL because the query builder has no FOR UPDATE. Outside a transaction the lock is released
     * at once, which is why every writer here opens one first.
     */
    private function lock(int $presale_id): ?array
    {
        $row = $this->db->query(
            'SELECT * FROM ' . $this->db->prefixTable($this->table) . ' WHERE presale_id = ? FOR UPDATE',
            [$presale_id],
        )->getRowArray();

        return $row ?: null;
    }

    /**
     * Whether the sale a presale is linked to is still an open register tab (sale_status OPENED).
     */
    private function delivery_tab_is_open(int $sale_id): bool
    {
        return $this->db->table('sales')
            ->where('sale_id', $sale_id)
            ->where('sale_status', OPENED)
            ->countAllResults() > 0;
    }

    private function customer_exists(int $customer_id): bool
    {
        return $customer_id > 0 && $this->db->table('customers')
            ->where('person_id', $customer_id)
            ->where('deleted', 0)
            ->countAllResults() > 0;
    }

    /**
     * A non-negative amount at scale 2, or null when it is not a number. Amounts reach the model
     * already normalised by the controller with parse_decimals(); this only refuses what is still not
     * a number.
     */
    private static function money_or_null(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        if (! Presale_campaign::is_decimal($value)) {
            return null;
        }

        return bcadd($value, '0', self::MONEY_SCALE);
    }

    private static function reference(?string $reference): ?string
    {
        $reference = trim((string) $reference);

        return $reference === '' ? null : mb_substr($reference, 0, 60);
    }
}
