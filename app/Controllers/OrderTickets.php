<?php

namespace App\Controllers;

use App\Models\Order_ticket;
use App\Models\Order_ticket_line;
use App\Models\Stock_location;
use CodeIgniter\HTTP\RedirectResponse;
use Config\OSPOS;

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
 * The usual header. These views extend order_tickets/layout, the one responsive page in the system
 * (§3.13, §9).
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

    public function __construct()
    {
        parent::__construct('order_tickets');

        $this->tickets = model(Order_ticket::class);
        $this->lines   = model(Order_ticket_line::class);
    }

    /**
     * The live tickets of this site, newest first, with how many dishes each carries and how many
     * have not been sent to the kitchen yet.
     */
    public function getIndex(): string
    {
        if (! $this->is_enabled()) {
            return $this->render_disabled();
        }

        $tickets = $this->tickets->get_live_for_location($this->resolve_location_id());

        return view('order_tickets/index', $this->layout_data() + [
            'tickets' => $tickets,
            'counts'  => $this->lines->count_by_ticket(array_column($tickets, 'order_ticket_id')),
        ]);
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
     * What every order-ticket view hands to the layout.
     *
     * @return array{title: string, employee_name: string}
     */
    private function layout_data(): array
    {
        $employee = $this->employee->get_logged_in_employee_info();
        $name     = is_object($employee)
            ? trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? ''))
            : '';

        return [
            'title'         => lang('Module.order_tickets'),
            'employee_name' => $name,
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
