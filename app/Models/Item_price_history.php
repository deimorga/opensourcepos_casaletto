<?php

namespace App\Models;

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Model;
use Throwable;

/**
 * Every price an item has ever had, and who set it.
 *
 * A cashier can fix an item's price from the till, and that price becomes the catalogue price for
 * every sale afterwards. The owner decided not to gate that behind a new permission: **this table is
 * the control that replaces it.** So the value of a row is not the number -- items.unit_price
 * already holds the number -- it is the answer to "who, when, from what, and why".
 *
 * Rows are written once and never edited. There is no `deleted` column and no update path: a record
 * that can be quietly rewritten is not a record.
 *
 * See docs/Tecnico/repreciar-articulo-desde-venta.md and
 * app/Database/Migrations/20260910000000_AddItemPriceHistory.php.
 */
class Item_price_history extends Model
{
    /**
     * Where a price change came from. Stable codes, never labels: the wording lives in the language
     * files and is resolved at display time, so switching locale cannot change what the data means.
     * Same reasoning as payment_type_code, cash_source and unit_of_measure.
     */
    public const SOURCE_SEED       = 'seed';        // the catalogue as it stood when history began
    public const SOURCE_SALE       = 'sale';        // repriced from the register, on completing a sale
    public const SOURCE_ITEM_FORM  = 'item_form';   // somebody edited the item
    public const SOURCE_BULK_EDIT  = 'bulk_edit';   // the bulk edit screen
    public const SOURCE_CSV_IMPORT = 'csv_import';  // the file upload
    public const SOURCE_UNKNOWN    = 'unknown';     // a write whose origin nobody declared

    public const ALLOWED_SOURCES = [
        self::SOURCE_SEED,
        self::SOURCE_SALE,
        self::SOURCE_ITEM_FORM,
        self::SOURCE_BULK_EDIT,
        self::SOURCE_CSV_IMPORT,
        self::SOURCE_UNKNOWN,
    ];

    protected $table            = 'item_price_history';
    protected $primaryKey       = 'price_history_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes   = false;

    /**
     * Must stay identical to Migration_AddItemPriceHistory::WRITABLE_COLUMNS -- CodeIgniter drops
     * any field missing from here without raising anything, and this project has already lost data
     * to that twice. There is a test that compares the two.
     */
    protected $allowedFields = [
        'item_id',
        'previous_price',
        'new_price',
        'changed_at',
        'employee_id',
        'source',
        'sale_id',
        'note',
    ];

    // changed_at is written by hand. CI4's automatic timestamps would insist on an updated_at as
    // well, and there is no such thing here: a row is written once.
    protected $useTimestamps = false;

    /**
     * Reduces anything at all to one of the codes the column accepts.
     *
     * Static and free of database access on purpose, the same as Item::normalize_unit_of_measure():
     * the write path below does not consult $allowedFields, so this is the only gate, and it has to
     * be provable without a live schema.
     *
     * An unrecognised value becomes 'unknown' rather than raising. Losing the origin of a price
     * change is a small loss; losing the change itself because its label was misspelt is a large
     * one.
     */
    public static function normalize_source(mixed $value): string
    {
        if (!is_string($value)) {
            return self::SOURCE_UNKNOWN;
        }

        $code = strtolower(trim($value));

        return in_array($code, self::ALLOWED_SOURCES, true) ? $code : self::SOURCE_UNKNOWN;
    }

    /**
     * Writes one row. **Never throws.**
     *
     * OBSERVAR NO PUEDE TUMBAR LO OBSERVADO.
     *
     * This runs from inside Item::save_value(), which is on the path of every price write in the
     * application -- including the one that happens right after a sale has been paid for and
     * committed. A history that can fail the thing it was only watching would be a history worth
     * removing. So a failure here becomes a critical log line and nothing else.
     *
     * The table may also be genuinely absent: deployments do not run migrations in this project
     * (AGENTS.md), so there is a window on every release where the code is live and the schema is
     * not. Without the guard, the first tenant whose migration is forgotten cannot close a sale.
     *
     * @param  int         $item_id        items.item_id
     * @param  string|null $previous_price null only when no earlier price is known -- a brand new
     *                                     item, or the seeded starting row. Never a guess.
     * @param  string      $new_price      as a decimal string; never a float, for the same reason
     *                                     quantities are strings everywhere in this system
     * @param  string      $source         one of the SOURCE_* constants
     * @param  int|null    $employee_id    people.person_id, or null when there is genuinely nobody
     * @param  int|null    $sale_id        only with SOURCE_SALE
     * @return int         the new id, or 0 when the row could not be written
     */
    public function record(
        int $item_id,
        ?string $previous_price,
        string $new_price,
        string $source,
        ?int $employee_id = null,
        ?int $sale_id = null,
        string $note = '',
    ): int {
        try {
            if (!$this->tableIsThere()) {
                return 0;
            }

            $this->insert([
                'item_id'        => $item_id,
                'previous_price' => $previous_price,
                'new_price'      => $new_price,
                'changed_at'     => date('Y-m-d H:i:s'),
                'employee_id'    => $employee_id,
                'source'         => self::normalize_source($source),
                'sale_id'        => $sale_id,
                'note'           => mb_substr($note, 0, 255),
            ]);
        } catch (Throwable $e) {
            log_message(
                'critical',
                'No se pudo registrar el cambio de precio del articulo ' . $item_id
                . ' a ' . $new_price . ' (origen ' . $source . '): ' . $e->getMessage()
            );

            return 0;
        }

        return (int) $this->getInsertID();
    }

    /**
     * The newest first, which is what the item's screen shows.
     *
     * Ordered by id as well and not only by changed_at: the column has one-second resolution, and
     * repricing two lines of the same sale lands both rows inside the same second. Without the tie
     * break the order would be whatever the database felt like.
     */
    public function get_history(int $item_id, int $limit = 50): array
    {
        if (!$this->tableIsThere()) {
            return [];
        }

        return $this->where('item_id', $item_id)
            ->orderBy('changed_at', 'DESC')
            ->orderBy('price_history_id', 'DESC')
            ->findAll($limit);
    }

    /**
     * What this item cost at a given moment, or null when the history does not reach back that far.
     *
     * Null is a real answer and must not be confused with zero: "the history does not know" and
     * "the item was free" are different facts, and only the seeded starting row keeps them apart.
     */
    public function get_price_at(int $item_id, string $at): ?string
    {
        if (!$this->tableIsThere()) {
            return null;
        }

        $row = $this->where('item_id', $item_id)
            ->where('changed_at <=', $at)
            ->orderBy('changed_at', 'DESC')
            ->orderBy('price_history_id', 'DESC')
            ->first();

        return $row === null ? null : (string) $row['new_price'];
    }

    /**
     * Everything one sale changed. This is what an owner opens when a price surprises them.
     */
    public function get_changes_for_sale(int $sale_id): array
    {
        if (!$this->tableIsThere()) {
            return [];
        }

        return $this->where('sale_id', $sale_id)
            ->orderBy('price_history_id', 'ASC')
            ->findAll();
    }

    /**
     * Whether the schema has caught up with the code yet.
     *
     * Cached for the request: this is asked on every price write, and the CSV import writes 1.184
     * of them inside a single transaction.
     */
    private ?bool $tableExists = null;

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
