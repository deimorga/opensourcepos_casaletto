<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * What was ordered on an order ticket ("comanda"), one row per dish, and whether the kitchen already
 * has it.
 *
 * THE TICKET OWNS ITS LINES; sales_items IS NOT THE SOURCE
 *
 * sales_items cannot carry this: Sale::save_value() deletes and reinserts every sales_items row of an
 * open account on each keystroke the cashier makes, and sales_items.line is renumbered every time the
 * cashier switches tab. A "sent to kitchen" mark stored there is gone by the next dish, and the
 * kitchen receives the same order twice. So the identity of a line is order_ticket_line_id, and
 * sales_items is only the billing projection of these rows. If the cashier edits the sale, these
 * lines do not change: the difference between what was ordered and what was charged is information,
 * not an error.
 *
 * THERE IS NO DELETE IN THIS MODEL, AND THERE MUST NEVER BE ONE
 *
 * A line that went to the kitchen is a fact: the kitchen already acted on it, and there may be a dish
 * on the pass. Removing a line is therefore a logical void (status = voided), and touching a line the
 * kitchen already has raises changed_after_send (D9: it is allowed, and it is announced). Deleting a
 * row would erase exactly the evidence D9 asks to keep.
 *
 * Quantities and prices are decimal strings compared with bcmath at the column's scale, never floats:
 * a float cast to string can come out as "1.0E-6", and half a kilo has to stay half a kilo.
 *
 * Unlike Item_price_history::record(), nothing here swallows exceptions: this model is used from the
 * order ticket's own screens, where a failure must be seen, not from the register's sale path.
 *
 * See docs/Tecnico/comandas-y-cuenta-abierta.md sections 2, 3.1, 3.2, 4.2, 4.4 and 4.5, and
 * app/Database/Migrations/20260923000000_AddOrderTickets.php.
 */
class Order_ticket_line extends Model
{
    /**
     * Stable codes, never labels: the wording lives in the language files and is resolved at display
     * time, so switching locale cannot change what the data means.
     *
     * pending -> sent happens only through assign_to_round(). Either of them -> voided happens only
     * through void_line(). Nothing ever leaves voided.
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT   = 'sent';
    public const STATUS_VOIDED = 'voided';

    /**
     * The scales of the columns (DECIMAL(15,3) and DECIMAL(15,2)). Validation compares at these and
     * not at the ambient bcmath scale, which is derived from the tenant's currency settings and would
     * silently drop the third decimal of a weight.
     */
    private const QUANTITY_SCALE = 3;

    private const PRICE_SCALE = 2;

    /**
     * Both text columns are VARCHAR(255).
     */
    private const TEXT_LENGTH = 255;

    /**
     * The only keys edit_line() will look at. Price, round and status are deliberately absent: the
     * price is a copy taken at capture time, and the round and status move only through
     * assign_to_round() and void_line(), whose conditions are what keep the kitchen from receiving a
     * line twice.
     */
    private const EDITABLE_KEYS = ['quantity', 'kitchen_note'];

    /**
     * What edit_line_seen() answers. Stable codes, never labels, for the same reason as the statuses:
     * the controller maps them to a message, and the wording can change without the meaning moving.
     */
    public const EDIT_OK = 'ok';

    /**
     * Someone changed the line since this screen read it. Nothing was written.
     */
    public const EDIT_CONFLICT = 'conflict';

    /**
     * The line is missing or voided, or the input was invalid. Nothing was written.
     */
    public const EDIT_REFUSED = 'refused';

    protected $table            = 'order_ticket_lines';
    protected $primaryKey       = 'order_ticket_line_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    /**
     * Must stay identical to Migration_AddOrderTickets::WRITABLE_COLUMNS_LINES plus
     * Migration_AddOrderTicketLineBilling::ADDED_COLUMNS -- CodeIgniter drops any field missing from
     * here without raising anything, and this project has already lost data to that twice. There is
     * a test that compares them.
     */
    protected $allowedFields = [
        'order_ticket_id',
        'item_id',
        'item_name',
        'quantity',
        'unit_price',
        'kitchen_note',
        'round_id',
        'status',
        'changed_after_send',
        'captured_by',
        'captured_at',
        // Added by 20260923030000_AddOrderTicketLineBilling -- see that migration for why the
        // register pulls lines instead of the phone writing sales_items.
        'billed_at',
        'changed_after_billed',
    ];

    // captured_at is written by hand. CI4's automatic timestamps would insist on created_at and
    // updated_at columns this table does not have.
    protected $useTimestamps = false;

    /**
     * Records one ordered dish, not yet sent to the kitchen.
     *
     * item_name and unit_price are COPIED here, not looked up when read. If the item is renamed or
     * repriced tomorrow -- and repricing from the till is a shipped feature, see item_price_history --
     * the printed ticket and the screen still say what the waiter actually ordered. A document that
     * is reinterpreted every time it is read is not a document.
     *
     * @param string $quantity   decimal string strictly greater than zero, e.g. "0.500"
     * @param string $unit_price decimal string, zero or more (a gift line is a real line at zero)
     *
     * @return int the new order_ticket_line_id, or 0 when the input was refused and nothing was written
     */
    public function add_line(
        int $order_ticket_id,
        int $item_id,
        string $item_name,
        string $quantity,
        string $unit_price,
        string $kitchen_note,
        int $captured_by,
    ): int {
        $quantity   = trim($quantity);
        $unit_price = trim($unit_price);

        if (! self::is_valid_quantity($quantity) || ! self::is_valid_price($unit_price)) {
            return 0;
        }

        $id = $this->insert([
            'order_ticket_id'    => $order_ticket_id,
            'item_id'            => $item_id,
            'item_name'          => self::clean_text($item_name),
            'quantity'           => $quantity,
            'unit_price'         => $unit_price,
            'kitchen_note'       => self::clean_text($kitchen_note),
            'round_id'           => null,
            'status'             => self::STATUS_PENDING,
            'changed_after_send' => 0,
            'captured_by'        => $captured_by,
            'captured_at'        => date('Y-m-d H:i:s'),
        ]);

        return $id === false ? 0 : (int) $id;
    }

    /**
     * One line, whatever its status, or null when it does not exist.
     */
    public function get_info(int $order_ticket_line_id): ?array
    {
        $row = $this->find($order_ticket_line_id);

        return is_array($row) ? $row : null;
    }

    /**
     * Every line of a ticket in the order it was captured.
     *
     * Voided lines are included by default: the ticket's own screen shows them struck through, since
     * a dish the kitchen may already have cooked should not simply vanish from view.
     */
    public function get_lines(int $order_ticket_id, bool $include_voided = true): array
    {
        $this->where('order_ticket_id', $order_ticket_id);

        if (! $include_voided) {
            $this->where('status !=', self::STATUS_VOIDED);
        }

        return $this->orderBy('order_ticket_line_id', 'ASC')->findAll();
    }

    /**
     * What the next send to the kitchen would carry. This is D8: the second ticket prints only what
     * was added, and "what was added" is exactly the rows with no round that are still pending.
     *
     * The condition is the same one assign_to_round() writes with, on purpose: what the waiter is
     * shown as "about to be sent" and what actually gets sent must be the same set.
     */
    public function get_pending(int $order_ticket_id): array
    {
        return $this->where('order_ticket_id', $order_ticket_id)
            ->where('round_id', null)
            ->where('status', self::STATUS_PENDING)
            ->orderBy('order_ticket_line_id', 'ASC')
            ->findAll();
    }

    /**
     * Changes the quantity and/or the kitchen note of a line. Nothing else can be changed here.
     *
     * Any key other than quantity and kitchen_note is ignored (see EDITABLE_KEYS). The values go
     * through the same validation as add_line(), and one invalid value refuses the whole edit, so a
     * half-applied change never reaches the kitchen.
     *
     * A line that already has a round is in the kitchen. Editing it is allowed (D9) but raises
     * changed_after_send, and that is decided inside the UPDATE itself, from the row's own round_id,
     * rather than from a value read beforehand: otherwise a send landing between the read and the
     * write would leave a changed dish unflagged.
     *
     * The flag rises only when a value actually CHANGES. A phone resubmitting the same form -- a
     * double tap, a reload -- must not tell the kitchen that a dish was altered when nothing was.
     *
     * This is the unchecked edit: whoever writes last wins. The waiter's screen uses
     * edit_line_seen(), which refuses to overwrite a change the waiter never saw.
     *
     * @param array $changes only 'quantity' (decimal string) and 'kitchen_note' (string) are read
     *
     * @return bool false when the line is missing or voided, or nothing valid was given
     */
    public function edit_line(int $order_ticket_line_id, array $changes): bool
    {
        return $this->apply_edit($order_ticket_line_id, $changes, null) === self::EDIT_OK;
    }

    /**
     * The same edit as edit_line(), but only if the line still holds what the waiter's screen showed.
     *
     * Two waiters open the same ticket and both change the same dish. Without this, the second save
     * silently erases the first, and the first waiter walks away believing the kitchen has their
     * version. With it, the second save is refused as EDIT_CONFLICT and the screen can say so.
     *
     * OPTIMISTIC, NOT A LOCK. The check is part of the WHERE of the very UPDATE that writes, so the
     * database decides "still what you saw?" and "write" as one step. Reading the row first and
     * writing afterwards would reopen the window this closes. And no row is locked while a waiter
     * types: a lock held by a phone on a bad signal in a full restaurant is worse than a retry.
     *
     * The quantity is compared as a number, so a seen "2" matches a stored 2.000. The note is
     * compared byte for byte (BINARY): the column's collation ignores case, and "SIN CEBOLLA"
     * written over "sin cebolla" is a change the kitchen reads.
     *
     * The seen values are what the screen showed, and nothing else: they are not written, and the
     * same trimming as the stored note is applied to them, so a form that trims does not conflict
     * with itself.
     *
     * @param array{quantity?: string, kitchen_note?: string}    $changes
     * @param array{quantity: string, kitchen_note: string}|null $seen    what the screen showed when
     *                                                                    the waiter started editing;
     *                                                                    null = no check (edit_line())
     *
     * @return string one of EDIT_OK, EDIT_CONFLICT, EDIT_REFUSED
     */
    public function edit_line_seen(int $order_ticket_line_id, array $changes, ?array $seen): string
    {
        return $this->apply_edit($order_ticket_line_id, $changes, $seen);
    }

    /**
     * The one implementation behind edit_line() and edit_line_seen(), so the validation and, above
     * all, the ordering of the flag assignments exist exactly once.
     *
     * @param array|null $seen null skips the "still what the screen showed" condition entirely
     *
     * @return string one of EDIT_OK, EDIT_CONFLICT, EDIT_REFUSED
     */
    private function apply_edit(int $order_ticket_line_id, array $changes, ?array $seen): string
    {
        $set = [];

        foreach (self::EDITABLE_KEYS as $key) {
            if (! array_key_exists($key, $changes)) {
                continue;
            }

            $value = $changes[$key];

            if (! is_string($value)) {
                return self::EDIT_REFUSED;
            }

            if ($key === 'quantity') {
                $value = trim($value);

                if (! self::is_valid_quantity($value)) {
                    return self::EDIT_REFUSED;
                }
            } else {
                $value = self::clean_text($value);
            }

            $set[$key] = $value;
        }

        if ($set === []) {
            return self::EDIT_REFUSED;
        }

        // What the screen showed must be complete and well formed. A malformed "seen" cannot be
        // compared with anything, and skipping the check for it would silently turn a guarded edit
        // into an unguarded one.
        if ($seen !== null) {
            if (! isset($seen['quantity'], $seen['kitchen_note'])
                || ! is_string($seen['quantity'])
                || ! is_string($seen['kitchen_note'])) {
                return self::EDIT_REFUSED;
            }

            $seen_quantity = trim($seen['quantity']);
            $seen_note     = self::clean_text($seen['kitchen_note']);

            if (! self::is_valid_quantity($seen_quantity)) {
                return self::EDIT_REFUSED;
            }
        }

        // "Does any value differ from what the row holds now?" Quantity compares as a number, so
        // "0.5" against a stored 0.500 is not a change. The note compares byte for byte: the column's
        // collation is case-insensitive, and an edit is an edit.
        $differs = [];

        foreach ($set as $column => $value) {
            $differs[] = $column === 'quantity'
                ? 'quantity <> ' . $this->db->escape($value)
                : 'BINARY kitchen_note <> BINARY ' . $this->db->escape($value);
        }

        $builder = $this->db->table($this->table);

        // ORDER MATTERS, and it is the reason this assignment comes first. MySQL evaluates the SET
        // list of a single-table UPDATE from left to right, and a later assignment already sees the
        // values written by an earlier one. Were quantity rewritten first, the comparison below would
        // compare the new value with itself and never flag anything.
        $builder->set(
            'changed_after_send',
            'CASE WHEN round_id IS NULL THEN changed_after_send WHEN ' . implode(' OR ', $differs)
            . ' THEN 1 ELSE changed_after_send END',
            false,
        );
        // The same question for the register: a dish it already brought into the sale changed.
        // The cart is not touched behind the cashier's back; the flag makes the register say so.
        $builder->set(
            'changed_after_billed',
            'CASE WHEN billed_at IS NULL THEN changed_after_billed WHEN ' . implode(' OR ', $differs)
            . ' THEN 1 ELSE changed_after_billed END',
            false,
        );
        $builder->set($set);
        $builder->where('order_ticket_line_id', $order_ticket_line_id);
        $builder->where('status !=', self::STATUS_VOIDED);

        // The optimistic check. In the WHERE, never in the SET: the WHERE is evaluated against the
        // row as it was before this UPDATE, which is exactly the row the screen is claiming to have
        // seen. Same comparisons as above -- numeric quantity, byte-exact note.
        if ($seen !== null) {
            $builder->where('quantity = ' . $this->db->escape($seen_quantity), null, false);
            $builder->where('BINARY kitchen_note = BINARY ' . $this->db->escape($seen_note), null, false);
        }

        if (! $builder->update()) {
            return self::EDIT_REFUSED;
        }

        if ($this->db->affectedRows() > 0) {
            return self::EDIT_OK;
        }

        // Zero affected rows is ambiguous, and the row is read again ONLY to say which case it was --
        // never to decide what to write, which already happened (or not) above:
        //   - no such line, or a voided one: refused;
        //   - a line that already holds exactly the requested values: success. MySQL does not count
        //     an UPDATE that changes nothing, and the same form submitted twice (a double tap, a
        //     reload carrying the old "seen") must read as done, not as somebody else's change;
        //   - anything else, with a "seen" given: the line moved since the screen read it.
        // Without a "seen" the UPDATE matched any live line, so zero rows can only mean "already
        // these values"; that answer is kept as edit_line() always gave it.
        $line = $this->get_info($order_ticket_line_id);

        if ($line === null || $line['status'] === self::STATUS_VOIDED) {
            return self::EDIT_REFUSED;
        }

        if ($seen === null || self::holds_values($line, $set)) {
            return self::EDIT_OK;
        }

        return self::EDIT_CONFLICT;
    }

    /**
     * Whether a line already holds these values, compared the way the UPDATE compares them:
     * quantity as a number at the column's scale, the note byte for byte.
     *
     * @param array<string, string> $values validated, as built by apply_edit()
     */
    private static function holds_values(array $line, array $values): bool
    {
        foreach ($values as $column => $value) {
            $same = $column === 'quantity'
                ? bccomp((string) $line['quantity'], $value, self::QUANTITY_SCALE) === 0
                : (string) $line['kitchen_note'] === $value;

            if (! $same) {
                return false;
            }
        }

        return true;
    }

    /**
     * Voids a line. Logical only: the row stays, with status voided. Never a DELETE (see the class
     * docblock).
     *
     * If the line already had a round, the kitchen has it, so changed_after_send is raised in the
     * same UPDATE -- decided from the row's own round_id for the same reason as in edit_line().
     *
     * @return bool false when the line does not exist or was already voided
     */
    public function void_line(int $order_ticket_line_id): bool
    {
        $builder = $this->db->table($this->table);
        $builder->set('status', self::STATUS_VOIDED);
        $builder->set('changed_after_send', 'CASE WHEN round_id IS NULL THEN changed_after_send ELSE 1 END', false);
        // A voided dish the register already has is still in the cashier's cart until they remove it.
        $builder->set('changed_after_billed', 'CASE WHEN billed_at IS NULL THEN changed_after_billed ELSE 1 END', false);
        $builder->where('order_ticket_line_id', $order_ticket_line_id);
        $builder->where('status !=', self::STATUS_VOIDED);

        return $builder->update() && $this->db->affectedRows() > 0;
    }

    /**
     * Hands every pending line of a ticket to a round and marks it sent. Returns how many lines it
     * took; zero means there was nothing to send.
     *
     * ONE conditioned UPDATE, never read-then-write. The "round_id IS NULL AND status = pending" in
     * the WHERE is what makes two simultaneous sends (a double tap, or the waiter and the cashier at
     * once) unable to send the same line twice: the second UPDATE simply finds no rows left. Reading
     * the pending ids first and updating them by id would reopen exactly that window.
     *
     * This method does NOT open a transaction. The caller (the idempotent send) wraps creating the
     * round and calling this in one transaction, so that a round is never left without its lines and
     * lines never point at a round that was rolled back.
     *
     * @return int rows assigned
     */
    public function assign_to_round(int $order_ticket_id, int $round_id): int
    {
        // A round id below 1 cannot exist, and writing it would take the lines out of the pending set
        // (round_id no longer null) without them being in any real round: lost to the kitchen.
        if ($round_id < 1) {
            return 0;
        }

        $builder = $this->db->table($this->table);
        $builder->set('round_id', $round_id);
        $builder->set('status', self::STATUS_SENT);
        $builder->where('order_ticket_id', $order_ticket_id);
        $builder->where('round_id', null);
        $builder->where('status', self::STATUS_PENDING);

        if (! $builder->update()) {
            return 0;
        }

        return $this->db->affectedRows();
    }

    /**
     * The dishes of a ticket the register has not brought into the sale yet: never billed, not
     * voided. A line voided before it was ever billed is simply never charged -- nobody needs to be
     * told, it was never in the cart.
     */
    public function get_unbilled(int $order_ticket_id): array
    {
        return $this->where('order_ticket_id', $order_ticket_id)
            ->where('billed_at', null)
            ->where('status !=', self::STATUS_VOIDED)
            ->orderBy('order_ticket_line_id', 'ASC')
            ->findAll();
    }

    /**
     * Stamps lines as brought into the sale. Called by the register only AFTER the tab was saved
     * with them in it: stamped first and saved second, a failed save would leave dishes marked as
     * charged that the sale does not contain.
     *
     * Conditioned on billed_at IS NULL, so a line is stamped once and keeps its first time.
     *
     * @param list<int> $order_ticket_line_ids
     *
     * @return int rows stamped
     */
    public function mark_billed(array $order_ticket_line_ids): int
    {
        $ids = array_values(array_unique(array_map('intval', $order_ticket_line_ids)));

        if ($ids === []) {
            return 0;
        }

        $builder = $this->db->table($this->table);
        $builder->set('billed_at', date('Y-m-d H:i:s'));
        $builder->whereIn('order_ticket_line_id', $ids);
        $builder->where('billed_at', null);

        if (! $builder->update()) {
            return 0;
        }

        return $this->db->affectedRows();
    }

    /**
     * Dishes the waiter edited or voided after the register already had them (D9, the cashier's
     * side). The register shows these; it never applies them to the cart by itself.
     */
    public function get_billing_changes(int $order_ticket_id): array
    {
        return $this->where('order_ticket_id', $order_ticket_id)
            ->where('changed_after_billed', 1)
            ->orderBy('order_ticket_line_id', 'ASC')
            ->findAll();
    }

    /**
     * The cashier saw the changes and dealt with them. Clears the notice for this ticket.
     *
     * @return int lines acknowledged
     */
    public function acknowledge_billing_changes(int $order_ticket_id): int
    {
        $builder = $this->db->table($this->table);
        $builder->set('changed_after_billed', 0);
        $builder->where('order_ticket_id', $order_ticket_id);
        $builder->where('changed_after_billed', 1);

        if (! $builder->update()) {
            return 0;
        }

        return $this->db->affectedRows();
    }

    /**
     * For each ticket: how many dishes it carries and how many are still waiting to be sent.
     *
     * One grouped query for the whole list, not one per ticket: the list screen is what a waiter
     * reloads all evening, from a phone, on whatever signal the table has.
     *
     * Every requested id comes back, with zeros when it has no lines, so the caller never needs an
     * isset(). Voided lines are not dishes. "Pending" is the D8 condition, the same one get_pending()
     * and assign_to_round() use, so the number on the list is exactly what the next send would carry.
     *
     * The aliases are not `lines`/`pending` on purpose: LINES is a reserved word in MySQL.
     *
     * @param list<int> $order_ticket_ids
     *
     * @return array<int, array{dishes: int, pending: int}>
     */
    public function count_by_ticket(array $order_ticket_ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $order_ticket_ids)));

        if ($ids === []) {
            return [];
        }

        $rows = $this->db->table($this->table)
            ->select('order_ticket_id')
            ->select("SUM(CASE WHEN status <> 'voided' THEN 1 ELSE 0 END) AS dish_count", false)
            ->select("SUM(CASE WHEN status = 'pending' AND round_id IS NULL THEN 1 ELSE 0 END) AS pending_count", false)
            ->whereIn('order_ticket_id', $ids)
            ->groupBy('order_ticket_id')
            ->get()
            ->getResultArray();

        $counts = array_fill_keys($ids, ['dishes' => 0, 'pending' => 0]);

        foreach ($rows as $row) {
            $counts[(int) $row['order_ticket_id']] = [
                'dishes'  => (int) $row['dish_count'],
                'pending' => (int) $row['pending_count'],
            ];
        }

        return $counts;
    }

    /**
     * What one round carries -- what prints as "RONDA n". Voided lines are left out: this is what the
     * kitchen is asked to cook, and a reprint must not bring back a dish that was cancelled.
     */
    public function get_by_round(int $round_id): array
    {
        return $this->where('round_id', $round_id)
            ->where('status !=', self::STATUS_VOIDED)
            ->orderBy('order_ticket_line_id', 'ASC')
            ->findAll();
    }

    /**
     * A quantity as a waiter and a cook read it: "2", "0.5", "1.25" -- not the column's "2.000".
     *
     * Only for the order-ticket screens and the kitchen sheet. The register keeps its own format
     * (to_quantity_decimals()), and this deliberately does not go through it: that helper applies the
     * locale's grouping, and trimming zeros off "1,000.000" would read as one.
     */
    public static function display_quantity(string $quantity): string
    {
        $quantity = trim($quantity);

        if (! str_contains($quantity, '.')) {
            return $quantity;
        }

        return rtrim(rtrim($quantity, '0'), '.');
    }

    /**
     * A plain decimal strictly above zero that fits DECIMAL(15,3): digits, an optional fraction, no
     * sign, no grouping, no exponent. bccomp() throws a ValueError on anything else, and this is
     * reached straight from a form, so a stray letter must come back as a refusal and not a 500.
     *
     * The comparison is at the column's scale: "0.0001" would be stored as 0.000, so it is refused.
     */
    private static function is_valid_quantity(string $value): bool
    {
        return preg_match('/^\d{1,12}(?:\.\d+)?$/', $value) === 1
            && bccomp($value, '0', self::QUANTITY_SCALE) > 0;
    }

    /**
     * A plain decimal of zero or more that fits DECIMAL(15,2). A leading minus sign fails the pattern,
     * which is how a negative price is refused.
     */
    private static function is_valid_price(string $value): bool
    {
        return preg_match('/^\d{1,13}(?:\.\d+)?$/', $value) === 1
            && bccomp($value, '0', self::PRICE_SCALE) >= 0;
    }

    /**
     * Trimmed and cut to the column. mb_substr and not substr: a note full of accents cut at a byte
     * boundary would leave half a character, which the database refuses or mangles.
     */
    private static function clean_text(string $value): string
    {
        return mb_substr(trim($value), 0, self::TEXT_LENGTH);
    }
}
