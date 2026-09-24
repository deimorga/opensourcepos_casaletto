<?php

namespace App\Controllers;

use App\Libraries\Order_ticket_request_guard;
use App\Models\Dinner_table;
use App\Models\Item;
use App\Models\Order_ticket;
use App\Models\Order_ticket_line;
use App\Models\Order_ticket_round;
use App\Models\Order_ticket_send_failed;
use App\Models\Sale;
use App\Models\Stock_location;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\OSPOS;
use Throwable;

/**
 * The waiter's screens: take an order at the table, from a phone, so it is not lost on the way to
 * the till (D11). What the business calls "comandas".
 *
 * WHAT THIS CONTROLLER DOES NOT TOUCH, AND WHY
 *
 * Sale_lib. The register's cart lives in session('sales_cart'), and a session holds ONE cart. The
 * waiter's phone is a different session from the cashier's, and a waiter serving three tables would
 * overwrite their own cart. Everything here reads and writes by order_ticket_id and sale_id instead
 * (docs/Tecnico/comandas-y-cuenta-abierta.md §3.12).
 *
 * THE SCREEN IS THE REGISTER'S. The views use the shared POS header and footer, register.css and the
 * register's element ids, so the business sees the design it already knows (D25, 2026-09-23 -- an
 * earlier separate Bootstrap 5 layout was rejected by the owner for breaking that). What this screen
 * adds is a viewport and css/order_tickets.css, which make it usable on a phone; and what it leaves
 * out is charging, which stays in the register.
 *
 * THE SWITCH
 *
 * order_tickets_enable is read with ?? '0' on every request: a settings cache that predates the
 * migration has no key, and an absent switch is an off switch (§3.14, D3). Off, the module is not
 * usable; the menu tile can still show for a granted employee, so the screen says plainly that the
 * business has it turned off instead of answering with an error.
 *
 * The routes are explicit (app/Config/Routes.php) for the same reason as items/bulk: the waiter
 * leaves this screen open on the phone and reloads it, so its addresses must be stable.
 */
class OrderTickets extends Secure_Controller
{
    private Order_ticket $tickets;
    private Order_ticket_line $lines;
    private Order_ticket_request_guard $guard;

    public function __construct()
    {
        parent::__construct('order_tickets');

        $this->tickets = model(Order_ticket::class);
        $this->lines   = model(Order_ticket_line::class);
        $this->guard   = new Order_ticket_request_guard();
    }

    /**
     * The order-ticket screen with no ticket selected: the bar of live tickets, like the register's
     * bar of open tabs, and nothing else until one is picked or a new one is opened.
     */
    public function getIndex(): string
    {
        if (! $this->is_enabled()) {
            return $this->render_disabled();
        }

        return $this->render_screen(null);
    }

    /**
     * The form to open a ticket: a free name (D5) and an optional note for the whole order.
     */
    public function getNew(): string
    {
        if (! $this->is_enabled()) {
            return $this->render_disabled();
        }

        return view('order_tickets/new', $this->layout_data());
    }

    /**
     * Opens a ticket: the ticket itself, the throwaway table that puts it on the register's tab bar,
     * and the OPENED sale that will charge it -- all three or none, in one transaction.
     *
     * WHY A THROWAWAY TABLE. The register's tab bar lists OPENED sales by dinner_table_id and reopens
     * them by dinner_table_id (sales/register.php, Sales::postChangeMode()); there is no path by
     * sale_id. A table of its own makes the ticket show up, reopen and be cleaned up on payment
     * exactly like a tab the cashier opened with "new table" -- which is what the business was
     * already doing by hand ("ANDREA", "LOBO GORDITO"). The owner chose this over a new path through
     * the money screen.
     *
     * The table gets the name cut to its column's 30 characters, the same cut Sales::postCreateTable()
     * makes. The ticket keeps the full 64: the table is a label, the ticket is the record.
     *
     * Neither Sale_lib nor _autosave_open_tab() is used: the waiter's session is not the cashier's,
     * and the autosave's guard on pseudo-tables must stay exactly as it is (§3.3). The three models
     * write through the shared default connection, which is what the transaction below wraps.
     */
    public function postCreate(): RedirectResponse
    {
        if (($repeated = $this->refuse_repeated_submission(0)) !== null) {
            return $repeated;
        }

        if (! $this->is_enabled()) {
            return redirect()->to('comandas');
        }

        // The tab bar lives behind dinner_table_enable; without it the ticket would be unreachable
        // from the till. Configuration refuses this combination, but a stale settings row can still
        // produce it, and a ticket nobody can charge is worse than a clear refusal.
        if ((string) (config(OSPOS::class)->settings['dinner_table_enable'] ?? '0') !== '1') {
            return redirect()->to('comandas/nueva')->withInput()->with('error', lang('Order_tickets.tables_off'));
        }

        $name = trim((string) $this->request->getPost('name'));

        if ($name === '') {
            return redirect()->to('comandas/nueva')->withInput()->with('error', lang('Order_tickets.name_required'));
        }

        $person_id   = (int) session()->get('person_id');
        $location_id = $this->resolve_location_id();
        $db          = db_connect();

        $db->transBegin();

        try {
            $ticket_id = $this->tickets->create_ticket($name, $location_id, $person_id, (string) $this->request->getPost('note'));
            $table_id  = $ticket_id > 0 ? model(Dinner_table::class)->create_at(mb_substr($name, 0, 30), $location_id, true) : 0;
            $sale_id   = $table_id > 0 ? model(Sale::class)->create_open_sale($person_id, $table_id, $location_id) : 0;
            $attached  = $sale_id > 0 && $this->tickets->attach_sale($ticket_id, $sale_id);

            // Every step is checked for its return value and not only for an exception: DBDebug is
            // off in production, where a failed insert answers false instead of throwing.
            if (! $attached || ! $db->transCommit()) {
                $db->transRollback();

                return redirect()->to('comandas/nueva')->withInput()->with('error', lang('Order_tickets.create_failed'));
            }
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'No se pudo abrir la comanda "' . $name . '": ' . $e->getMessage());

            return redirect()->to('comandas/nueva')->withInput()->with('error', lang('Order_tickets.create_failed'));
        }

        return redirect()->to('comandas/' . $ticket_id);
    }

    /**
     * One ticket, drawn as the register draws a sale: the bar of live tickets with this one active,
     * the item search, the table of dishes and, on the right, the totals and what can be done with
     * the ticket -- send to the kitchen, mark delivered, cancel. Never charge: that is the register's.
     *
     * A ticket that is no longer live (charged, cancelled) is still shown, read-only: its rounds are
     * the record of what the kitchen was asked for.
     */
    public function getShow(int $order_ticket_id): RedirectResponse|string
    {
        if (! $this->is_enabled()) {
            return $this->render_disabled();
        }

        $ticket = $this->tickets->get_info($order_ticket_id);

        if ($ticket === null) {
            return redirect()->to('comandas')->with('error', lang('Order_tickets.not_found'));
        }

        return $this->render_screen($ticket);
    }

    /**
     * Live item search for the screen's search box: the same jQuery UI autocomplete the register
     * uses, fed from Item::search_orderable() -- items and kits only, never deleted ones.
     *
     * Its own endpoint and not sales/itemSearch: a waiter has no `sales` grant, and must not be
     * given one to search.
     */
    public function getSearch(): ResponseInterface
    {
        if (! $this->is_enabled()) {
            return $this->response->setJSON([]);
        }

        $rows = model(Item::class)->search_orderable((string) $this->request->getGet('term'), 25);

        return $this->response->setJSON(array_map(static fn (array $row): array => [
            'value' => (int) $row['item_id'],
            'label' => $row['name'] . ' | ' . to_currency((string) $row['unit_price']),
        ], $rows));
    }

    /**
     * Adds one dish. item_name and unit_price are taken from the catalogue HERE, by the server, and
     * copied onto the line -- never from the form, which anybody can edit.
     */
    public function postAddLine(int $order_ticket_id): RedirectResponse
    {
        if (($repeated = $this->refuse_repeated_submission($order_ticket_id)) !== null) {
            return $repeated;
        }

        $ticket = $this->live_ticket_or_null($order_ticket_id);

        if ($ticket === null) {
            return $this->refuse_closed($order_ticket_id);
        }

        $item = model(Item::class)->get_orderable((int) $this->request->getPost('item_id'));

        if ($item === null) {
            return $this->back_to($order_ticket_id, 'error', lang('Order_tickets.item_not_found'));
        }

        $line_id = $this->lines->add_line(
            $order_ticket_id,
            (int) $item['item_id'],
            (string) $item['name'],
            $this->posted_quantity(),
            (string) $item['unit_price'],
            (string) $this->request->getPost('kitchen_note'),
            (int) session()->get('person_id'),
        );

        if ($line_id === 0) {
            return $this->back_to($order_ticket_id, 'error', lang('Order_tickets.quantity_invalid'));
        }

        return $this->back_to($order_ticket_id, 'success', lang('Order_tickets.line_added', [$item['name']]), trim((string) $this->request->getPost('q')));
    }

    /**
     * Changes the quantity or the kitchen note of a dish. Touching a dish the kitchen already has is
     * allowed (D9) and announced -- here to the waiter, on the kitchen's side by the flag the model
     * raises, and on the till's side by the billing flag.
     *
     * The form carries what the screen showed (seen_quantity, seen_note), and the edit only goes
     * through if the dish still holds exactly that (Order_ticket_line::edit_line_seen()). Two phones
     * on the same table editing the same dish: the second one is told, instead of silently erasing
     * the first. A form without those fields was drawn before they existed; it is treated like any
     * other out-of-date page -- nothing saved, page reloaded -- rather than let through unchecked.
     */
    public function postEditLine(int $order_ticket_id, int $order_ticket_line_id): RedirectResponse
    {
        if (($repeated = $this->refuse_repeated_submission($order_ticket_id)) !== null) {
            return $repeated;
        }

        $line = $this->line_of_live_ticket($order_ticket_id, $order_ticket_line_id);

        if ($line === null) {
            return $this->refuse_closed($order_ticket_id);
        }

        $seen_quantity = $this->request->getPost('seen_quantity');
        $seen_note     = $this->request->getPost('seen_note');

        if (! is_string($seen_quantity) || ! is_string($seen_note)) {
            return $this->back_to($order_ticket_id, 'error', lang('Order_tickets.stale_form'));
        }

        $changes = ['kitchen_note' => (string) $this->request->getPost('kitchen_note')];

        if ($this->request->getPost('quantity') !== null) {
            $changes['quantity'] = $this->posted_quantity();
        }

        $outcome = $this->lines->edit_line_seen(
            $order_ticket_line_id,
            $changes,
            ['quantity' => $seen_quantity, 'kitchen_note' => $seen_note],
        );

        if ($outcome === Order_ticket_line::EDIT_CONFLICT) {
            return $this->back_to($order_ticket_id, 'error', lang('Order_tickets.edit_conflict'));
        }

        if ($outcome !== Order_ticket_line::EDIT_OK) {
            return $this->back_to($order_ticket_id, 'error', lang('Order_tickets.quantity_invalid'));
        }

        $after = $this->lines->get_info($order_ticket_line_id);

        if ($after !== null && (int) $after['changed_after_send'] === 1) {
            return $this->back_to($order_ticket_id, 'warning', lang('Order_tickets.changed_in_kitchen', [$line['item_name']]));
        }

        return $this->back_to($order_ticket_id, 'success', lang('Order_tickets.line_saved'));
    }

    /**
     * Voids a dish. Logical: the row stays, struck through. A dish that was already in the kitchen
     * gets the same announcement as an edit.
     */
    public function postVoidLine(int $order_ticket_id, int $order_ticket_line_id): RedirectResponse
    {
        if (($repeated = $this->refuse_repeated_submission($order_ticket_id)) !== null) {
            return $repeated;
        }

        $line = $this->line_of_live_ticket($order_ticket_id, $order_ticket_line_id);

        if ($line === null) {
            return $this->refuse_closed($order_ticket_id);
        }

        if (! $this->lines->void_line($order_ticket_line_id)) {
            return $this->back_to($order_ticket_id, 'error', lang('Order_tickets.line_already_voided'));
        }

        if ($line['round_id'] !== null) {
            return $this->back_to($order_ticket_id, 'warning', lang('Order_tickets.voided_in_kitchen', [$line['item_name']]));
        }

        return $this->back_to($order_ticket_id, 'success', lang('Order_tickets.line_voided'));
    }

    /**
     * Sends the unsent dishes to the kitchen as a new round (D8). Pressing it twice sends nothing the
     * second time: Order_ticket_round::send() is what guarantees it, not this screen.
     *
     * From a phone this creates the round; the paper comes out at the till, which is where the
     * printer is (§8.3). The ticket screen shows the round as "not printed" until it is.
     *
     * "Nothing to send" and "the send failed" get different messages on purpose. The first tells the
     * waiter the kitchen already has everything; the second, that it has none of it. Confusing them
     * leaves a table waiting for food nobody is cooking.
     */
    public function postSend(int $order_ticket_id): RedirectResponse
    {
        if (($repeated = $this->refuse_repeated_submission($order_ticket_id)) !== null) {
            return $repeated;
        }

        if ($this->live_ticket_or_null($order_ticket_id) === null) {
            return $this->refuse_closed($order_ticket_id);
        }

        try {
            $round = model(Order_ticket_round::class)->send($order_ticket_id, (int) session()->get('person_id'));
        } catch (Order_ticket_send_failed $e) {
            log_message('error', $e->getMessage());

            return $this->back_to($order_ticket_id, 'error', lang('Order_tickets.send_failed'));
        }

        if ($round === null) {
            return $this->back_to($order_ticket_id, 'error', lang('Order_tickets.nothing_to_send'));
        }

        return $this->back_to($order_ticket_id, 'success', lang('Order_tickets.round_sent', [$round['number'], $round['lines']]));
    }

    /**
     * The sheet of one round, for the kitchen: a bare page, no layout (§8).
     *
     * With ?imprimir=1 -- the link the ticket screen offers -- the page prints itself and the round
     * is marked printed. Without it, it is only shown: a waiter reviewing a round on a phone must not
     * set off a print dialog, nor record a print that never happened. Reprinting is opening the same
     * link again; the FIRST print time is the one kept.
     *
     * A closed ticket's rounds stay viewable: they are the record of what the kitchen was asked for.
     */
    public function getRound(int $order_ticket_id, int $round_id): RedirectResponse|string
    {
        if (! $this->is_enabled()) {
            return $this->render_disabled();
        }

        $ticket = $this->tickets->get_info($order_ticket_id);
        $rounds = model(Order_ticket_round::class);
        $round  = $rounds->get_info($round_id);

        if ($ticket === null || $round === null || (int) $round['order_ticket_id'] !== $order_ticket_id) {
            return redirect()->to('comandas')->with('error', lang('Order_tickets.not_found'));
        }

        $print = $this->request->getGet('imprimir') === '1';

        if ($print) {
            $rounds->mark_printed($round_id);
        }

        return view('order_tickets/round_print', [
            'ticket'  => $ticket,
            'round'   => $round,
            'lines'   => $this->lines->get_by_round($round_id),
            'print'   => $print,
            'company' => (string) (config(OSPOS::class)->settings['company'] ?? ''),
            // Who SENT the round -- the waiter the kitchen may need to ask -- not who is printing it.
            'employee' => $this->employee_name((int) $round['sent_by']),
        ]);
    }

    /**
     * The order reached the table or went out for delivery (D14, "gestionar orden").
     */
    public function postDelivered(int $order_ticket_id): RedirectResponse
    {
        if (($repeated = $this->refuse_repeated_submission($order_ticket_id)) !== null) {
            return $repeated;
        }

        if (! $this->is_enabled() || ! $this->tickets->mark_delivered($order_ticket_id, (int) session()->get('person_id'))) {
            return $this->refuse_closed($order_ticket_id);
        }

        return $this->back_to($order_ticket_id, 'success', lang('Order_tickets.delivered_done'));
    }

    /**
     * Cancels the whole ticket (D14): only before it is charged, only with order_tickets_void, and
     * only with a reason -- the kitchen may already have cooked, and "why" is what the operation will
     * want to read.
     *
     * Cancelling the ticket also closes its tab, exactly as the register closes one it cancels
     * (Sales::postCancel()): the throwaway table is deleted and the OPENED sale becomes CANCELED.
     * Without that, a ghost tab would stay on every till. Only an OPENED sale is touched: anything
     * else means the till already acted on it, and is left for a person to look at.
     *
     * The ticket is cancelled first because that transition is the one that can be refused (already
     * charged, cancelled twice); closing the tab only follows a cancellation that happened.
     */
    public function postCancel(int $order_ticket_id): RedirectResponse
    {
        if (($repeated = $this->refuse_repeated_submission($order_ticket_id)) !== null) {
            return $repeated;
        }

        if (! $this->is_enabled()) {
            return redirect()->to('comandas');
        }

        if (! $this->can_cancel()) {
            return $this->back_to($order_ticket_id, 'error', lang('Order_tickets.cancel_not_allowed'));
        }

        $reason = trim((string) $this->request->getPost('reason'));

        if ($reason === '') {
            return $this->back_to($order_ticket_id, 'error', lang('Order_tickets.cancel_reason_required'));
        }

        $ticket = $this->tickets->get_info($order_ticket_id);

        if ($ticket === null || ! $this->tickets->cancel($order_ticket_id, (int) session()->get('person_id'), $reason)) {
            return $this->refuse_closed($order_ticket_id);
        }

        $this->close_tab_of((int) ($ticket['sale_id'] ?? 0));

        return redirect()->to('comandas')->with('success', lang('Order_tickets.cancelled_done', [$ticket['name']]));
    }

    /**
     * The waiter's own way out.
     *
     * Not home/logout: Home is a Secure_Controller gated on the `home` grant, and a waiter granted
     * only order_tickets does not have it -- that link would strand them on no_access. Works with
     * the switch off too: turning the module off must never lock anybody in.
     */
    public function getLogout(): RedirectResponse
    {
        $this->employee->logout();

        return redirect()->to('login');
    }

    /**
     * Every write starts here. On a waiter's phone with poor signal the request can reach the server
     * and be saved while the answer is lost; the waiter reloads, the browser offers to resubmit the
     * form, and "Add" would add the dish twice -- or "Open ticket" open two tickets. Every form carries
     * a single-use token (Order_ticket_request_guard) and the second arrival of the same token does
     * nothing.
     *
     * Two different answers on purpose: a form with no token at all is a page drawn before this
     * safeguard existed (a phone left open across a deploy), and the waiter has to reload; a token
     * already used is the resubmission, and what it asked for is already saved.
     *
     * @return RedirectResponse|null null when this is the first arrival and the action may proceed
     */
    private function refuse_repeated_submission(int $order_ticket_id): ?RedirectResponse
    {
        $token = (string) $this->request->getPost(Order_ticket_request_guard::FIELD);

        if ($this->guard->claim($token === '' ? null : $token)) {
            return null;
        }

        $target = $order_ticket_id > 0 ? 'comandas/' . $order_ticket_id : 'comandas';

        return $token === ''
            ? redirect()->to($target)->with('error', lang('Order_tickets.stale_form'))
            : redirect()->to($target)->with('success', lang('Order_tickets.already_saved'));
    }

    /**
     * open or delivered: a ticket that can still receive dishes, be sent and be charged.
     */
    private function is_live(array $ticket): bool
    {
        return in_array($ticket['status'], [Order_ticket::STATUS_OPEN, Order_ticket::STATUS_DELIVERED], true);
    }

    /**
     * The ticket, only if the module is on and the ticket is still live. Every write goes through
     * this: a cancelled or charged ticket is closed, and a stale phone screen must not reopen it.
     */
    private function live_ticket_or_null(int $order_ticket_id): ?array
    {
        if (! $this->is_enabled()) {
            return null;
        }

        $ticket = $this->tickets->get_info($order_ticket_id);

        return $ticket !== null && $this->is_live($ticket) ? $ticket : null;
    }

    /**
     * The line, only if it belongs to THIS ticket and the ticket is live. The two ids come from the
     * URL, so the pairing is checked rather than trusted: otherwise any line could be edited through
     * any ticket's address.
     */
    private function line_of_live_ticket(int $order_ticket_id, int $order_ticket_line_id): ?array
    {
        if ($this->live_ticket_or_null($order_ticket_id) === null) {
            return null;
        }

        $line = $this->lines->get_info($order_ticket_line_id);

        return $line !== null && (int) $line['order_ticket_id'] === $order_ticket_id ? $line : null;
    }

    /**
     * The answer to a write on a ticket that is closed, missing or switched off: back to the ticket
     * if it still exists, to the list otherwise. Never an error page in the waiter's hand.
     */
    private function refuse_closed(int $order_ticket_id): RedirectResponse
    {
        if (! $this->is_enabled() || $this->tickets->get_info($order_ticket_id) === null) {
            return redirect()->to('comandas')->with('error', lang('Order_tickets.not_found'));
        }

        return $this->back_to($order_ticket_id, 'error', lang('Order_tickets.closed'));
    }

    /**
     * Post/Redirect/Get: every write lands back on the ticket's page, so a reload repeats nothing.
     *
     * @param 'error'|'success'|'warning' $type
     */
    private function back_to(int $order_ticket_id, string $type, string $message, string $search = ''): RedirectResponse
    {
        // After adding a dish the waiter usually wants another from the same search -- three
        // sandwiches of different kinds, a drink for each. Coming back to the same results, scrolled
        // to them, saves typing the search again on a phone keyboard for every dish.
        $url = 'comandas/' . $order_ticket_id;

        if ($search !== '') {
            $url .= '?q=' . rawurlencode($search) . '#ot-results';
        }

        return redirect()->to($url)->with($type, $message);
    }

    /**
     * The quantity as typed. A phone keyboard set to a comma-decimal locale may send "0,5"; the model
     * only accepts a plain decimal with a dot, so the comma is converted and the model still decides.
     */
    private function posted_quantity(): string
    {
        $quantity = trim((string) ($this->request->getPost('quantity') ?? '1'));

        return str_replace(',', '.', $quantity === '' ? '1' : $quantity);
    }

    /**
     * Cancelling needs its own permission (order_tickets_void): taking orders is a waiter's job,
     * cancelling one the kitchen may already have cooked is a supervisor's.
     */
    private function can_cancel(): bool
    {
        return $this->employee->has_grant('order_tickets_void', (int) session()->get('person_id'));
    }

    /**
     * Closes the register tab of a cancelled ticket, the way Sales::postCancel() closes one: the sale
     * becomes CANCELED and its throwaway table is deleted. Only an OPENED sale is touched -- the
     * status is read here, null-safely, rather than through Sale::get_sale_status(), which
     * dereferences a row that may not exist.
     *
     * Delivery and Take Away (ids 1 and 2) are never deleted, the same guard postCancel() keeps.
     */
    private function close_tab_of(int $sale_id): void
    {
        if ($sale_id <= 0) {
            return;
        }

        $row = db_connect()->table('sales')
            ->select('sale_status, dinner_table_id')
            ->where('sale_id', $sale_id)
            ->get()
            ->getRowArray();

        if ($row === null || (int) $row['sale_status'] !== OPENED) {
            log_message('warning', 'Comanda cancelada con la venta ' . $sale_id . ' fuera de OPENED; la pestaña no se tocó.');

            return;
        }

        model(Sale::class)->update_sale_status($sale_id, CANCELED);

        $table_id = (int) ($row['dinner_table_id'] ?? 0);

        if ($table_id > 2) {
            model(Dinner_table::class)->delete($table_id);
        }
    }

    /**
     * Whether the business has order tickets turned on. Absent reads as off (see the class docblock).
     */
    private function is_enabled(): bool
    {
        return (string) (config(OSPOS::class)->settings['order_tickets_enable'] ?? '0') === '1';
    }

    private function render_disabled(): string
    {
        return view('order_tickets/disabled', $this->layout_data());
    }

    /**
     * "First Last" of any employee, or '' when there is no such person.
     */
    private function employee_name(int $person_id): string
    {
        $info = $this->employee->get_info($person_id);

        return is_object($info) ? trim(($info->first_name ?? '') . ' ' . ($info->last_name ?? '')) : '';
    }

    /**
     * The screen, with or without a selected ticket. See screen.php for how it maps onto the register.
     *
     * @param array<string, mixed>|null $ticket
     */
    private function render_screen(?array $ticket): string
    {
        $tickets = $this->tickets->get_live_for_location($this->resolve_location_id());
        $data    = [
            'tickets' => $tickets,
            'counts'  => $this->lines->count_by_ticket(array_column($tickets, 'order_ticket_id')),
            'ticket'  => $ticket,
        ];

        if ($ticket !== null) {
            $id    = (int) $ticket['order_ticket_id'];
            $live  = $this->is_live($ticket);
            $term  = trim((string) $this->request->getGet('q'));
            $lines = $this->lines->get_lines($id);

            $data += [
                'live'         => $live,
                'lines'        => $lines,
                'item_numbers' => $this->item_numbers(array_column($lines, 'item_id')),
                'pending'      => count($this->lines->get_pending($id)),
                'rounds'       => model(Order_ticket_round::class)->get_rounds($id),
                'term'         => $term,
                // Without JavaScript the search box is a plain GET and the matches are listed here.
                'results'    => $live && $term !== '' ? model(Item::class)->search_orderable($term) : [],
                'can_cancel' => $live && $this->can_cancel(),
            ];
        }

        return view('order_tickets/screen', $data + $this->layout_data());
    }

    /**
     * The "Artículo #" column, as the register shows it. Read from the catalogue at draw time: the
     * line keeps the name and price it was ordered at, but the code is only for finding it.
     *
     * @param list<int|string> $item_ids
     *
     * @return array<int, string>
     */
    private function item_numbers(array $item_ids): array
    {
        $item_ids = array_values(array_unique(array_map('intval', $item_ids)));

        if ($item_ids === []) {
            return [];
        }

        $rows = db_connect()->table('items')->select('item_id, item_number')->whereIn('item_id', $item_ids)->get()->getResultArray();

        return array_column($rows, 'item_number', 'item_id');
    }

    /**
     * css/order_tickets.css with a fingerprint of its content in the query string.
     *
     * Staging and production serve it with no Cache-Control, so each browser guesses how long to keep
     * it. On 2026-09-23 an iPhone kept the stylesheet of the first, rejected design -- same file name,
     * rules for a different page -- and the new screen drew with the panels on top of each other.
     * The bundle gets this from gulp-rev; this file is not in the bundle, so it is done here.
     */
    private static function stylesheet_url(): string
    {
        $hash = @md5_file(FCPATH . 'css/order_tickets.css');

        return 'css/order_tickets.css' . ($hash === false ? '' : '?v=' . substr($hash, 0, 8));
    }

    /**
     * What every order-ticket view hands to the shared POS header (partial/header.php): the same
     * theme, menu and bundle as the register, plus the three options only this screen turns on.
     *
     * - responsive: the header declares no viewport by default, and this screen is used on phones.
     * - logout_route / profile_link: home/logout and home/changePassword sit behind the `home` grant;
     *   a waiter granted only Comandas does not have it, and those links would be dead ends.
     *
     * @return array<string, mixed>
     */
    private function layout_data(): array
    {
        $employee = $this->employee->get_logged_in_employee_info();
        $has_home = is_object($employee) && $this->employee->has_module_grant('home', (int) $employee->person_id);

        return [
            'responsive'        => true,
            'extra_stylesheets' => [self::stylesheet_url()],
            'logout_route'      => 'comandas/salir',
            'profile_link'      => $has_home,
            // One single-use token per drawn page, carried by every form on it. Submitting any one of
            // them reloads the page, which draws a new token. See refuse_repeated_submission().
            'request_token' => $this->guard->issue(),
        ];
    }

    /**
     * Which site the tickets on this screen belong to.
     *
     * 1. The location the register is using in this session, when the employee is a cashier who
     *    picked one: the ticket must land on the same site as the sale that will charge it. Only the
     *    session key is read -- never the cart, which is Sale_lib's and not this controller's.
     * 2. The employee's own sales location grant.
     * 3. The business's first active location. This is the ordinary case for a waiter, who is
     *    granted order_tickets and no sales location at all -- and must not be given one: with two
     *    sites, two location grants would pass Employee::has_module_grant('sales') and open the
     *    register to the waiter (see 20260923020000_AddOrderTicketsModule).
     *
     * Stock_location::get_default_location_id() is deliberately not used: it dereferences a row
     * that, for a waiter, does not exist.
     */
    private function resolve_location_id(): int
    {
        $from_register = (int) session()->get('sales_location');

        if ($from_register > 0) {
            return $from_register;
        }

        $locations = model(Stock_location::class);

        return $locations->find_granted_location_id((int) session()->get('person_id'), 'sales')
            ?? $locations->find_first_active_location_id()
            ?? 1;
    }
}
