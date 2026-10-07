<?php

namespace App\Libraries;

use App\Models\Cashup;
use App\Models\Presale;
use App\Models\Presale_campaign;
use App\Models\Presale_payment;
use Config\OSPOS;
use Throwable;

/**
 * The register's side of presales: delivering a fully paid presale through the till (D17, T4, T18).
 *
 * WHICH SALE IS A DELIVERY
 *
 * Two marks, read in this order:
 *
 * 1. The session (Sale_lib::get_presale_id()). Set when the delivery is loaded into the cart, and
 *    cleared by Sale_lib::clear_all() -- so anything that replaces the cart (switching tab,
 *    completing, unsuspending another sale) forgets it in the same stroke.
 * 2. The database: `presales.sale_id` pointing at the register's current sale. With Tables on, the
 *    delivery is a tab, i.e. an OPENED row in `sales`, and the presale is linked to it the moment the
 *    tab is opened. That link is what survives switching tabs, a reload, another till opening the
 *    same tab, or a cashier who logs out and back in. It is the same column mark_delivered() writes:
 *    save_value() completes an open tab in place, so the open sale's id IS the delivery's sale id.
 *
 * With Tables off there is no tab and no OPENED row (Sales::postDeliverPresale() explains why): the
 * delivery lives in the session cart, like every other sale of that business, and nothing reaches the
 * database before completion. Losing the session loses the cart and nothing else -- the presale stays
 * open and paid, and "Entregar" loads it again.
 *
 * FOLLOWS THE DATA, NOT THE SWITCH
 *
 * Like Order_ticket_register: a delivery tab that exists was opened while presales were on, and its
 * guards must hold even if the business switches the module off afterwards. A business that never
 * used presales pays one cached tableExists() and, only when the register has a saved sale, one
 * lookup on a unique index.
 *
 * NEVER THROWS INTO THE REGISTER
 *
 * Every read catches Throwable and answers the harmless default. "Harmless" here is checked twice:
 * a sale carrying a `presale` payment that this class does not recognise as a delivery is refused
 * at completion (Sales::postComplete()), so a failed lookup can stop a charge but never turn a
 * presale payment into money nobody delivered.
 *
 * See docs/Tecnico/venta-anticipada.md §7.
 */
class Presale_register
{
    private const MONEY_SCALE = 2;

    private ?bool $tablesPresent = null;

    /**
     * The presale whose delivery is the register's current cart, in whatever status it is now, or
     * null when the cart is not a delivery -- which is nearly always.
     */
    public function presale_for(Sale_lib $sale_lib): ?array
    {
        if (! $this->tables_present()) {
            return null;
        }

        try {
            $presales = model(Presale::class);
            $marked   = $sale_lib->get_presale_id();

            if ($marked > 0) {
                return $presales->get_info($marked);
            }

            $sale_id = $sale_lib->get_sale_id();

            if ($sale_id <= 0) {
                return null;
            }

            $presale = $presales->get_by_sale($sale_id);

            if ($presale !== null) {
                $sale_lib->set_presale_id((int) $presale['presale_id']);
            }

            return $presale;
        } catch (Throwable $e) {
            log_message('critical', 'Presale_register::presale_for: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * The label the delivery's payment carries in the cart, in the language that is active now.
     */
    public static function payment_label(): string
    {
        return lang('Sales.presale');
    }

    /**
     * Whether a label, in the language that is active now, is the presale payment's. Only for what
     * a cashier types or posts (a forged payment type); a payment already in the cart is recognised
     * by is_presale_entry(), never by its label alone.
     */
    public static function is_presale_payment(string $label): bool
    {
        helper('payment_type');

        return payment_type_code_from_label(urldecode($label)) === 'presale';
    }

    /**
     * Whether a cart payment is a presale payment.
     *
     * By the code it carries first: ensure_payment() puts 'presale' on the delivery's payment, and a
     * tab reloaded from the database (Sale_lib::copy_entire_sale()) brings each row's stored code.
     * The label is in the language of whoever saved the tab -- "Preventa" for a Spanish-speaking
     * cashier, "Presale" for an English-speaking one -- so recognising the payment by its label in
     * the language active NOW turned the stored one into an ordinary payment on top of the new one,
     * and completing handed the whole presale back as cash change. Only an entry without a code
     * (one typed in this session) falls back to its label.
     *
     * @param array<string, mixed> $payment the entry, as Sale_lib::get_payments() holds it
     */
    public static function is_presale_entry(string $key, array $payment): bool
    {
        if (isset($payment['payment_type_code'])) {
            return $payment['payment_type_code'] === 'presale';
        }

        return self::is_presale_payment($key);
    }

    /**
     * @param array<string, array<string, mixed>> $payments as Sale_lib::get_payments() returns them
     */
    public static function has_presale_payment(array $payments): bool
    {
        return self::presale_entries($payments) !== [];
    }

    /**
     * The presale payments of a cart, by key.
     *
     * @param array<string, array<string, mixed>> $payments as Sale_lib::get_payments() returns them
     *
     * @return array<string, array<string, mixed>>
     */
    public static function presale_entries(array $payments): array
    {
        $found = [];

        foreach ($payments as $key => $payment) {
            if (self::is_presale_entry((string) $key, is_array($payment) ? $payment : [])) {
                $found[(string) $key] = $payment;
            }
        }

        return $found;
    }

    // ---------------------------------------------------------------------------------------------
    // What the register will charge (owner's decision of 2026-10-07: total parity with the register)
    // ---------------------------------------------------------------------------------------------

    /**
     * What the register will charge to deliver these lines to this customer, with the quantities
     * agreed: the presale's total. Computed here, next to the delivery, so registration and delivery
     * cannot drift apart.
     *
     * THE REGISTER'S RULE, NOT A COPY OF IT
     *
     * At completion the register (Sales::postComplete()) asks Tax_lib::get_taxes() for the taxes of
     * the cart and Sale_lib::get_totals() for the total:
     *
     *   total = Σ get_extended_amount(quantity, price, get_item_discount(...))  -- unrounded, per line
     *         + Σ sale_tax_amount of every tax EXCLUDED from the price           -- tax_included off
     *
     * at the bcscale Load_config sets on every request, max(2, currency + tax decimals). Nothing in
     * there rounds the total; it is accepted as paid when what is left is below half a unit of the
     * currency (`payments_cover_total`). So the least the register accepts is the total rounded half
     * up to the currency's decimals -- and that is what a presale charges. A 0-decimal currency with
     * two lines of 1.255 kg × 9,990 (12,537.45 each) is 25,075, not the 25,074 that rounding each line
     * gave before.
     *
     * Taxes come from Tax_lib itself, fed a cart with the same keys the delivery's cart has (built by
     * cart_for(), from the same presale lines Presale_register::load() rebuilds), for this customer
     * and in sale mode: the customer's taxable flag, item tax categories, destination-based tax, tax
     * included or not, all as the register decides them. Tax_lib reads the customer and the mode from
     * the register's session; the session is never touched here (the cashier may be in the middle of
     * a sale) -- Tax_lib is handed a Sale_lib that answers this customer and sale mode instead.
     *
     * CASH ROUNDING does not apply: a delivery is paid by the `presale` payment, which is not cash, so
     * the register never enters cash mode for it (Sale_lib::get_payments_total()).
     *
     * @param list<array{item_id: int|string, quantity: string, unit_price: string, discount?: string, discount_type?: int}> $lines
     *
     * @return array{total: string, taxes: string, charge: string} the register's unrounded total, the
     *                                                             taxes added on top of the prices,
     *                                                             and the charge (scale 2)
     */
    public function charge_for(array $lines, int $customer_id): array
    {
        $cart = $this->cart_for($lines);

        return $this->register_charge($cart, $customer_id);
    }

    /**
     * The register's total for a cart, as Sales::postComplete() computes it, for $customer_id in sale
     * mode. See charge_for().
     *
     * @param array<int|string, array<string, mixed>> $cart
     *
     * @return array{total: string, taxes: string, charge: string}
     */
    public function register_charge(array $cart, int $customer_id): array
    {
        helper('locale');

        $scale = bcscale();

        // What Load_config sets on every request of the application. Set here too so the figure does
        // not depend on who calls (a command, a test) -- and put back afterwards.
        bcscale(max(2, totals_decimals() + tax_decimals()));

        try {
            $sale_lib = self::sale_lib_for($customer_id);
            // Tax_lib reads the customer and the mode from the Sale_lib it is given.
            $tax_lib = new Tax_lib($sale_lib);

            $taxes = $tax_lib->get_taxes($cart)[0];
            $total = '0.0';

            // Sale_lib::get_totals(), line by line.
            foreach ($cart as $item) {
                $discount = $sale_lib->get_item_discount((string) $item['quantity'], (string) $item['price'], (string) $item['discount'], (int) $item['discount_type']);
                $total    = bcadd($total, $sale_lib->get_extended_amount((string) $item['quantity'], (string) $item['price'], $discount));
            }

            $added = '0';

            foreach ($taxes as $tax) {
                if ($tax['tax_type'] === Tax_lib::TAX_TYPE_EXCLUDED) {
                    $total = bcadd($total, (string) $tax['sale_tax_amount']);
                    $added = bcadd($added, (string) $tax['sale_tax_amount']);
                }
            }

            return [
                'total'  => $total,
                'taxes'  => bcadd($added, '0', self::MONEY_SCALE),
                'charge' => Presale_campaign::round_money($total),
            ];
        } finally {
            bcscale($scale);
        }
    }

    /**
     * A cart for Tax_lib and the totals, from presale lines: one line per presale line, numbered
     * from 1, with the keys they read -- the values Presale_register::load() gets from add_item() for
     * the same line (item, quantity, frozen price and discount, the item's tax category and stock
     * type).
     *
     * @param list<array<string, mixed>> $lines
     *
     * @return array<int, array<string, mixed>>
     */
    public function cart_for(array $lines): array
    {
        $ids   = array_values(array_unique(array_map(static fn (array $line): int => (int) $line['item_id'], $lines)));
        $items = [];

        if ($ids !== []) {
            $rows = db_connect()->table('items')
                ->select('item_id, tax_category_id, stock_type, item_type')
                ->whereIn('item_id', $ids)
                ->get()->getResultArray();

            foreach ($rows as $row) {
                $items[(int) $row['item_id']] = $row;
            }
        }

        $cart = [];
        $key  = 0;

        foreach ($lines as $line) {
            $item_id = (int) $line['item_id'];
            $key++;

            $cart[$key] = [
                'item_id'         => $item_id,
                'line'            => $key,
                'quantity'        => (string) $line['quantity'],
                'price'           => (string) $line['unit_price'],
                'discount'        => (string) ($line['discount'] ?? '0'),
                'discount_type'   => (int) ($line['discount_type'] ?? 0),
                'tax_category_id' => $items[$item_id]['tax_category_id'] ?? null,
                'stock_type'      => (int) ($items[$item_id]['stock_type'] ?? HAS_STOCK),
                'item_type'       => (int) ($line['item_type'] ?? $items[$item_id]['item_type'] ?? ITEM),
            ];
        }

        return $cart;
    }

    /**
     * A Sale_lib that answers $customer_id and sale mode, whatever the register's session holds.
     * Everything else is the register's own code.
     */
    private static function sale_lib_for(int $customer_id): Sale_lib
    {
        return new class ($customer_id) extends Sale_lib {
            public function __construct(private readonly int $presale_customer_id)
            {
                parent::__construct();
            }

            public function get_customer(): int
            {
                return $this->presale_customer_id;
            }

            public function get_mode(): string
            {
                return 'sale';
            }
        };
    }

    // ---------------------------------------------------------------------------------------------
    // A lighter weight than agreed (owner's decision of 2026-10-07)
    // ---------------------------------------------------------------------------------------------

    /**
     * The percentage of the presale's total that a lighter weight may hand back without someone
     * holding presales_manage. '0' means no limit. Read with ?? default, like every presales setting.
     */
    public static function weight_refund_limit(): string
    {
        $value = trim((string) (config(OSPOS::class)->settings['presales_weight_refund_limit'] ?? '15'));

        return Presale_campaign::is_decimal($value) ? $value : '15';
    }

    /**
     * What a lighter weight hands back across the counter: what was paid for the presale minus what
     * the register charges for the weights on screen, never below zero.
     */
    public static function weight_refund(string $paid, string $sale_total): string
    {
        $raw = self::weight_refund_raw($paid, $sale_total);

        // At scale 2, half up: what the sale's cash_refund column (decimal(15,2)) keeps of it.
        return bcadd($raw, '0.005', self::MONEY_SCALE);
    }

    /**
     * paid − sale total, unrounded, never below zero. The limit is compared against this and not a
     * rounded figure: a difference above the limit must not round down onto it.
     */
    public static function weight_refund_raw(string $paid, string $sale_total): string
    {
        $raw = bcsub($paid, $sale_total, 6);

        return bccomp($raw, '0', 6) > 0 ? $raw : '0';
    }

    /**
     * Change below half a unit of the currency: what is left when the register's unrounded total
     * (25,074.90) is paid by the presale's charge rounded to the currency (25,075). It cannot be
     * handed over, so a delivery does not record it as cash change nor open the drawer for it. The
     * same remainder the other way (paid 25,145 for 25,145.12) the register already ignores
     * (payments_cover_total).
     */
    public static function is_rounding_remainder(float $change): bool
    {
        helper('locale');

        return $change > 0 && $change < (10 ** -totals_decimals()) / 2;
    }

    /**
     * Whether giving back $refund (unrounded, weight_refund_raw()) needs presales_manage: it is above
     * the configured percentage of the presale's total. Exactly the limit is still allowed.
     */
    public static function weight_refund_over_limit(string $refund, string $presale_total): bool
    {
        $limit = self::weight_refund_limit();

        if (bccomp($limit, '0', 2) <= 0 || bccomp($refund, '0', 6) <= 0) {
            return false;
        }

        $allowed = bcdiv(bcmul($presale_total, $limit, 6), '100', 6);

        return bccomp($refund, $allowed, 6) > 0;
    }

    /**
     * Fills the (already cleared) cart with the delivery of $presale: its lines exactly as agreed,
     * its customer, sale mode, and the presale payment. Returns false, leaving the cart empty, when a
     * line cannot be rebuilt.
     *
     * THE CART IS BUILT ONE LINE AT A TIME ON AN EMPTY CART
     *
     * add_item() merges a line into an existing line of the same item and location, and the merged
     * line keeps the first price with the new total (Sale_lib.php, the "already in sale" branch). Two
     * lines of the same product at two prices would come out wrong. So each line is built by
     * add_item() alone -- every key the register expects, from the register's own code -- and the
     * lines are put together with set_cart() afterwards.
     *
     * NO CUSTOMER DISCOUNT
     *
     * The customer is set with set_customer(), never through postSelectCustomer(): that applies the
     * customer's standing discount to every line without one, and the agreed price would change.
     */
    public function load(Sale_lib $sale_lib, array $presale): bool
    {
        try {
            $lines = model(Presale::class)->get_lines((int) $presale['presale_id']);
        } catch (Throwable $e) {
            log_message('critical', 'Presale_register::load: ' . $e->getMessage());

            return false;
        }

        if ($lines === []) {
            return false;
        }

        $location = (int) $presale['location_id'];
        $cart     = [];
        $key      = 0;

        foreach ($lines as $line) {
            $sale_lib->set_cart([]);

            $item_id      = (string) $line['item_id'];
            $discount     = (string) $line['discount'];
            $price        = (string) $line['unit_price'];
            $description  = $line['description'] ?? null;
            $print_option = (int) $line['print_option'];

            if (! $sale_lib->add_item(
                $item_id,
                $location,
                (string) $line['quantity'],
                $discount,
                (int) $line['discount_type'],
                PRICE_MODE_STANDARD,
                null,
                null,
                $price,
                $description === '' ? null : $description,
                null,
                null,
                true,
            )) {
                $sale_lib->set_cart([]);

                return false;
            }

            $built = array_values($sale_lib->get_cart())[0];

            $key++;
            $built['line']         = $key;
            $built['print_option'] = $print_option;
            $cart[$key]            = $built;
        }

        $sale_lib->set_cart($cart);
        $sale_lib->set_customer((int) $presale['customer_id']);
        $sale_lib->set_mode('sale');
        $sale_lib->set_sale_type(SALE_TYPE_POS);
        $sale_lib->set_presale_id((int) $presale['presale_id']);
        $this->ensure_payment($sale_lib, (int) $presale['presale_id']);

        return true;
    }

    /**
     * Rebuilds a delivery whose cart no longer matches the presale (cart_matches()), keeping the real
     * weights already entered. They are read, by line, from the tab's saved lines when there is a
     * saved tab -- a tab reloaded through copy_entire_sale() has had its lines merged -- and from the
     * cart otherwise.
     */
    public function restore(Sale_lib $sale_lib, array $presale, int $sale_id): bool
    {
        $weights = [];

        try {
            if ($sale_id > 0) {
                $rows = db_connect()->table('sales_items')
                    ->select('line, quantity_purchased')
                    ->where('sale_id', $sale_id)
                    ->get()->getResultArray();

                foreach ($rows as $row) {
                    $weights[(int) $row['line']] = (string) $row['quantity_purchased'];
                }
            } else {
                foreach ($sale_lib->get_cart() as $key => $line) {
                    $weights[(int) $key] = (string) $line['quantity'];
                }
            }
        } catch (Throwable $e) {
            log_message('critical', 'Presale_register::restore: ' . $e->getMessage());
        }

        if (! $this->load($sale_lib, $presale)) {
            return false;
        }

        $cart = $sale_lib->get_cart();

        foreach ($cart as $key => $line) {
            $weight = $weights[(int) $key] ?? null;

            if ($weight === null || ! Sale_lib::line_sells_by_weight($line) || bccomp($weight, '0', 3) <= 0) {
                continue;
            }

            $cart[$key]['quantity']         = $weight;
            $cart[$key]['total']            = $sale_lib->get_item_total($weight, (string) $line['price'], (string) $line['discount'], (int) $line['discount_type']);
            $cart[$key]['discounted_total'] = $sale_lib->get_item_total($weight, (string) $line['price'], (string) $line['discount'], (int) $line['discount_type'], true);
        }

        $sale_lib->set_cart($cart);

        return true;
    }

    /**
     * Puts the delivery back the way it has to be: sale mode, the presale's customer and exactly ONE
     * presale payment for exactly what was paid. Called on every redraw of a delivery, because
     * Sale_lib::empty_payments() runs on every mode change and every line edit, and a tab reloaded
     * from the database brings its payments back from what was autosaved.
     *
     * The amount is read from presale_payments every time, never from the session.
     */
    public function ensure_delivery_state(Sale_lib $sale_lib, array $presale): void
    {
        if ($sale_lib->get_mode() !== 'sale') {
            $sale_lib->set_mode('sale');
        }

        $sale_lib->set_sale_type(SALE_TYPE_POS);

        if ($sale_lib->get_customer() !== (int) $presale['customer_id']) {
            $sale_lib->set_customer((int) $presale['customer_id']);
        }

        $this->ensure_payment($sale_lib, (int) $presale['presale_id']);
    }

    /**
     * Exactly one presale payment, for what the customer paid. Other payments are left alone: they
     * are the cash or card that covers a heavier weight (T18).
     */
    public function ensure_payment(Sale_lib $sale_lib, int $presale_id): void
    {
        $paid     = model(Presale_payment::class)->get_paid($presale_id);
        $label    = self::payment_label();
        $payments = [$label => [
            'payment_type'      => $label,
            'payment_amount'    => $paid,
            'cash_refund'       => 0,
            'cash_adjustment'   => CASH_ADJUSTMENT_FALSE,
            'payment_type_code' => 'presale',
        ]];

        // Every presale payment already there goes, whatever language its label is in: by its code.
        foreach ($sale_lib->get_payments() as $key => $payment) {
            if (! self::is_presale_entry((string) $key, $payment)) {
                $payments[$key] = $payment;
            }
        }

        $sale_lib->set_payments($payments);
    }

    /**
     * What completing a delivery needs to know about its presale, read from the database once per
     * completion (Sales::postComplete()) and handed to every check and to the save: the summary
     * (status, derived state, customer, total, paid) and the agreed lines. The screen may be minutes
     * old, so it is read at completion; it is not read again before the transaction, whose
     * Presale::mark_delivered() locks the row and checks status and paid once more.
     *
     * @return array{summary: array<string, mixed>|null, lines: list<array<string, mixed>>}
     */
    public function delivery_facts(int $presale_id): array
    {
        $presales = model(Presale::class);

        return [
            'summary' => $presales->get_summary($presale_id, date('Y-m-d')),
            'lines'   => $presales->get_lines($presale_id),
        ];
    }

    /**
     * Why the delivery on screen cannot be completed as it stands, as a language key with its
     * arguments, or null when it can. Re-read from the database (delivery_facts(), read here when
     * not given): the screen may be minutes old.
     *
     * @param array<string, array<string, mixed>>                                               $payments
     * @param array<int|string, array<string, mixed>>                                           $cart
     * @param array{summary: array<string, mixed>|null, lines: list<array<string, mixed>>}|null $facts
     *
     * @return array{0: string, 1: list<string>}|null
     */
    public function completion_refusal(array $presale, string $mode, int $customer_id, array $cart, array $payments, bool $payments_cover_total, ?array $facts = null): ?array
    {
        $presale_id = (int) $presale['presale_id'];
        $facts ??= $this->delivery_facts($presale_id);
        $summary = $facts['summary'];

        if ($summary === null || $summary['status'] !== Presale::STATUS_OPEN || $summary['state'] !== Presale::STATE_PAID) {
            return ['Presale_register.delivery_failed', []];
        }

        // Owner's decision of 2026-10-07: a delivery hands goods over, and with a lighter weight cash
        // too; both belong to a shift somebody will count.
        if (model(Cashup::class)->get_open_cashup_id() === null) {
            return ['Presale_register.no_open_cashup', []];
        }

        if ($mode !== 'sale') {
            return ['Presale_register.mode_sale_only', []];
        }

        if ($customer_id !== (int) $summary['customer_id'] || ! $this->cart_matches($presale_id, $cart, $facts['lines'])) {
            return ['Presale_register.cart_changed', []];
        }

        // Counted by code (is_presale_entry()): two presale payments, whatever their labels, are
        // refused -- the second would come back as cash change.
        $presale_payments = self::presale_entries($payments);

        if (count($presale_payments) !== 1
            || bccomp((string) reset($presale_payments)['payment_amount'], (string) $summary['paid'], self::MONEY_SCALE) !== 0) {
            return ['Presale_register.payment_mismatch', [to_currency($summary['paid'])]];
        }

        if (! $payments_cover_total) {
            return ['Presale_register.not_covered', []];
        }

        return null;
    }

    /**
     * Why a lighter weight cannot be handed back by this cashier (owner's decision of 2026-10-07):
     * what goes back is above the configured percentage of the presale's total and the cashier does
     * not hold presales_manage. Null when it can be completed.
     *
     * Only when a weight changed: change with the agreed weights (taxes changed since registration,
     * T20's known limit) is not a weight refund and is not what this limit is about.
     *
     * @param array<int|string, array<string, mixed>>                                           $cart
     * @param array{summary: array<string, mixed>|null, lines: list<array<string, mixed>>}|null $facts as delivery_facts() returns them
     *
     * @return array{0: string, 1: list<string>}|null
     */
    public function weight_refund_refusal(array $presale, array $cart, string $sale_total, bool $may_authorise, ?array $facts = null): ?array
    {
        if ($may_authorise || $this->weight_adjustments((int) $presale['presale_id'], $cart, $facts['lines'] ?? null) === []) {
            return null;
        }

        $paid   = isset($facts['summary']['paid']) ? (string) $facts['summary']['paid'] : model(Presale_payment::class)->get_paid((int) $presale['presale_id']);
        $refund = self::weight_refund_raw($paid, $sale_total);

        if (! self::weight_refund_over_limit($refund, (string) $presale['total'])) {
            return null;
        }

        return ['Presale_register.weight_refund_needs_manager', [self::weight_refund_limit()]];
    }

    /**
     * The lines whose delivered quantity differs from the agreed one -- in practice the real weight
     * of a line sold by weight (T18) -- as [line, item_id, agreed, delivered]. Recorded on the
     * presale when it is delivered, because a lighter weight hands cash back across the counter.
     *
     * @param array<int|string, array<string, mixed>> $cart
     * @param list<array<string, mixed>>|null         $lines the presale's lines, when already read
     *
     * @return list<array{line: int, item_id: int, agreed: string, delivered: string}>
     */
    public function weight_adjustments(int $presale_id, array $cart, ?array $lines = null): array
    {
        $lines ??= model(Presale::class)->get_lines($presale_id);

        ksort($cart);

        $adjusted = [];

        foreach (array_values($cart) as $index => $cart_line) {
            $agreed = (string) ($lines[$index]['quantity'] ?? '0');

            if (bccomp((string) $cart_line['quantity'], $agreed, 3) !== 0) {
                $adjusted[] = [
                    'line'      => $index + 1,
                    'item_id'   => (int) $cart_line['item_id'],
                    'agreed'    => bcadd($agreed, '0', 3),
                    'delivered' => bcadd((string) $cart_line['quantity'], '0', 3),
                ];
            }
        }

        return $adjusted;
    }

    /**
     * Whether the cart is still the agreed delivery: the same lines in the same order, same item,
     * price and discount, and the same quantity except on lines sold by weight, whose quantity is the
     * one thing a cashier may change (T18) -- to anything above zero.
     *
     * Every path that could change the cart is already refused on a delivery (Sales). This is the
     * last line behind them, for a path nobody thought of.
     *
     * @param array<int|string, array<string, mixed>> $cart
     * @param list<array<string, mixed>>|null         $lines the presale's lines, when already read
     */
    public function cart_matches(int $presale_id, array $cart, ?array $lines = null): bool
    {
        $lines ??= model(Presale::class)->get_lines($presale_id);

        if (count($lines) !== count($cart)) {
            return false;
        }

        ksort($cart);

        foreach (array_values($cart) as $index => $cart_line) {
            $line = $lines[$index];

            if ((int) $cart_line['item_id'] !== (int) $line['item_id']
                || bccomp((string) $cart_line['price'], (string) $line['unit_price'], self::MONEY_SCALE) !== 0
                || bccomp((string) $cart_line['discount'], (string) $line['discount'], self::MONEY_SCALE) !== 0
                || (int) $cart_line['discount_type'] !== (int) $line['discount_type']) {
                return false;
            }

            $quantity = (string) $cart_line['quantity'];

            if (Sale_lib::line_sells_by_weight($cart_line)) {
                if (bccomp($quantity, '0', 3) <= 0) {
                    return false;
                }
            } elseif (bccomp($quantity, (string) $line['quantity'], 3) !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Links an open presale to the register tab that delivers it. The guarded write is the model's
     * (Presale::attach_delivery_sale()).
     */
    public function attach(int $presale_id, int $sale_id, ?int $stale_sale_id): bool
    {
        return model(Presale::class)->attach_delivery_sale($presale_id, $sale_id, $stale_sale_id);
    }

    /**
     * Undoes attach() for a presale that will not be delivered by that tab after all. Never throws
     * into the register.
     */
    public function detach(int $presale_id, int $sale_id): void
    {
        try {
            model(Presale::class)->detach_delivery_sale($presale_id, $sale_id);
        } catch (Throwable $e) {
            log_message('critical', 'Presale_register::detach: ' . $e->getMessage());
        }
    }

    public function number(int $presale_id): string
    {
        return model(Presale::class)->number($presale_id);
    }

    private function tables_present(): bool
    {
        if ($this->tablesPresent === null) {
            try {
                $this->tablesPresent = db_connect()->tableExists('presales');
            } catch (Throwable $e) {
                $this->tablesPresent = false;
            }
        }

        return $this->tablesPresent;
    }
}
