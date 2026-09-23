<?php

namespace App\Libraries;

use App\Models\Item;
use App\Models\Item_kit;
use App\Models\Order_ticket;
use App\Models\Order_ticket_line;
use Throwable;

/**
 * The register's side of order tickets: everything Sales needs, and nothing that can stop a sale.
 *
 * THE REGISTER PULLS; THE PHONE NEVER WRITES sales_items
 *
 * The register keeps its cart in the session, and every edit of an open tab rewrites sales_items from
 * that cart (Sale::save_value() deletes them all first). A dish written into sales_items from a phone
 * while the cashier had that tab open would be deleted by the cashier's next keystroke: served and
 * never charged. So the phone writes only order_ticket_lines, and while a ticket's tab is the active
 * sale this class adds the dishes the register has not brought in yet into the CART -- through the
 * register's own add logic, the same path as typing the item -- and Sales autosaves. Only after that
 * save succeeds are the lines stamped billed_at. The cart is always the target of the merge; nothing
 * ever overwrites it from outside. See 20260923030000_AddOrderTicketLineBilling.
 *
 * NEVER THROWS INTO THE REGISTER
 *
 * Every public method catches Throwable, logs it as critical and answers the harmless default. The
 * ticket tables can be absent (SKIP_MIGRATIONS, a table lost by hand), and following an order must
 * never be what stops a till from charging. OBSERVAR NO PUEDE TUMBAR LO OBSERVADO.
 *
 * It follows the DATA, not the switch: if a sale belongs to a ticket, it is synced, even after the
 * business turns order tickets off. A ticket that exists was taken while the switch was on, and
 * charging its tab must still close it. With no tickets at all -- every business that never used the
 * feature -- each call is one cached tableExists() and, with an active sale, one indexed lookup.
 */
class Order_ticket_register
{
    private ?bool $tablesPresent = null;

    /**
     * The ticket that the given sale charges, or null. Null for any sale that is not a ticket's,
     * which is nearly all of them.
     */
    public function ticket_for_sale(int $sale_id): ?array
    {
        if ($sale_id <= 0 || ! $this->tables_present()) {
            return null;
        }

        try {
            return model(Order_ticket::class)->get_by_sale_id($sale_id);
        } catch (Throwable $e) {
            $this->log('ticket_for_sale', $sale_id, $e);

            return null;
        }
    }

    public function is_live(array $ticket): bool
    {
        return in_array($ticket['status'], [Order_ticket::STATUS_OPEN, Order_ticket::STATUS_DELIVERED], true);
    }

    public function is_cancelled(array $ticket): bool
    {
        return $ticket['status'] === Order_ticket::STATUS_CANCELLED;
    }

    /**
     * Whether the ticket has dishes the register has not brought in. postComplete() asks this before
     * charging: a total the cashier has not seen must never be charged.
     */
    public function has_unbilled(int $order_ticket_id): bool
    {
        if (! $this->tables_present()) {
            return false;
        }

        try {
            return model(Order_ticket_line::class)->get_unbilled($order_ticket_id) !== [];
        } catch (Throwable $e) {
            $this->log('has_unbilled', $order_ticket_id, $e);

            return false;
        }
    }

    /**
     * Adds the unbilled dishes of a ticket to the cart and returns the ids of the lines added.
     *
     * The caller must autosave and, only if that save succeeded, call mark_billed() with these ids;
     * if the save failed, it must restore the cart it had (see Sales::_sync_order_ticket()). A line
     * that cannot be added -- its item gone for good -- is skipped and stays unbilled, so it is not
     * silently lost; the register shows the count.
     *
     * @return array{added: list<int>, skipped: int}
     */
    public function add_unbilled_to_cart(Sale_lib $sale_lib, int $order_ticket_id, int $item_location): array
    {
        $added   = [];
        $skipped = 0;

        if (! $this->tables_present()) {
            return ['added' => $added, 'skipped' => $skipped];
        }

        try {
            foreach (model(Order_ticket_line::class)->get_unbilled($order_ticket_id) as $line) {
                if ($this->add_line($sale_lib, $line, $item_location)) {
                    $added[] = (int) $line['order_ticket_line_id'];
                } else {
                    $skipped++;
                }
            }
        } catch (Throwable $e) {
            $this->log('add_unbilled_to_cart', $order_ticket_id, $e);
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    /**
     * @param list<int> $order_ticket_line_ids
     */
    public function mark_billed(array $order_ticket_line_ids): void
    {
        if (! $this->tables_present()) {
            return;
        }

        try {
            model(Order_ticket_line::class)->mark_billed($order_ticket_line_ids);
        } catch (Throwable $e) {
            $this->log('mark_billed', 0, $e);
        }
    }

    /**
     * Dishes changed or voided by the waiter after the register already had them (D9, cashier's side).
     */
    public function billing_changes(int $order_ticket_id): array
    {
        if (! $this->tables_present()) {
            return [];
        }

        try {
            return model(Order_ticket_line::class)->get_billing_changes($order_ticket_id);
        } catch (Throwable $e) {
            $this->log('billing_changes', $order_ticket_id, $e);

            return [];
        }
    }

    public function acknowledge(int $order_ticket_id): void
    {
        if (! $this->tables_present()) {
            return;
        }

        try {
            model(Order_ticket_line::class)->acknowledge_billing_changes($order_ticket_id);
        } catch (Throwable $e) {
            $this->log('acknowledge', $order_ticket_id, $e);
        }
    }

    /**
     * The sale was charged: its ticket is closed. Order_ticket::mark_charged_by_sale() already never
     * throws; this wrapper only keeps the register from depending on that promise.
     */
    public function mark_charged(int $sale_id): void
    {
        if ($sale_id <= 0 || ! $this->tables_present()) {
            return;
        }

        try {
            model(Order_ticket::class)->mark_charged_by_sale($sale_id);
        } catch (Throwable $e) {
            $this->log('mark_charged', $sale_id, $e);
        }
    }

    /**
     * The cashier cancelled a ticket's tab from the register: the ticket is cancelled too, with a
     * reason that says where it happened. The other direction of what the ticket screen does when a
     * ticket is cancelled from the phone.
     */
    public function cancel_from_register(int $sale_id, int $employee_id): void
    {
        $ticket = $this->ticket_for_sale($sale_id);

        if ($ticket === null || ! $this->is_live($ticket)) {
            return;
        }

        try {
            model(Order_ticket::class)->cancel((int) $ticket['order_ticket_id'], $employee_id, lang('Order_tickets.cancelled_from_register'));
        } catch (Throwable $e) {
            $this->log('cancel_from_register', $sale_id, $e);
        }
    }

    /**
     * One dish into the cart, exactly as the register would add it when the cashier types it.
     *
     * A kit (its representative item has item_type ITEM_KIT) goes the way Sales::postAdd() adds one:
     * the representative item in PRICE_MODE_KIT with the kit's price, print option and discount, then
     * its components through add_item_kit(). One deliberate difference: postAdd() adds the components
     * once whatever the kit quantity; here the quantity is passed as add_item_kit()'s multiplier, so
     * two kits deduct the ingredients of two.
     *
     * The catalogue price is used, as when the cashier adds the item. The ticket keeps the price it
     * was taken at -- that is what printed for the kitchen (D12) -- and a difference between the two
     * is information, not an error (docs/Tecnico/comandas-y-cuenta-abierta.md §4.5).
     *
     * Deleted items are included, the same way copy_entire_sale() reloads a tab: the dish was ordered
     * while it existed, and it still has to be charged.
     */
    private function add_line(Sale_lib $sale_lib, array $line, int $item_location): bool
    {
        $item_id  = (string) $line['item_id'];
        $quantity = (string) $line['quantity'];
        $item     = model(Item::class)->get_info((int) $line['item_id']);

        if ((int) ($item->item_type ?? ITEM) === ITEM_KIT) {
            $item_kits   = model(Item_kit::class);
            $item_kit_id = $item_kits->get_item_kit_id_for_item_id((int) $line['item_id']);

            if ($item_kit_id === null) {
                return false;
            }

            $kit           = $item_kits->get_info($item_kit_id);
            $discount      = (string) $kit->kit_discount;
            $discount_type = (int) $kit->kit_discount_type;

            if (! $sale_lib->add_item($item_id, $item_location, $quantity, $discount, $discount_type, PRICE_MODE_KIT, (int) $kit->price_option, (int) $kit->print_option, null, null, null, null, true)) {
                return false;
            }

            $stock_warning = null;

            return $sale_lib->add_item_kit((string) $item_kit_id, $item_location, (float) $discount, (string) $discount_type, (bool) $kit->price_option, (bool) $kit->print_option, $stock_warning, $quantity);
        }

        $discount = '0';

        return $sale_lib->add_item($item_id, $item_location, $quantity, $discount, 0, PRICE_MODE_STANDARD, null, null, null, null, null, null, true);
    }

    /**
     * Whether the ticket tables are there at all. Cached for the request: the register asks on every
     * render.
     *
     * Protected and not private for one reason: a test double answers false here to prove that a
     * register without the ticket tables keeps charging, without dropping the real table -- the test
     * database is shared between files.
     */
    protected function tables_present(): bool
    {
        if ($this->tablesPresent === null) {
            try {
                $db                  = db_connect();
                $this->tablesPresent = $db->tableExists('order_tickets') && $db->fieldExists('billed_at', 'order_ticket_lines');
            } catch (Throwable $e) {
                $this->log('tables_present', 0, $e);
                $this->tablesPresent = false;
            }
        }

        return $this->tablesPresent;
    }

    private function log(string $where, int $id, Throwable $e): void
    {
        log_message('critical', 'Comandas en la caja (' . $where . ', ' . $id . '): ' . $e->getMessage());
    }
}
