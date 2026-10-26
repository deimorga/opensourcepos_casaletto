<?php

namespace App\Models;

use CodeIgniter\Model;
use Config\OSPOS;

/**
 * The outbox of the e-mails a presale's customer receives (docs/Tecnico/preventas-cartera-y-avisos.md
 * §4.3 to §4.5): one row per thing to tell, written in the same transaction as the fact (K7) and sent
 * later by `php spark presales:notify`, so the till never waits for a mail server (K3).
 *
 * A row is never duplicated: UNIQUE (presale_id, kind, ref_id) and INSERT IGNORE (K6). Resending
 * reuses the row.
 *
 *   pending  ->  sending  ->  sent
 *                         ->  pending again, later (BACKOFF_MINUTES), up to MAX_ATTEMPTS
 *                         ->  failed
 *   skipped_no_email      the customer has no valid address; the screen says so (A4)
 *   skipped_no_transport  the environment names no mail server (Email_lib::platform() is null)
 *   skipped_paid          a reminder whose instalment was paid, or whose presale closed, before it went out
 */
class Presale_notification extends Model
{
    public const KIND_CREATED                = 'created';
    public const KIND_PAYMENT                = 'payment';
    public const KIND_CANCELED               = 'canceled';
    public const KIND_DELIVERED              = 'delivered';
    public const KIND_REMINDER_BEFORE        = 'reminder_before';
    public const KIND_REMINDER_LATE          = 'reminder_late';
    public const STATUS_PENDING              = 'pending';
    public const STATUS_SENDING              = 'sending';
    public const STATUS_SENT                 = 'sent';
    public const STATUS_FAILED               = 'failed';
    public const STATUS_SKIPPED_NO_EMAIL     = 'skipped_no_email';
    public const STATUS_SKIPPED_NO_TRANSPORT = 'skipped_no_transport';
    public const STATUS_SKIPPED_PAID         = 'skipped_paid';

    /**
     * Minutes to wait after the 1st, 2nd, 3rd and 4th failure. The 5th failure is final.
     */
    public const BACKOFF_MINUTES = [1, 5, 15, 60];

    public const MAX_ATTEMPTS = 5;

    /**
     * A row left in `sending` longer than this belongs to a run that died: it goes back to pending.
     */
    public const STALE_SENDING_MINUTES = 10;

    protected $table            = 'presale_notifications';
    protected $primaryKey       = 'notification_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes   = false;

    /**
     * Must match Migration_AddPresaleNotifications::WRITABLE_COLUMNS; a test compares them.
     */
    protected $allowedFields = [
        'presale_id',
        'kind',
        'ref_id',
        'to_email',
        'status',
        'attempts',
        'last_error',
        'next_attempt_at',
        'created_at',
        'sent_at',
        'employee_id',
    ];

    /**
     * Whether this business e-mails its presale customers. Absent reads as off.
     */
    public static function is_on(): bool
    {
        return (string) (config(OSPOS::class)->settings['presales_notify_email'] ?? '0') === '1';
    }

    /**
     * Queues an e-mail about a presale. Called inside the transaction of the fact it reports (K7), on
     * the same connection, so a fact rolled back takes its e-mail with it.
     *
     * Returns the status the row got -- pending, or skipped_no_email when the customer has no valid
     * address -- or null when nothing was queued: the switch is off, or the row already exists.
     */
    public function enqueue(int $presale_id, string $kind, int $ref_id, ?int $employee_id): ?string
    {
        if (! self::is_on() || ! $this->db->tableExists($this->table)) {
            return null;
        }

        $email  = $this->customer_email($presale_id);
        $status = $email === null ? self::STATUS_SKIPPED_NO_EMAIL : self::STATUS_PENDING;
        $now    = date('Y-m-d H:i:s');

        $this->db->table($this->table)->ignore(true)->insert([
            'presale_id'      => $presale_id,
            'kind'            => $kind,
            'ref_id'          => $ref_id,
            'to_email'        => $email,
            'status'          => $status,
            'attempts'        => 0,
            'last_error'      => null,
            'next_attempt_at' => $now,
            'created_at'      => $now,
            'sent_at'         => null,
            'employee_id'     => $employee_id,
        ]);

        return $this->db->affectedRows() === 1 ? $status : null;
    }

    /**
     * The status of one e-mail, or null when there is none -- for the "no address" notice on screen.
     */
    public function status_of(int $presale_id, string $kind, int $ref_id): ?string
    {
        if (! $this->db->tableExists($this->table)) {
            return null;
        }

        $row = $this->db->table($this->table)
            ->select('status')
            ->where(['presale_id' => $presale_id, 'kind' => $kind, 'ref_id' => $ref_id])
            ->get()->getRowArray();

        return $row['status'] ?? null;
    }

    /**
     * The e-mails of one presale, oldest first, for its «Correos» tab.
     */
    public function get_for(int $presale_id): array
    {
        if (! $this->db->tableExists($this->table)) {
            return [];
        }

        return $this->db->table($this->table)
            ->where('presale_id', $presale_id)
            ->orderBy('created_at', 'asc')
            ->orderBy('notification_id', 'asc')
            ->get()->getResultArray();
    }

    /**
     * Puts an e-mail back in the queue, to the customer's CURRENT address: the usual reason to resend
     * is that somebody has just added or fixed it. Same row, attempts back to zero.
     *
     * Returns the new status, or null when there is no such e-mail for that presale.
     */
    public function resend(int $notification_id, int $presale_id, int $employee_id): ?string
    {
        $row = $this->db->table($this->table)
            ->where(['notification_id' => $notification_id, 'presale_id' => $presale_id])
            ->get()->getRowArray();

        if ($row === null) {
            return null;
        }

        $email  = $this->customer_email($presale_id);
        $status = $email === null ? self::STATUS_SKIPPED_NO_EMAIL : self::STATUS_PENDING;

        $this->db->table($this->table)->where('notification_id', $notification_id)->update([
            'to_email'        => $email,
            'status'          => $status,
            'attempts'        => 0,
            'last_error'      => null,
            'next_attempt_at' => date('Y-m-d H:i:s'),
            'sent_at'         => null,
            'employee_id'     => $employee_id,
        ]);

        return $status;
    }

    /**
     * Takes up to $limit e-mails that are due, marking each `sending` with a guarded UPDATE: two runs
     * that overlap never send the same row twice. A row stuck in `sending` from a run that died is
     * released first.
     *
     * @return list<array<string, mixed>>
     */
    public function claim(int $limit): array
    {
        $now = date('Y-m-d H:i:s');

        // While sending, next_attempt_at holds the moment the row was claimed.
        $this->db->table($this->table)
            ->where('status', self::STATUS_SENDING)
            ->where('next_attempt_at <', date('Y-m-d H:i:s', strtotime('-' . self::STALE_SENDING_MINUTES . ' minutes')))
            ->update(['status' => self::STATUS_PENDING]);

        $candidates = $this->db->table($this->table)
            ->where('status', self::STATUS_PENDING)
            ->where('next_attempt_at <=', $now)
            ->orderBy('notification_id', 'asc')
            ->limit($limit)
            ->get()->getResultArray();

        $claimed = [];

        foreach ($candidates as $row) {
            $this->db->table($this->table)
                ->where('notification_id', $row['notification_id'])
                ->where('status', self::STATUS_PENDING)
                ->update(['status' => self::STATUS_SENDING, 'next_attempt_at' => $now]);

            if ($this->db->affectedRows() === 1) {
                $claimed[] = $row;
            }
        }

        return $claimed;
    }

    public function mark_sent(int $notification_id, int $attempts): void
    {
        $this->db->table($this->table)->where('notification_id', $notification_id)->update([
            'status'     => self::STATUS_SENT,
            'attempts'   => $attempts + 1,
            'last_error' => null,
            'sent_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * A failed attempt: back to pending after the wait for this attempt, or failed for good after the
     * last one. Returns the status left.
     */
    public function mark_failed_attempt(int $notification_id, int $attempts, string $error): string
    {
        $attempts++;
        $final  = $attempts >= self::MAX_ATTEMPTS;
        $status = $final ? self::STATUS_FAILED : self::STATUS_PENDING;
        $wait   = self::BACKOFF_MINUTES[min($attempts, count(self::BACKOFF_MINUTES)) - 1];

        $this->db->table($this->table)->where('notification_id', $notification_id)->update([
            'status'          => $status,
            'attempts'        => $attempts,
            'last_error'      => self::short_error($error),
            'next_attempt_at' => $final ? date('Y-m-d H:i:s') : date('Y-m-d H:i:s', strtotime('+' . $wait . ' minutes')),
        ]);

        return $status;
    }

    /**
     * An e-mail that will not be attempted: no address, or no mail server in this environment.
     */
    public function mark_skipped(int $notification_id, string $status, ?string $error = null): void
    {
        $this->db->table($this->table)->where('notification_id', $notification_id)->update([
            'status'     => $status,
            'last_error' => $error === null ? null : self::short_error($error),
        ]);
    }

    /**
     * Queues the instalment reminders due on $today (S1): one `reminder_before` for every instalment
     * falling due in $days_before days, and one `reminder_late` for every instalment that fell due
     * yesterday, in both cases only when the payments do not cover it yet. Only open presales.
     *
     * Running it twice on the same day queues nothing new (K6). Returns how many were queued.
     */
    public function enqueue_reminders(string $today, int $days_before, bool $late): int
    {
        if (! self::is_on()) {
            return 0;
        }

        $targets = [];

        if ($days_before > 0) {
            $targets[date('Y-m-d', strtotime($today . ' +' . $days_before . ' days'))] = self::KIND_REMINDER_BEFORE;
        }

        if ($late) {
            $targets[date('Y-m-d', strtotime($today . ' -1 day'))] = self::KIND_REMINDER_LATE;
        }

        if ($targets === []) {
            return 0;
        }

        $presale_ids = array_map('intval', array_column(
            $this->db->table('presale_installments AS pi')
                ->distinct()
                ->select('pi.presale_id')
                ->join('presales AS p', 'p.presale_id = pi.presale_id')
                ->where('p.status', Presale::STATUS_OPEN)
                ->whereIn('pi.due_date', array_keys($targets))
                ->get()->getResultArray(),
            'presale_id',
        ));

        if ($presale_ids === []) {
            return 0;
        }

        $payments = model(Presale_payment::class);
        $queued   = 0;

        foreach ($presale_ids as $presale_id) {
            $installments = $this->db->table('presale_installments')
                ->where('presale_id', $presale_id)
                ->get()->getResultArray();

            foreach (self::uncovered($installments, $payments->get_paid($presale_id)) as $installment) {
                $kind = $targets[$installment['due_date']] ?? null;

                if ($kind !== null && $this->enqueue($presale_id, $kind, (int) $installment['installment_id'], null) !== null) {
                    $queued++;
                }
            }
        }

        return $queued;
    }

    /**
     * The instalments the payments do not fully cover, each with what is still missing of it. The
     * accumulation is Presale::coverage()'s, the one derive() and the portfolio read: one rule for
     * "this instalment is paid".
     *
     * @param list<array<string, mixed>> $installments
     *
     * @return list<array<string, mixed>> the rows Presale::coverage() returns, only those with something missing
     */
    public static function uncovered(array $installments, string $paid): array
    {
        return array_values(array_filter(
            Presale::coverage($paid, $installments),
            static fn (array $installment): bool => bccomp((string) $installment['missing'], '0', 2) > 0,
        ));
    }

    /**
     * The customer's address when it is a valid one, else null. FILTER_VALIDATE_EMAIL, never a
     * FILTER_SANITIZE_* filter: those turn accented letters into HTML entities.
     */
    private function customer_email(int $presale_id): ?string
    {
        $row = $this->db->table('presales AS p')
            ->select('people.email')
            ->join('people', 'people.person_id = p.customer_id')
            ->where('p.presale_id', $presale_id)
            ->get()->getRowArray();

        $email = trim((string) ($row['email'] ?? ''));

        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
    }

    private static function short_error(string $error): string
    {
        $error = trim((string) preg_replace('/\s+/', ' ', $error));

        return mb_substr($error, 0, 500);
    }
}
