<?php

/**
 * Order tickets -- what the business calls "comandas".
 *
 * `void` is the label of the order_tickets_void subpermission. The Employees screen builds that key
 * as ucfirst("<module_id>.<suffix>"), so it has to live in this file under exactly that name.
 */

return [
    "back"             => "Back",
    "create"           => "Open ticket",
    "create_failed"    => "The ticket could not be opened. Please try again.",
    "disabled"         => "Order tickets are turned off for this business. An administrator can turn them on in Configuration, Order Tickets tab.",
    "dishes"           => "Dishes: {0}",
    "name"             => "Ticket name",
    "name_help"        => "How the till will recognise it: \"ANDREA\", \"table 4\", \"delivery Juan\".",
    "name_required"    => "Type a name for the ticket.",
    "new_ticket"       => "New ticket",
    "no_tickets"       => "There are no open tickets at this site.",
    "note"             => "Note for the whole order (optional)",
    "pending"          => "Not sent: {0}",
    "status_cancelled" => "Cancelled",
    "status_charged"   => "Charged",
    "status_delivered" => "Delivered",
    "status_open"      => "Open",
    "tables_off"       => "Order tickets need Tables turned on for this business. Ask an administrator.",
    "void"             => "Allow cancelling a ticket",
];
