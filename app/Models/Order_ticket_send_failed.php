<?php

namespace App\Models;

use RuntimeException;

/**
 * Sending an order ticket to the kitchen failed at the database, and nothing was sent.
 *
 * A type of its own so that nobody can confuse it with Order_ticket_round::send()'s null, which means
 * "there was nothing to send". The waiter is told two opposite things in those two cases.
 */
final class Order_ticket_send_failed extends RuntimeException
{
}
