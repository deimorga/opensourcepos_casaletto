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
     * Returns ['round_id' => int, 'number' => int, 'lines' => int], or null when there was NOTHING TO
     * SEND: no such ticket, a ticket that is no longer live, or no pending line. Null is an ordinary
     * answer, not a failure: it is exactly what the second tap of a double tap must get.
     *
     * A FAILURE IS NEVER A NULL. If the database fails -- a lock that did not come free in time, a
     * lost connection -- this throws Order_ticket_send_failed after closing the transaction. The two
     * must not look alike: "there were no new dishes" and "the dishes did not go" lead a waiter to
     * opposite actions, and telling them the first when the second happened leaves a table waiting
     * for food nobody is cooking. (Found by the 2.2 concurrency test: an earlier version answered
     * null to a lock timeout.)
     *
     * HOW IT GUARANTEES "ONCE"
     *
     *   1. The ticket row is locked (SELECT ... FOR UPDATE) before anything is read. Two sends of the
     *      same ticket therefore run one after the other, never interleaved: the second waits for the
     *      first to commit. OrderTicketRoundTest proves it with a real second connection.
     *   2. "Is there anything to send?" is asked UNDER that lock and BEFORE a round exists, so an
     *      empty round is never created, and the answer cannot change until this send commits.
     *   3. The round number is computed after the lock, so two rounds can never both be "RONDA 2".
     *      The UNIQUE (order_ticket_id, number) key is the database's backstop.
     *   4. Lines are taken by Order_ticket_line::assign_to_round(), one UPDATE conditioned on
     *      round_id IS NULL. Taking none at this point is not "nothing to send" -- step 2 already saw
     *      some -- so it is treated as the failure it is.
     *
     * WHY EVERY STEP CHECKS FOR false: inside a transaction CodeIgniter does NOT throw for a failed
     * query, not even with DBDebug on (BaseConnection::query(): "In transactions, do not throw
     * exception by default"). The query just returns false. So every result is checked, in testing
     * and in production alike.
     *
     * After a failed query the connection's transaction status stays "failed" under strict mode, and
     * any later transaction on it in the same request would roll back without a word; the status is
     * reset once this one is closed.
     *
     * MUST NOT BE CALLED INSIDE ANOTHER TRANSACTION. CodeIgniter only begins, commits and rolls back
     * the outermost transaction; a nested transRollback() just decrements a counter. That is a
     * programming error, so it is refused loudly rather than tolerated.
     *
     * @return array{round_id: int, number: int, lines: int}|null
     *
     * @throws Order_ticket_send_failed when the database failed and nothing was sent
     */
    public function send(int $order_ticket_id, int $sent_by): ?array
    {
        if ($this->db->transDepth > 0) {
            throw new LogicException('Order_ticket_round::send() must not run inside another transaction: a nested rollback would keep an empty round.');
        }

        $tickets = $this->db->prefixTable('order_tickets');
        $lines   = $this->db->prefixTable('order_ticket_lines');
        $rounds  = $this->db->prefixTable($this->table);

        $this->db->transBegin();

        try {
            $ticket = $this->db->query(
                "SELECT status FROM {$tickets} WHERE order_ticket_id = ? FOR UPDATE",
                [$order_ticket_id],
            );

            if ($ticket === false) {
                $this->fail_send($order_ticket_id, 'the ticket could not be locked');
            }

            $row = $ticket->getRowArray();

            if ($row === null || ! in_array($row['status'], [Order_ticket::STATUS_OPEN, Order_ticket::STATUS_DELIVERED], true)) {
                return $this->nothing_to_send();
            }

            // The D8 condition, the same one assign_to_round() writes with, asked under the lock.
            $pending = $this->db->query(
                "SELECT COUNT(*) AS n FROM {$lines} WHERE order_ticket_id = ? AND round_id IS NULL AND status = ?",
                [$order_ticket_id, Order_ticket_line::STATUS_PENDING],
            );

            if ($pending === false) {
                $this->fail_send($order_ticket_id, 'the pending dishes could not be read');
            }

            if ((int) $pending->getRow()->n === 0) {
                return $this->nothing_to_send();
            }

            $max = $this->db->query(
                "SELECT COALESCE(MAX(number), 0) AS n FROM {$rounds} WHERE order_ticket_id = ?",
                [$order_ticket_id],
            );

            if ($max === false) {
                $this->fail_send($order_ticket_id, 'the next round number could not be read');
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
                $this->fail_send($order_ticket_id, 'the round could not be created');
            }

            $round_id = (int) $this->db->insertID();

            // Built on THIS connection explicitly, not through model(): the UPDATE that takes the lines
            // has to run inside the transaction opened above, and a line model holding a different
            // connection would commit its UPDATE on its own -- leaving lines pointing at a round that
            // is then rolled back.
            $taken = (new Order_ticket_line($this->db))->assign_to_round($order_ticket_id, $round_id);

            if ($taken < 1) {
                $this->fail_send($order_ticket_id, 'the dishes could not be assigned to the round');
            }

            if (! $this->db->transCommit()) {
                $this->fail_send($order_ticket_id, 'the round could not be committed');
            }

            return ['round_id' => $round_id, 'number' => $number, 'lines' => $taken];
        } catch (Order_ticket_send_failed $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->close_failed_transaction();

            throw new Order_ticket_send_failed('Sending order ticket ' . $order_ticket_id . ' failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Nothing to send: close the transaction, which wrote nothing, and answer null.
     */
    private function nothing_to_send(): ?array
    {
        $this->db->transRollback();

        return null;
    }

    /**
     * The database failed: close the transaction, leave the connection usable, and say so.
     *
     * @throws Order_ticket_send_failed always
     */
    private function fail_send(int $order_ticket_id, string $what): never
    {
        $this->close_failed_transaction();

        throw new Order_ticket_send_failed('Sending order ticket ' . $order_ticket_id . ' failed: ' . $what . '.');
    }

    private function close_failed_transaction(): void
    {
        if ($this->db->transDepth > 0) {
            $this->db->transRollback();
        }

        // Strict mode keeps a failed status across transactions; without this, the next transaction
        // on this connection in the same request would roll back silently.
        $this->db->resetTransStatus();
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
