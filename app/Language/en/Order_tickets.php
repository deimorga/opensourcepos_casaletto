<?php

/**
 * Order tickets -- what the business calls "comandas".
 *
 * `void` is the label of the order_tickets_void subpermission. The Employees screen builds that key
 * as ucfirst("<module_id>.<suffix>"), so it has to live in this file under exactly that name.
 */

return [
    "back"               => "Back",
    "void"               => "Allow cancelling a ticket",
];
