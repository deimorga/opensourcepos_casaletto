<?php

/**
 * Order tickets -- what the business calls "comandas".
 *
 * `void` is the label of the order_tickets_void subpermission. The Employees screen builds that key
 * as ucfirst("<module_id>.<suffix>"), so it has to live in this file under exactly that name.
 */

return [
    "back"             => "Back",
    "disabled"         => "Order tickets are turned off for this business. An administrator can turn them on in Configuration, Order Tickets tab.",
    "dishes"           => "Dishes: {0}",
    "new_ticket"       => "New ticket",
    "no_tickets"       => "There are no open tickets at this site.",
    "pending"          => "Not sent: {0}",
    "status_cancelled" => "Cancelled",
    "status_charged"   => "Charged",
    "status_delivered" => "Delivered",
    "status_open"      => "Open",
    "void"             => "Allow cancelling a ticket",
];
