<?php

namespace App\Models;

use CodeIgniter\Model;
use DateTime;

/**
 * A presale campaign: the products a business sells in advance, at what price, delivered on which
 * days, and sold until when. Every presale belongs to one (D13).
 *
 * Every rule here is enforced on the server and nowhere else is trusted with it. The Due payment of
 * the register taught that lesson: its "a customer is required" check lives only in the view.
 *
 * PRICE (D14, D19, T14)
 *
 * When a product is added, its catalogue price is copied as base_price and the campaign stops
 * following the catalogue. The price a presale is sold at is, in this order:
 *
 *   1. the product's campaign_price, when it has one;
 *   2. otherwise base_price less the product's own discount_percent, when it has one (0 included);
 *   3. otherwise base_price less the campaign's discount_percent.
 *
 * effective_price() is the only place that order is written down.
 *
 * See docs/Tecnico/venta-anticipada.md sections 3 and 4.
 */
class Presale_campaign extends Model
{
    /**
     * Money is computed at scale 2, the scale of every money column in these tables. Never the ambient
     * bcmath scale, which Load_config sets from unrelated settings.
     */
    private const MONEY_SCALE = 2;

    protected $table            = 'presale_campaigns';
    protected $primaryKey       = 'campaign_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes   = false;

    /**
     * Must match Migration_AddPresales::WRITABLE_COLUMNS_CAMPAIGNS; a test compares them.
     */
    protected $allowedFields = [
        'name',
        'sale_starts',
        'sale_ends',
        'discount_percent',
        'min_initial_percent',
        'active',
        'deleted',
        'created_by',
        'created_at',
    ];

    /**
     * The campaign, or null when it does not exist or was deleted.
     */
    public function get_info(int $campaign_id): ?array
    {
        $row = $this->db->table($this->table)
            ->where('campaign_id', $campaign_id)
            ->where('deleted', 0)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /**
     * Every campaign that was not deleted, the newest first.
     */
    public function get_all(): array
    {
        return $this->db->table($this->table)
            ->where('deleted', 0)
            ->orderBy('sale_starts', 'desc')
            ->orderBy('campaign_id', 'desc')
            ->get()->getResultArray();
    }

    /**
     * Campaigns that were not deleted, the newest first, each with how many products and delivery
     * dates it has (`products`, `dates`): the list's rows in one query, instead of reading every
     * campaign's products and dates to count them. One campaign when $campaign_id is given.
     *
     * Products are counted as get_items() lists them: rows whose item still exists in `items`.
     *
     * @return list<array<string, mixed>>
     */
    public function get_with_counts(?int $campaign_id = null): array
    {
        // Raw SQL, bound, like Presales::search_sql(): the query builder would try to track the
        // aliases of the grouped subqueries and prefix their columns.
        $campaigns = $this->db->prefixTable($this->table);
        $items     = $this->db->prefixTable('presale_campaign_items');
        $catalogue = $this->db->prefixTable('items');
        $dates     = $this->db->prefixTable('presale_campaign_dates');

        $where = 'c.deleted = 0';
        $binds = [];

        if ($campaign_id !== null) {
            $where .= ' AND c.campaign_id = ?';
            $binds[] = $campaign_id;
        }

        $rows = $this->db->query(
            "SELECT c.*, COALESCE(p.products, 0) AS products, COALESCE(d.dates, 0) AS dates
                FROM {$campaigns} AS c
                LEFT JOIN (SELECT ci.campaign_id, COUNT(*) AS products
                        FROM {$items} AS ci JOIN {$catalogue} AS i ON i.item_id = ci.item_id
                        GROUP BY ci.campaign_id) AS p ON p.campaign_id = c.campaign_id
                LEFT JOIN (SELECT cd.campaign_id, COUNT(*) AS dates
                        FROM {$dates} AS cd
                        GROUP BY cd.campaign_id) AS d ON d.campaign_id = c.campaign_id
                WHERE {$where}
                ORDER BY c.sale_starts DESC, c.campaign_id DESC",
            $binds,
        )->getResultArray();

        foreach ($rows as &$row) {
            $row['products'] = (int) $row['products'];
            $row['dates']    = (int) $row['dates'];
        }

        return $rows;
    }

    /**
     * The campaigns a presale can be registered in on $today (Y-m-d): active, not deleted, and inside
     * their selling window, both ends included.
     */
    public function get_selling(string $today): array
    {
        return $this->db->table($this->table)
            ->where('deleted', 0)
            ->where('active', 1)
            ->where('sale_starts <=', $today)
            ->where('sale_ends >=', $today)
            ->orderBy('name', 'asc')
            ->get()->getResultArray();
    }

    /**
     * Whether a presale can be registered in this campaign on $today. Instalments, deliveries and
     * cancellations never ask this: closing the window only stops new presales (D16).
     */
    public static function is_selling(array $campaign, string $today): bool
    {
        return (int) $campaign['active'] === 1
            && (int) ($campaign['deleted'] ?? 0) === 0
            && $campaign['sale_starts'] <= $today
            && $today <= $campaign['sale_ends'];
    }

    /**
     * Creates or updates a campaign and returns its id, or a language key explaining the refusal.
     *
     * @param array{name?: string, sale_starts?: string, sale_ends?: string, discount_percent?: string, min_initial_percent?: string, active?: bool|int} $data
     */
    public function save_campaign(array $data, int $campaign_id, int $employee_id): int|string
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 100) {
            return 'Presales.campaign_name_required';
        }

        $starts = (string) ($data['sale_starts'] ?? '');
        $ends   = (string) ($data['sale_ends'] ?? '');

        if (! self::is_date($starts) || ! self::is_date($ends) || $starts > $ends) {
            return 'Presales.campaign_dates_invalid';
        }

        $discount = self::percent_or_null($data['discount_percent'] ?? '0');
        $minimum  = self::percent_or_null($data['min_initial_percent'] ?? '0');

        if ($discount === null || $minimum === null) {
            return 'Presales.percent_invalid';
        }

        $row = [
            'name'                => $name,
            'sale_starts'         => $starts,
            'sale_ends'           => $ends,
            'discount_percent'    => $discount,
            'min_initial_percent' => $minimum,
            'active'              => empty($data['active']) ? 0 : 1,
        ];

        if ($campaign_id === NEW_ENTRY) {
            $row['created_by'] = $employee_id;
            $row['created_at'] = date('Y-m-d H:i:s');
            $row['deleted']    = 0;

            if (! $this->db->table($this->table)->insert($row)) {
                return 'Presales.save_failed';
            }

            return (int) $this->db->insertID();
        }

        if ($this->get_info($campaign_id) === null) {
            return 'Presales.campaign_not_found';
        }

        $this->db->table($this->table)->where('campaign_id', $campaign_id)->update($row);

        return $campaign_id;
    }

    /**
     * Removes a campaign, logically. Refused while it has presales: they point at it.
     *
     * With no presale pointing at it, its products and delivery dates go with it, in the same
     * transaction: nothing reads them again, and a product row left behind would keep saying the
     * product is in a campaign (Presale::item_in_use() also ignores deleted campaigns, for the ones
     * deleted before this was done).
     */
    public function delete_campaign(int $campaign_id): bool
    {
        if ($this->db->table('presales')->where('campaign_id', $campaign_id)->countAllResults() > 0) {
            return false;
        }

        $this->db->transStart();
        $this->db->table($this->table)->where('campaign_id', $campaign_id)->update(['deleted' => 1]);
        $this->db->table('presale_campaign_items')->where('campaign_id', $campaign_id)->delete();
        $this->db->table('presale_campaign_dates')->where('campaign_id', $campaign_id)->delete();
        $this->db->transComplete();

        return $this->db->transStatus();
    }

    // ---------------------------------------------------------------------------------------------
    // Products
    // ---------------------------------------------------------------------------------------------

    /**
     * The campaign's products with their name, catalogue data and effective price.
     *
     * @return list<array<string, mixed>>
     */
    public function get_items(int $campaign_id): array
    {
        $campaign = $this->get_info($campaign_id);

        if ($campaign === null) {
            return [];
        }

        $rows = $this->db->table('presale_campaign_items AS ci')
            ->select('ci.*, items.name, items.item_number, items.unit_price AS catalogue_price, items.unit_of_measure, items.item_type, items.deleted AS item_deleted')
            ->join('items', 'items.item_id = ci.item_id')
            ->where('ci.campaign_id', $campaign_id)
            ->orderBy('items.name', 'asc')
            ->get()->getResultArray();

        foreach ($rows as &$row) {
            $row['effective_price'] = self::effective_price($row, $campaign);
        }

        return $rows;
    }

    /**
     * One product of the campaign with its effective price, or null when it is not in the campaign.
     */
    public function get_item(int $campaign_id, int $item_id): ?array
    {
        foreach ($this->get_items($campaign_id) as $row) {
            if ((int) $row['item_id'] === $item_id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Adds a product of the business's catalogue to the campaign, copying its catalogue price as the
     * starting point (D14, D24). A product that does not exist or was deleted is refused: a product
     * that is missing has to be created in Items first.
     */
    public function add_item(int $campaign_id, int $item_id): bool|string
    {
        if ($this->get_info($campaign_id) === null) {
            return 'Presales.campaign_not_found';
        }

        $item = $this->db->table('items')->where('item_id', $item_id)->where('deleted', 0)->get()->getRowArray();

        if ($item === null) {
            return 'Presales.item_not_in_catalogue';
        }

        $exists = $this->db->table('presale_campaign_items')
            ->where('campaign_id', $campaign_id)->where('item_id', $item_id)
            ->countAllResults() > 0;

        if ($exists) {
            return true;
        }

        return $this->db->table('presale_campaign_items')->insert([
            'campaign_id'      => $campaign_id,
            'item_id'          => $item_id,
            'base_price'       => self::money((string) $item['unit_price']),
            'campaign_price'   => null,
            'discount_percent' => null,
        ]);
    }

    /**
     * Sets a product's own price or discount. An empty string means "none": an empty campaign_price
     * falls back to the discounts, an empty discount_percent inherits the campaign's.
     */
    public function update_item(int $campaign_id, int $item_id, ?string $campaign_price, ?string $discount_percent): bool|string
    {
        $price = null;

        if ($campaign_price !== null && trim($campaign_price) !== '') {
            $campaign_price = trim($campaign_price);

            if (! self::is_decimal($campaign_price) || bccomp($campaign_price, '0', 2) < 0) {
                return 'Presales.price_invalid';
            }

            $price = self::money($campaign_price);
        }

        $discount = null;

        if ($discount_percent !== null && trim($discount_percent) !== '') {
            $discount = self::percent_or_null($discount_percent);

            if ($discount === null) {
                return 'Presales.percent_invalid';
            }
        }

        return $this->db->table('presale_campaign_items')
            ->where('campaign_id', $campaign_id)->where('item_id', $item_id)
            ->update(['campaign_price' => $price, 'discount_percent' => $discount]);
    }

    /**
     * Takes a product out of the campaign. Refused while an open presale of this campaign has it:
     * that presale would be left with a product nobody can price or deliver again.
     */
    public function remove_item(int $campaign_id, int $item_id): bool
    {
        $in_use = $this->db->table('presale_items AS pi')
            ->join('presales AS p', 'p.presale_id = pi.presale_id')
            ->where('p.campaign_id', $campaign_id)
            ->where('p.status', Presale::STATUS_OPEN)
            ->where('pi.item_id', $item_id)
            ->countAllResults() > 0;

        if ($in_use) {
            return false;
        }

        return $this->db->table('presale_campaign_items')
            ->where('campaign_id', $campaign_id)->where('item_id', $item_id)
            ->delete();
    }

    /**
     * The price a product of a campaign is sold at. See the class docblock for the order.
     *
     * @param array{base_price: string, campaign_price?: string|null, discount_percent?: string|null} $item
     * @param array{discount_percent: string}                                                         $campaign
     */
    public static function effective_price(array $item, array $campaign): string
    {
        if (($item['campaign_price'] ?? null) !== null) {
            return self::money((string) $item['campaign_price']);
        }

        $percent = ($item['discount_percent'] ?? null) !== null
            ? (string) $item['discount_percent']
            : (string) $campaign['discount_percent'];

        $factor = bcsub('1', bcdiv($percent, '100', 6), 6);

        return self::round_money(bcmul((string) $item['base_price'], $factor, 6));
    }

    // ---------------------------------------------------------------------------------------------
    // Delivery dates
    // ---------------------------------------------------------------------------------------------

    /**
     * @return list<array{date_id: int|string, campaign_id: int|string, delivery_date: string}>
     */
    public function get_dates(int $campaign_id): array
    {
        return $this->db->table('presale_campaign_dates')
            ->where('campaign_id', $campaign_id)
            ->orderBy('delivery_date', 'asc')
            ->get()->getResultArray();
    }

    /**
     * The date row when it belongs to this campaign, or null.
     */
    public function get_date(int $campaign_id, int $date_id): ?array
    {
        $row = $this->db->table('presale_campaign_dates')
            ->where('campaign_id', $campaign_id)->where('date_id', $date_id)
            ->get()->getRowArray();

        return $row ?: null;
    }

    public function add_date(int $campaign_id, string $delivery_date): bool|string
    {
        if (! self::is_date($delivery_date)) {
            return 'Presales.date_invalid';
        }

        if ($this->get_info($campaign_id) === null) {
            return 'Presales.campaign_not_found';
        }

        $exists = $this->db->table('presale_campaign_dates')
            ->where('campaign_id', $campaign_id)->where('delivery_date', $delivery_date)
            ->countAllResults() > 0;

        return $exists || $this->db->table('presale_campaign_dates')->insert([
            'campaign_id'   => $campaign_id,
            'delivery_date' => $delivery_date,
        ]);
    }

    /**
     * Refused while any presale, of any status, was agreed for this date: it is part of what was
     * agreed with that customer.
     */
    public function remove_date(int $campaign_id, int $date_id): bool
    {
        if ($this->db->table('presales')->where('delivery_date_id', $date_id)->countAllResults() > 0) {
            return false;
        }

        return $this->db->table('presale_campaign_dates')
            ->where('campaign_id', $campaign_id)->where('date_id', $date_id)
            ->delete();
    }

    /**
     * A plain decimal number: digits, an optional point and more digits, an optional leading minus.
     *
     * Stricter than is_numeric() on purpose: is_numeric() accepts "1e3" and " 5", and bcmath throws a
     * ValueError on both. Amounts reach the models already normalised by parse_decimals().
     */
    public static function is_decimal(string $value): bool
    {
        return preg_match('/^-?\d+(\.\d+)?$/', $value) === 1;
    }

    public static function is_date(string $value): bool
    {
        $date = DateTime::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
     * A percentage between 0 and 100 with two decimals, or null when it is not one.
     */
    private static function percent_or_null(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            $value = '0';
        }

        if (! self::is_decimal($value) || bccomp($value, '0', 2) < 0 || bccomp($value, '100', 2) > 0) {
            return null;
        }

        return bcadd($value, '0', 2);
    }

    private static function money(string $value): string
    {
        return bcadd($value, '0', self::MONEY_SCALE);
    }

    /**
     * Half-up rounding to the business's currency decimals (totals_decimals(), never more than the
     * two the columns hold), returned at scale 2.
     *
     * The business's decimals and not a fixed 2: the presale total has to be the very amount the
     * register charges on delivery, and the register rounds to totals_decimals(). A peso business has
     * zero decimals; a price of 33,333.33 agreed here would never match the 33,333 the till asks for.
     *
     * bcmath truncates, so the half is added by hand, on the side of the sign.
     */
    public static function round_money(string $value): string
    {
        helper('locale');

        $decimals = min(self::MONEY_SCALE, max(0, totals_decimals()));
        $half     = $decimals === 0 ? '0.5' : '0.' . str_repeat('0', $decimals) . '5';

        $rounded = bccomp($value, '0', 6) < 0
            ? bcsub($value, $half, $decimals)
            : bcadd($value, $half, $decimals);

        return bcadd($rounded, '0', self::MONEY_SCALE);
    }
}
