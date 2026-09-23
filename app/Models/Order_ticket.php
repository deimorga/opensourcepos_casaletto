<?php

namespace App\Models;

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Model;
use Throwable;

/**
 * The order ticket -- what the business calls a "comanda": one order somebody is taking, with a free
 * name ("ANDREA", "mesa 4", "domicilio Juan"), billed by exactly one OPENED sale.
 *
 * The state machine lives here and not in a controller on purpose. Cancelling a ticket that was
 * already charged is the case the business excluded outright (D14: "before payment was requested"),
 * and the model is the only place where that rule cannot be skipped by some screen written later.
 *
 * Every transition is a single conditioned UPDATE. See transition() for why that is not a style
 * choice.
 *
 * See docs/Tecnico/comandas-y-cuenta-abierta.md sections 4.1, 4.6 and 7.2, and
 * app/Database/Migrations/20260923000000_AddOrderTickets.php.
 */
class Order_ticket extends Model
{
    /**
     * Stable codes, never labels: the wording lives in the language files and is resolved at display
     * time, so switching locale cannot change what the data means. Same reasoning as
     * payment_type_code, cash_source and item_price_history.source.
     */
    public const STATUS_OPEN      = 'open';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_CHARGED   = 'charged';

    /**
     * from => list of allowed destinations.
     *
     * cancelled and charged are terminal. A charged ticket is money that already entered the drawer,
     * and letting it be cancelled afterwards would make the ticket list disagree with the till.
     */
    public const TRANSITIONS = [
        self::STATUS_OPEN      => [self::STATUS_DELIVERED, self::STATUS_CANCELLED, self::STATUS_CHARGED],
        self::STATUS_DELIVERED => [self::STATUS_CHARGED, self::STATUS_CANCELLED],
        self::STATUS_CANCELLED => [],
        self::STATUS_CHARGED   => [],
    ];

    protected $table            = 'order_tickets';
    protected $primaryKey       = 'order_ticket_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    /**
     * Must stay identical to Migration_AddOrderTickets::WRITABLE_COLUMNS_TICKETS -- CodeIgniter drops
     * any field missing from here without raising anything, and this project has already lost data
     * to that twice. There is a test that compares the two.
     */
    protected $allowedFields = [
        'sale_id',
        'name',
        'status',
        'location_id',
        'note',
        'opened_by',
        'opened_at',
        'delivered_by',
        'delivered_at',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
        'charged_at',
    ];

    // Every *_at column is written by hand at the moment of its transition. CI4's automatic
    // timestamps would insist on created_at/updated_at columns that this table does not have.
    protected $useTimestamps = false;

    /**
     * Whether the schema has caught up with the code yet. Cached for the instance, like
     * Item_price_history: it is asked on the path of every charged sale.
     */
    private ?bool $tableExists = null;

    /**
     * Whether the state machine allows going from one status to another.
     *
     * Static and free of database access on purpose, so the rule can be proved without a live schema
     * and asked by a view deciding which buttons to draw. An unknown status allows nothing.
     */
    public static function can_transition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Opens a new ticket and returns its id, or 0 when there is nothing to open.
     *
     * A blank name is refused rather than defaulted: the name is how the cashier finds the order
     * again among a dozen tabs, and an unnamed one is the ticket nobody will ever charge.
     *
     * The name is cut to the column's 64 characters by mb_substr and not left to the database: a
     * byte-level cut, or a strict-mode rejection, would either split an "ñ" in half or lose the
     * order. sale_id stays NULL until attach_sale(), inside the caller's transaction.
     *
     * Not wrapped in try/catch: this runs from the ticket's own screens, where the person is using
     * the ticket rather than selling, and a failure there has to be seen (section 4.6).
     */
    public function create_ticket(string $name, int $location_id, int $opened_by, string $note = ''): int
    {
        $name = mb_substr(trim($name), 0, 64);

        if ($name === '') {
            return 0;
        }

        $id = $this->insert([
            'sale_id'     => null,
            'name'        => $name,
            'status'      => self::STATUS_OPEN,
            'location_id' => $location_id,
            'note'        => mb_substr(trim($note), 0, 255),
            'opened_by'   => $opened_by,
            'opened_at'   => date('Y-m-d H:i:s'),
        ]);

        return (int) $id;
    }

    /**
     * Links the ticket to the OPENED sale that will bill it.
     *
     * Only a live ticket (open or delivered) can be attached: pointing a cancelled or charged ticket
     * at a new sale would bill an order that is already closed.
     *
     * Asking again with the same sale answers true. The connection runs without FOUND_ROWS, so an
     * UPDATE that writes the value already there reports zero rows, and the caller must not read that
     * as a refusal. The read afterwards only interprets the outcome; it never decides the write.
     */
    public function attach_sale(int $order_ticket_id, int $sale_id): bool
    {
        $this->builder()
            ->where('order_ticket_id', $order_ticket_id)
            ->whereIn('status', [self::STATUS_OPEN, self::STATUS_DELIVERED])
            ->update(['sale_id' => $sale_id]);

        if ($this->db->affectedRows() === 1) {
            return true;
        }

        $row = $this->get_info($order_ticket_id);

        return $row !== null
            && (int) $row['sale_id'] === $sale_id
            && in_array($row['status'], [self::STATUS_OPEN, self::STATUS_DELIVERED], true);
    }

    public function get_info(int $order_ticket_id): ?array
    {
        $row = $this->builder()
            ->where('order_ticket_id', $order_ticket_id)
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    /**
     * The ticket a sale bills. A sale bills at most one ticket; should two ever point at the same
     * sale the oldest is returned, so the answer does not change between two reads.
     */
    public function get_by_sale_id(int $sale_id): ?array
    {
        $row = $this->builder()
            ->where('sale_id', $sale_id)
            ->orderBy('order_ticket_id', 'ASC')
            ->limit(1)
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    /**
     * The tickets still in play at one site, newest opened first.
     *
     * Filtered by location from day one even though Sale::get_all_opened() is not (section 3.10):
     * the other site's orders are noise on a till that cannot charge them.
     *
     * Ordered by id as well and not only by opened_at: the column has one-second resolution, and two
     * tickets opened in the same second would otherwise come back in whatever order the database
     * felt like.
     */
    public function get_live_for_location(int $location_id): array
    {
        return $this->builder()
            ->where('location_id', $location_id)
            ->whereIn('status', [self::STATUS_OPEN, self::STATUS_DELIVERED])
            ->orderBy('opened_at', 'DESC')
            ->orderBy('order_ticket_id', 'DESC')
            ->get()
            ->getResultArray();
    }

    /**
     * The order reached the table or went out for delivery -- the "gestionar orden" of D14. Without
     * it nobody can say what is still waiting to be served.
     */
    public function mark_delivered(int $order_ticket_id, int $employee_id): bool
    {
        return $this->transition($order_ticket_id, self::STATUS_DELIVERED, [
            'delivered_by' => $employee_id,
            'delivered_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Cancels a ticket that was not charged. The reason is mandatory and a blank one refuses without
     * touching anything: a ticket that falls through after it was printed already cost paper and
     * possibly a cooked dish, and "why" is exactly what the operation wants to read afterwards.
     */
    public function cancel(int $order_ticket_id, int $employee_id, string $reason): bool
    {
        $reason = mb_substr(trim($reason), 0, 255);

        if ($reason === '') {
            return false;
        }

        return $this->transition($order_ticket_id, self::STATUS_CANCELLED, [
            'cancelled_by'  => $employee_id,
            'cancelled_at'  => date('Y-m-d H:i:s'),
            'cancel_reason' => $reason,
        ]);
    }

    /**
     * Marks as charged every live ticket billed by a sale that was just paid, and returns how many.
     * **Never throws.**
     *
     * OBSERVAR NO PUEDE TUMBAR LO OBSERVADO.
     *
     * This runs from Sales::postComplete(), on the path of the money, after the sale has been paid
     * for. A ticket register that can fail the sale it was only following would be one worth
     * removing, so any failure becomes a critical log line and a 0, nothing else.
     *
     * The table may also be genuinely absent, and the guard is cheap insurance rather than the
     * normal path. Since 8f92b4901 (2026-08-28) docker/entrypoint.sh migrates every tenant schema
     * before Apache accepts a request, so the old "code live, table missing" deploy window is closed
     * on the ordinary route. It is not closed on every route: SKIP_MIGRATIONS=1 is a documented escape
     * hatch that starts the container behind its schema, and a table can be lost by hand while the
     * migrations table still says it is there -- in which case is_latest() stays true, the till keeps
     * working, and this is the only thing standing between a missing ticket table and a sale that
     * cannot be charged.
     *
     * Cancelled tickets are left alone on purpose: a cancelled order that ends up on a paid sale is
     * something to investigate, not something to overwrite.
     */
    public function mark_charged_by_sale(int $sale_id): int
    {
        try {
            if (! $this->tableIsThere()) {
                return 0;
            }

            $this->builder()
                ->where('sale_id', $sale_id)
                ->whereIn('status', self::origins_for(self::STATUS_CHARGED))
                ->update([
                    'status'     => self::STATUS_CHARGED,
                    'charged_at' => date('Y-m-d H:i:s'),
                ]);

            return (int) $this->db->affectedRows();
        } catch (Throwable $e) {
            log_message(
                'critical',
                'No se pudieron marcar como cobradas las comandas de la venta ' . $sale_id . ': ' . $e->getMessage(),
            );

            return 0;
        }
    }

    /**
     * Moves one ticket to $to, and answers whether THIS call is the one that moved it.
     *
     * The single statement is the point. The allowed origins go inside the UPDATE, so the database
     * decides, and affectedRows() === 1 is the proof. Reading the status first and writing afterwards
     * would let two tills pressing at the same moment both find the ticket open, and one would charge
     * it while the other cancels it. A refused transition is a false, never an exception: it is an
     * ordinary answer to a person who pressed a stale button.
     *
     * @param array<string, mixed> $extra the who/when/why columns that go with this transition
     */
    private function transition(int $order_ticket_id, string $to, array $extra): bool
    {
        $origins = self::origins_for($to);

        if ($origins === []) {
            return false;
        }

        $this->builder()
            ->where('order_ticket_id', $order_ticket_id)
            ->whereIn('status', $origins)
            ->update(['status' => $to] + $extra);

        return $this->db->affectedRows() === 1;
    }

    /**
     * Every status from which $to may be reached, derived from TRANSITIONS so the table stays the
     * single statement of the rule.
     *
     * @return list<string>
     */
    private static function origins_for(string $to): array
    {
        $origins = [];

        foreach (self::TRANSITIONS as $from => $destinations) {
            if (in_array($to, $destinations, true)) {
                $origins[] = $from;
            }
        }

        return $origins;
    }

    private function tableIsThere(): bool
    {
        if ($this->tableExists === null) {
            try {
                $this->tableExists = $this->db->tableExists($this->table);
            } catch (DatabaseException) {
                $this->tableExists = false;
            }
        }

        return $this->tableExists;
    }
}
