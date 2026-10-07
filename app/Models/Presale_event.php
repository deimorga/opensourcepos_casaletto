<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * What happened to a presale, who did it and why. Insert only: nothing in the application updates or
 * deletes these rows, because a cancellation that gave money back, or a price somebody changed, is
 * exactly what the business will want to read later.
 */
class Presale_event extends Model
{
    public const CREATED   = 'created';
    public const PAYMENT   = 'payment';
    public const REFUND    = 'refund';
    public const DELIVERED = 'delivered';
    public const CANCELED  = 'canceled';

    protected $table            = 'presale_events';
    protected $primaryKey       = 'event_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes   = false;

    /**
     * Must match Migration_AddPresales::WRITABLE_COLUMNS_EVENTS; a test compares them.
     */
    protected $allowedFields = [
        'presale_id',
        'event_time',
        'employee_id',
        'event_type',
        'detail',
        'reason',
    ];

    /**
     * @param array<string, mixed> $detail What changed, before and after; stored as JSON.
     */
    public function log(int $presale_id, string $event_type, int $employee_id, array $detail = [], ?string $reason = null): bool
    {
        return $this->db->table($this->table)->insert([
            'presale_id'  => $presale_id,
            'event_time'  => date('Y-m-d H:i:s'),
            'employee_id' => $employee_id,
            'event_type'  => $event_type,
            'detail'      => $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE),
            'reason'      => $reason,
        ]);
    }

    /**
     * The history of one presale, oldest first.
     */
    public function get_for(int $presale_id): array
    {
        return $this->db->table($this->table)
            ->where('presale_id', $presale_id)
            ->orderBy('event_time', 'asc')
            ->orderBy('event_id', 'asc')
            ->get()->getResultArray();
    }
}
