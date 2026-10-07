<?php

namespace App\Libraries;

use App\Models\Presale;
use App\Models\Presale_payment;
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
     * Whether a cart payment, keyed by its label as Sale_lib keys them, is a presale payment.
     */
    public static function is_presale_payment(string $label): bool
    {
        helper('payment_type');

        return payment_type_code_from_label(urldecode($label)) === 'presale';
    }

    /**
     * @param array<string, array<string, mixed>> $payments as Sale_lib::get_payments() returns them
     */
    public static function has_presale_payment(array $payments): bool
    {
        foreach (array_keys($payments) as $label) {
            if (self::is_presale_payment((string) $label)) {
                return true;
            }
        }

        return false;
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
            'payment_type'    => $label,
            'payment_amount'  => $paid,
            'cash_refund'     => 0,
            'cash_adjustment' => CASH_ADJUSTMENT_FALSE,
        ]];

        foreach ($sale_lib->get_payments() as $key => $payment) {
            if (! self::is_presale_payment((string) $key)) {
                $payments[$key] = $payment;
            }
        }

        $sale_lib->set_payments($payments);
    }

    /**
     * Why the delivery on screen cannot be completed as it stands, as a language key with its
     * arguments, or null when it can. Re-read from the database: the screen may be minutes old.
     *
     * @param array<string, array<string, mixed>>     $payments
     * @param array<int|string, array<string, mixed>> $cart
     *
     * @return array{0: string, 1: list<string>}|null
     */
    public function completion_refusal(array $presale, string $mode, int $customer_id, array $cart, array $payments, bool $payments_cover_total): ?array
    {
        $presale_id = (int) $presale['presale_id'];
        $summary    = model(Presale::class)->get_summary($presale_id, date('Y-m-d'));

        if ($summary === null || $summary['status'] !== Presale::STATUS_OPEN || $summary['state'] !== Presale::STATE_PAID) {
            return ['Presale_register.delivery_failed', []];
        }

        if ($mode !== 'sale') {
            return ['Presale_register.mode_sale_only', []];
        }

        if ($customer_id !== (int) $summary['customer_id'] || ! $this->cart_matches($presale_id, $cart)) {
            return ['Presale_register.cart_changed', []];
        }

        $presale_payments = array_filter($payments, static fn ($key): bool => self::is_presale_payment((string) $key), ARRAY_FILTER_USE_KEY);

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
     * The lines whose delivered quantity differs from the agreed one -- in practice the real weight
     * of a line sold by weight (T18) -- as [line, item_id, agreed, delivered]. Recorded on the
     * presale when it is delivered, because a lighter weight hands cash back across the counter.
     *
     * @param array<int|string, array<string, mixed>> $cart
     *
     * @return list<array{line: int, item_id: int, agreed: string, delivered: string}>
     */
    public function weight_adjustments(int $presale_id, array $cart): array
    {
        $lines = model(Presale::class)->get_lines($presale_id);

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
     */
    public function cart_matches(int $presale_id, array $cart): bool
    {
        $lines = model(Presale::class)->get_lines($presale_id);

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
