<?php

namespace App\Models;

use CodeIgniter\Model;
use LogicException;
use Throwable;

/**
 * Each send of an order ticket to the kitchen -- what prints as "RONDA n".
 *
 * The whole reason this class exists is send(), and the one property send() must have is that the
 * kitchen never receives the same dish twice. A waiter double-tapping the button, a phone that
 * resubmits on reload, and the waiter and the cashier pressing at the same moment all have to end in
 * exactly one round carrying each line once.
 *
 * See docs/Tecnico/comandas-y-cuenta-abierta.md sections 4.3 and 4.4, and Order_ticket_line, whose
 * assign_to_round() is the other half of the mechanism.
 */
class Order_ticket_round extends Model
{
    protected $table            = 'order_ticket_rounds';
    protected $primaryKey       = 'round_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    /**
     * Must stay identical to Migration_AddOrderTickets::WRITABLE_COLUMNS_ROUNDS -- CodeIgniter drops
     * any field missing from here without raising anything, and this project has already lost data
     * to that twice. There is a test that compares the two.
     */
    protected $allowedFields = [
        'order_ticket_id',
        'number',
        'sent_at',
        'sent_by',
        'printed_at',
    ];

    // sent_at and printed_at are written by hand. CI4's automatic timestamps would insist on
    // created_at and updated_at columns this table does not have.
    protected $useTimestamps = false;

    /**
     * Sends every pending line of a ticket to the kitchen as a new round.
     *
     * Returns ['round_id' => int, 'number' => int, 'lines' => int], or null when there was nothing
     * to send -- no pending line, or a ticket that is no longer live. Null is an ordinary answer, not
     * a failure: it is exactly what the second tap of a double tap must get.
     *
     * HOW IT GUARANTEES "ONCE"
     *
     *   1. The ticket row is locked (SELECT ... FOR UPDATE) before anything is read. Two sends of the
     *      same ticket therefore run one after the other, never interleaved: the second waits for the
     *      first to commit.
     *   2. The round number is computed only after that lock, so two rounds can never both be
     *      "RONDA 2". The UNIQUE (order_ticket_id, number) key is the database's backstop if that is
     *      ever broken.
     *   3. Lines are taken by Order_ticket_line::assign_to_round(), a single UPDATE conditioned on
     *      round_id IS NULL. When the second send finally runs, the first has already taken every
     *      line, so it finds none.
     *   4. A round that took no lines is rolled back, never kept. An empty "RONDA 3" on the kitchen's
     *      paper would read as a missing order.
     *
     * A ticket that was cancelled or charged sends nothing: its lines are no longer anybody's to cook.
     *
     * MUST NOT BE CALLED INSIDE ANOTHER TRANSACTION. CodeIgniter only begins, commits and rolls back
     * the outermost transaction; a nested transRollback() just decrements a counter. Called inside
     * an outer transaction, step 4 would silently not happen and an empty round would be committed
     * with it. That is a programming error, so it is refused loudly rather than tolerated.
     *
     * Failures are not swallowed: this runs from the ticket's own screen, where the waiter must know
     * the order did not go (section 4.6 of the design). But the transaction is always closed first.
     * Every step is also checked for a false return, not only for an exception: DBDebug is off in
     * production (app/Config/Database.php), and there a failed query returns false instead of
     * throwing.
     *
     * @return array{round_id: int, number: int, lines: int}|null
     */
    public function send(int $order_ticket_id, int $sent_by): ?array
    {
        if ($this->db->transDepth > 0) {
            throw new LogicException('Order_ticket_round::send() must not run inside another transaction: a nested rollback would keep an empty round.');
        }

        $tickets = $this->db->prefixTable('order_tickets');
        $rounds  = $this->db->prefixTable($this->table);

        $this->db->transBegin();

        try {
            $ticket = $this->db->query(
                "SELECT status FROM {$tickets} WHERE order_ticket_id = ? FOR UPDATE",
                [$order_ticket_id]
            );

            $row = $ticket === false ? null : $ticket->getRowArray();

            if ($row === null || ! in_array($row['status'], [Order_ticket::STATUS_OPEN, Order_ticket::STATUS_DELIVERED], true)) {
                $this->db->transRollback();

                return null;
            }

            $max = $this->db->query(
                "SELECT COALESCE(MAX(number), 0) AS n FROM {$rounds} WHERE order_ticket_id = ?",
                [$order_ticket_id]
            );

            if ($max === false) {
                $this->db->transRollback();

                return null;
            }

            $number = (int) $max->getRow()->n + 1;

            $inserted = $this->db->table($this->table)->insert([
                'order_ticket_id' => $order_ticket_id,
                'number'          => $number,
                'sent_at'         => date('Y-m-d H:i:s'),
                'sent_by'         => $sent_by,
                'printed_at'      => null,
            ]);

            if ($inserted === false) {
                $this->db->transRollback();

                return null;
            }

            $round_id = (int) $this->db->insertID();

            // Built on THIS connection explicitly, not through model(): the UPDATE that takes the lines
            // has to run inside the transaction opened above, and a line model holding a different
            // connection would commit its UPDATE on its own -- leaving lines pointing at a round that
            // step 4 then rolls back.
            $lines = (new Order_ticket_line($this->db))->assign_to_round($order_ticket_id, $round_id);

            if ($lines < 1) {
                $this->db->transRollback();

                return null;
            }

            if (! $this->db->transCommit()) {
                $this->db->transRollback();

                return null;
            }

            return ['round_id' => $round_id, 'number' => $number, 'lines' => $lines];
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }
    }

    public function get_info(int $round_id): ?array
    {
        $row = $this->find($round_id);

        return is_array($row) ? $row : null;
    }

    /**
     * Every round of a ticket, in the order they were sent.
     */
    public function get_rounds(int $order_ticket_id): array
    {
        return $this->where('order_ticket_id', $order_ticket_id)
            ->orderBy('number', 'ASC')
            ->findAll();
    }

    /**
     * Records that the round's sheet came out of the printer.
     *
     * Sending and printing are two acts: the waiter sends from a phone that cannot reach the till's
     * printer, and the paper comes out when somebody opens the round at the register (section 8.3).
     * printed_at NULL is therefore a real state -- "sent, not printed yet" -- that the till can show.
     *
     * The FIRST print is what is kept. A reprint is a reprint, and overwriting the time would hide
     * how long the kitchen actually waited for the paper. Asking again on a printed round answers
     * true: it is printed.
     */
    public function mark_printed(int $round_id): bool
    {
        $this->db->table($this->table)
            ->where('round_id', $round_id)
            ->where('printed_at', null)
            ->update(['printed_at' => date('Y-m-d H:i:s')]);

        if ($this->db->affectedRows() === 1) {
            return true;
        }

        $round = $this->get_info($round_id);

        return $round !== null && $round['printed_at'] !== null;
    }
}
