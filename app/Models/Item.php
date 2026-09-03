<?php

namespace App\Models;

use CodeIgniter\Database\ResultInterface;
use CodeIgniter\Model;
use Config\OSPOS;
use ReflectionException;
use stdClass;
use Throwable;

/**
 * Item class
 *
 * @property inventory inventory
 * @property item_quantity item_quantity
 */
class Item extends Model
{

    /**
     * How an item is sold. Stable codes, never labels: the wording lives in the Items.* language
     * files and is resolved at display time, so switching locale cannot change what the data means.
     * Same reasoning as payment_type_code and cash_source.
     */
    public const UNIT_OF_MEASURE_UNIT = 'unit';
    public const UNIT_OF_MEASURE_KG = 'kg';

    /**
     * Order matters twice over: 'unit' is printed first so a dropdown that lost its selection falls
     * back to the code that changes nothing, and the sequence is what the selector renders.
     *
     * THERE IS DELIBERATELY NO POUND HERE, and adding one back would undo a decision, not extend a
     * list. The scale reports kilograms, the catalogue is priced per kilogram, and a business that
     * says "por libra" out loud still records half a pound as 0.227 kg. A second weighed unit buys
     * nothing and costs a great deal: nothing in this system converts, so an item put on the wrong
     * weighed unit is charged at 2.2 times the wrong price with no error anywhere -- the same class
     * of silent money loss the weighed-sales work exists to remove. That is not hypothetical: it
     * happened here, and only the catalogue's own two months of sales caught it.
     * See docs/Tecnico/venta-por-peso-y-hardware-de-caja.md section 3.3.
     */
    public const ALLOWED_UNITS_OF_MEASURE = [
        self::UNIT_OF_MEASURE_UNIT,
        self::UNIT_OF_MEASURE_KG
    ];

    /**
     * Codes that mean "this item is priced by what it weighs, so ask for a weight".
     *
     * A list of one, and kept as a list on purpose: the register asks this question of a cart line,
     * not of a particular unit, so every caller already reads it as a set. What must NOT happen is
     * a second entry appearing here casually -- read the note on ALLOWED_UNITS_OF_MEASURE first.
     */
    public const UNITS_OF_MEASURE_BY_WEIGHT = [self::UNIT_OF_MEASURE_KG];

    /**
     * What is printed beside a quantity on the register.
     *
     * Not a language key, unlike the selector labels: 'kg' is an international symbol and reads the
     * same in every language this screen ships in. A unit is absent from the map on purpose -- a
     * line sold by the unit shows nothing after the number, which is what every line in a shop that
     * weighs nothing looks like today.
     */
    public const UNIT_OF_MEASURE_SYMBOLS = [
        self::UNIT_OF_MEASURE_KG => 'kg'
    ];

    public const ALLOWED_SUGGESTIONS_COLUMNS = ['name', 'item_number', 'description', 'cost_price', 'unit_price'];
    public const ALLOWED_SUGGESTIONS_COLUMNS_WITH_EMPTY = ['', 'name', 'item_number', 'description', 'cost_price', 'unit_price'];

    public const ALLOWED_BULK_EDIT_FIELDS = [
        'name',
        'category',
        'supplier_id',
        'cost_price',
        'unit_price',
        'unit_of_measure',
        'reorder_level',
        'description',
        'allow_alt_description',
        'is_serialized'
    ];
    protected $table = 'items';
    protected $primaryKey = 'item_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'name',
        'category',
        'supplier_id',
        'item_number',
        'description',
        'cost_price',
        'unit_price',
        'unit_of_measure',
        'reorder_level',
        'allow_alt_description',
        'is_serialized',
        'deleted',
        'stock_type',
        'item_type',
        'tax_category_id',
        'receiving_quantity',
        'pic_filename',
        'qty_per_pack',
        'pack_name',
        'low_sell_item_id',
        'hsn_code'
    ];


    /**
     * Reduces anything at all to one of the codes the column accepts.
     *
     * Static and free of any database access on purpose: this is the single gate every write path
     * goes through -- the item form, the CSV import and the bulk edit -- and it has to be provable
     * on its own, without a live schema.
     *
     * Unrecognised input falls back to 'unit' rather than raising. The field is never mandatory, so
     * an item saved without answering the question has to keep working, and 'unit' is the answer
     * that leaves behaviour exactly as it was. That also means save_value(), which writes through
     * the raw query builder and never consults $allowedFields, cannot be talked into storing a
     * value the rest of the system does not understand.
     *
     * @param mixed $value raw input: a POST field, a CSV cell, a missing key
     */
    public static function normalize_unit_of_measure(mixed $value): string
    {
        if (!is_string($value)) {
            return self::UNIT_OF_MEASURE_UNIT;
        }

        $code = strtolower(trim($value));

        return in_array($code, self::ALLOWED_UNITS_OF_MEASURE, true)
            ? $code
            : self::UNIT_OF_MEASURE_UNIT;
    }

    /**
     * The codes as a selector wants them: code => label, in the order they are offered.
     *
     * Here rather than in a controller because more than one screen needs it and because a list
     * kept beside the codes cannot fall behind them. That is not hypothetical: a code was once
     * accepted by normalize_unit_of_measure() and stored happily by the column while the only
     * selector in the application still offered two options, so nobody could choose it.
     *
     * Labels are resolved at display time, never stored -- switching locale must not change what
     * the data means. Same reasoning as payment_type_code and cash_source.
     *
     * @return array<string, string>
     */
    public static function units_of_measure_options(): array
    {
        $options = [];

        foreach (self::ALLOWED_UNITS_OF_MEASURE as $code) {
            $options[$code] = lang('Items.unit_of_measure_' . $code);
        }

        return $options;
    }

    /**
     * Whether an item priced in this unit is sold by what it weighs.
     *
     * The register uses this to decide whether to ask for a weight. It is a question about the
     * unit, so it is answered next to the units -- Sale_lib asks it of a cart line and Sales asks
     * it of an item row, and neither should be holding its own list of which codes are weights.
     */
    public static function unit_of_measure_is_weight(mixed $value): bool
    {
        return in_array(self::normalize_unit_of_measure($value), self::UNITS_OF_MEASURE_BY_WEIGHT, true);
    }

    /**
     * The symbol printed beside a quantity, or '' for a unit and for anything unrecognised.
     *
     * Empty rather than a fallback symbol on purpose: showing 'kg' next to a number that is not
     * kilos is worse than showing nothing, and 'unit' has no symbol to show.
     */
    public static function unit_of_measure_symbol(mixed $value): string
    {
        return self::UNIT_OF_MEASURE_SYMBOLS[self::normalize_unit_of_measure($value)] ?? '';
    }

    /**
     * Determines if a given item_id is an item
     */
    public function exists(string $item_id, bool $ignore_deleted = false, bool $deleted = false): bool
    {
        $builder = $this->db->table('items');
        $builder->groupStart();
        $builder->where('item_id', $item_id);
        $builder->orWhere('item_number', $item_id);
        $builder->groupEnd();

        if (!$ignore_deleted) {
            $builder->where('deleted', $deleted);
        }

        return ($builder->get()->getNumRows() === 1);
    }

    /**
     * Determines if a given item_number exists
     */
    public function item_number_exists(string $item_number, string $item_id = ''): bool
    {
        $config = config(OSPOS::class)->settings;

        if ($config['allow_duplicate_barcodes']) {
            return false;
        }

        $builder = $this->db->table('items');
        $builder->where('item_number', $item_number);
        $builder->where('deleted !=', 1);
        $builder->where('item_id !=', intval($item_id));

        // Check if $item_id is a number and not a string starting with 0
        // because cases like 00012345 will be seen as a number where it is a barcode
        if (ctype_digit($item_id) && !str_starts_with($item_id, '0')) {
            $builder->where('item_id !=', intval($item_id));
        }
        return ($builder->get()->getNumRows()) >= 1;
    }

    /**
     * Gets total of rows
     */
    public function get_total_rows(): int
    {
        $builder = $this->db->table('items');
        $builder->where('deleted', 0);

        return $builder->countAllResults();
    }

    /**
     * @param int $tax_category_id
     * @return int
     */
    public function get_tax_category_usage(int $tax_category_id): int    // TODO: This function is never called in the code.
    {
        $builder = $this->db->table('items');
        $builder->where('tax_category_id', $tax_category_id);

        return $builder->countAllResults();
    }

    /**
     * Get number of rows
     */
    public function get_found_rows(string $search, array $filters): int
    {
        return $this->search($search, $filters, 0, 0, 'items.name', 'asc', true);
    }

    /**
     * Perform a search on items
     */
    public function search(string $search, array $filters, ?int $rows = 0, ?int $limit_from = 0, ?string $sort = 'items.name', ?string $order = 'asc', ?bool $count_only = false)
    {
        // Set default values
        if ($rows == null) {
            $rows = 0;
        }
        if ($limit_from == null) {
            $limit_from = 0;
        }
        if ($sort == null) {
            $sort = 'items.name';
        }
        if ($order == null) {
            $order = 'asc';
        }
        if ($count_only == null) {
            $count_only = false;
        }

        $config = config(OSPOS::class)->settings;
        $builder = $this->db->table('items AS items');    // TODO: I'm not sure if it's needed to write items AS items... I think you can just get away with items

        // get_found_rows case
        if ($count_only) {
            $builder->select('COUNT(DISTINCT items.item_id) AS count');
        } else {
            $builder->select('MAX(items.item_id) AS item_id');
            $builder->select('MAX(items.name) AS name');
            $builder->select('MAX(items.category) AS category');
            $builder->select('MAX(items.supplier_id) AS supplier_id');
            $builder->select('MAX(items.item_number) AS item_number');
            $builder->select('MAX(items.description) AS description');
            $builder->select('MAX(items.cost_price) AS cost_price');
            $builder->select('MAX(items.unit_price) AS unit_price');
            $builder->select('MAX(items.unit_of_measure) AS unit_of_measure');
            $builder->select('MAX(items.reorder_level) AS reorder_level');
            $builder->select('MAX(items.receiving_quantity) AS receiving_quantity');
            $builder->select('MAX(items.pic_filename) AS pic_filename');
            $builder->select('MAX(items.allow_alt_description) AS allow_alt_description');
            $builder->select('MAX(items.is_serialized) AS is_serialized');
            $builder->select('MAX(items.pack_name) AS pack_name');
            $builder->select('MAX(items.tax_category_id) AS tax_category_id');
            $builder->select('MAX(items.deleted) AS deleted');

            $builder->select('MAX(suppliers.person_id) AS person_id');
            $builder->select('MAX(suppliers.company_name) AS company_name');
            $builder->select('MAX(suppliers.agency_name) AS agency_name');
            $builder->select('MAX(suppliers.account_number) AS account_number');
            $builder->select('MAX(suppliers.deleted) AS deleted');

            $builder->select('MAX(inventory.trans_id) AS trans_id');
            $builder->select('MAX(inventory.trans_items) AS trans_items');
            $builder->select('MAX(inventory.trans_user) AS trans_user');
            $builder->select('MAX(inventory.trans_date) AS trans_date');
            $builder->select('MAX(inventory.trans_comment) AS trans_comment');
            $builder->select('MAX(inventory.trans_location) AS trans_location');
            $builder->select('MAX(inventory.trans_inventory) AS trans_inventory');

            if ($filters['stock_location_id'] > -1) {
                $builder->select('MAX(item_quantities.item_id) AS qty_item_id');
                $builder->select('MAX(item_quantities.location_id) AS location_id');
                $builder->select('MAX(item_quantities.quantity) AS quantity');
            }
        }

        $builder->join('suppliers AS suppliers', 'suppliers.person_id = items.supplier_id', 'left');
        $builder->join('inventory AS inventory', 'inventory.trans_items = items.item_id');

        if ($filters['stock_location_id'] > -1) {
            $builder->join('item_quantities AS item_quantities', 'item_quantities.item_id = items.item_id');
            $builder->where('location_id', $filters['stock_location_id']);
        }

        $where = empty($config['date_or_time_format'])
            ? 'DATE_FORMAT(trans_date, "%Y-%m-%d") BETWEEN ' . $this->db->escape($filters['start_date']) . ' AND ' . $this->db->escape($filters['end_date'])
            : 'trans_date BETWEEN ' . $this->db->escape(rawurldecode($filters['start_date'])) . ' AND ' . $this->db->escape(rawurldecode($filters['end_date']));
        $builder->where($where);

        $attributes_enabled = count($filters['definition_ids']) > 0;

        if (!empty($search)) {
            if ($attributes_enabled && $filters['search_custom']) {
                $builder->havingLike('attribute_values', $search);
                $builder->orHavingLike('attribute_dtvalues', $search);
                $builder->orHavingLike('attribute_dvalues', $search);
            } else {
                $builder->groupStart();
                $builder->like('name', $search);
                $builder->orLike('item_number', $search);
                $builder->orLike('items.item_id', $search);
                $builder->orLike('company_name', $search);
                $builder->orLike('items.category', $search);
                $builder->groupEnd();
            }
        }

        if ($attributes_enabled) {
            $format = $this->db->escape(dateformat_mysql());
            $this->db->simpleQuery('SET SESSION group_concat_max_len=49152');
            $builder->select('GROUP_CONCAT(DISTINCT CONCAT_WS(\'_\', definition_id, attribute_value) ORDER BY definition_id SEPARATOR \'|\') AS attribute_values');
            $builder->select("GROUP_CONCAT(DISTINCT CONCAT_WS('_', definition_id, DATE_FORMAT(attribute_date, $format)) SEPARATOR '|') AS attribute_dtvalues");
            $builder->select('GROUP_CONCAT(DISTINCT CONCAT_WS(\'_\', definition_id, attribute_decimal) SEPARATOR \'|\') AS attribute_dvalues');
            $builder->join('attribute_links', 'attribute_links.item_id = items.item_id AND attribute_links.receiving_id IS NULL AND attribute_links.sale_id IS NULL AND definition_id IN (' . implode(',', $filters['definition_ids']) . ')', 'left');
            $builder->join('attribute_values', 'attribute_values.attribute_id = attribute_links.attribute_id', 'left');
        }

        $builder->where('items.deleted', $filters['is_deleted']);

        if ($filters['empty_upc']) {
            $builder->where('item_number', null);
        }
        if ($filters['low_inventory']) {
            $builder->where('quantity <=', 'reorder_level');
        }
        if ($filters['is_serialized']) {
            $builder->where('is_serialized', 1);
        }
        if ($filters['no_description']) {
            $builder->where('items.description', '');
        }
        if ($filters['temporary']) {
            $builder->where('items.item_type', ITEM_TEMP);
        } else {
            $non_temp = [ITEM, ITEM_KIT, ITEM_AMOUNT_ENTRY];
            $builder->whereIn('items.item_type', $non_temp);
        }

        // get_found_rows case
        if ($count_only) {
            return $builder->get()->getRow()->count;
        }

        // Avoid duplicated entries with same name because of inventory reporting multiple changes on the same item in the same date range
        $builder->groupBy('items.item_id');

        // Order by name of item by default
        $builder->orderBy($sort, $order);

        if ($rows > 0) {
            $builder->limit($rows, $limit_from);
        }

        return $builder->get();
    }

    /**
     * Los artículos que salen en el archivo que se le entrega al cliente.
     *
     * POR QUÉ NO SE USA `get_all()`, QUE HACE CASI LO MISMO
     *
     * Por dos cosas, y las dos muerden justo con 1.184 artículos:
     *
     * 1. **`get_all()` ordena por `items.name`, que NO es único.** Paginar con `LIMIT/OFFSET` sobre
     *    una columna repetida hace que MariaDB pueda devolver la misma fila en dos lotes y saltarse
     *    otra: el archivo saldría con artículos duplicados y con artículos que faltan, sin avisar.
     *    No es teórico -- medido el 2026-09-01: Casaletto tiene 3 nombres repetidos y Paraíso 14.
     *    Aquí se ordena por `item_id`, que es la clave primaria.
     * 2. **`get_all()` no filtra `item_type`**, así que trae kits, entradas por monto y artículos
     *    TEMPORALES. Los TEMP los crea el propio punto de venta al cobrar algo suelto; sacarlos en el
     *    archivo del cliente y que este los reimporte los **resucita**. Un kit no se puede
     *    reconstruir desde estas columnas, así que reimportarlo lo convertiría en un artículo suelto.
     *
     * Se pagina para no traer 1.184 objetos a memoria de golpe.
     *
     * @param int $rows      cuántos por lote. 0 = todos.
     * @param int $offset    desde cuál.
     */
    public function get_all_for_export(int $rows = 0, int $offset = 0): ResultInterface
    {
        $builder = $this->db->table('items');

        $builder->where('items.deleted', 0);
        $builder->where('items.item_type', ITEM);
        $builder->orderBy('items.item_id', 'asc');

        if ($rows > 0) {
            $builder->limit($rows, $offset);
        }

        return $builder->get();
    }

    /**
     * Cuántos artículos va a traer la exportación. Para saber cuántos lotes hacen falta.
     */
    public function count_all_for_export(): int
    {
        return $this->db->table('items')
            ->where('items.deleted', 0)
            ->where('items.item_type', ITEM)
            ->countAllResults();
    }

    /**
     * Resuelve un lote de códigos de artículo de una sola consulta.
     *
     * DISTINGUE TRES COSAS QUE HOY SON INDISTINGUIBLES
     *
     * `get_info_by_id_or_number()` devuelve la cadena vacía **tanto si no existe ningún artículo con
     * ese código como si existen varios**. Para un emparejamiento masivo eso no sirve: «no existe»
     * significa crear y «hay varios» significa parar y decirlo (D6). Confundirlos crearía un artículo
     * duplicado cada vez que hay una ambigüedad.
     *
     * Y **no incluye borrados**, al revés que aquel, cuyo valor por omisión sí los trae: emparejar
     * contra un artículo borrado lo revive sin que nadie lo haya pedido.
     *
     * Solo artículos normales: un código que pertenezca a un kit o a un temporal se devuelve con su
     * `item_type` para que quien llame pueda rechazar la fila en vez de escribirle encima.
     *
     * @param list<string> $item_numbers
     * @return array<string, list<object>> el código, y los artículos vivos que lo llevan. Una lista
     *                                     de más de uno es la ambigüedad de D6.
     */
    public function resolve_item_numbers(array $item_numbers): array
    {
        $item_numbers = array_values(array_unique(array_filter($item_numbers, static fn ($code): bool => $code !== '' && $code !== null)));

        if ($item_numbers === []) {
            return [];
        }

        $rows = $this->db->table('items')
            ->select('item_id, item_number, item_type, name')
            ->whereIn('item_number', $item_numbers)
            ->where('deleted', 0)
            ->get()
            ->getResult();

        $found = [];

        foreach ($rows as $row) {
            $found[(string)$row->item_number][] = $row;
        }

        return $found;
    }

    /**
     * Returns all the items
     */
    public function get_all(int $stock_location_id = NEW_ENTRY, int $rows = 0, int $limit_from = 0): ResultInterface
    {
        $builder = $this->db->table('items');

        if ($stock_location_id > -1) {
            $builder->join('item_quantities', 'item_quantities.item_id = items.item_id');
            $builder->where('location_id', $stock_location_id);
        }

        $builder->where('items.deleted', 0);

        // Order by name of item
        $builder->orderBy('items.name', 'asc');

        if ($rows > 0) {
            $builder->limit($rows, $limit_from);
        }

        return $builder->get();
    }

    /**
     * Gets information about a particular item
     */
    public function get_info(int $item_id): object
    {
        $builder = $this->db->table('items');
        $builder->select('items.*');
        $builder->select('GROUP_CONCAT(attribute_value SEPARATOR \'|\') AS attribute_values');
        $builder->select('GROUP_CONCAT(attribute_decimal SEPARATOR \'|\') AS attribute_dvalues');
        $builder->select('GROUP_CONCAT(attribute_date SEPARATOR \'|\') AS attribute_dtvalues');
        $builder->join('attribute_links', 'attribute_links.item_id = items.item_id', 'left');
        $builder->join('attribute_values', 'attribute_links.attribute_id = attribute_values.attribute_id', 'left');
        $builder->where('items.item_id', $item_id);
        $builder->groupBy('items.item_id');

        $query = $builder->get();

        if ($query->getNumRows() == 1) {
            return $query->getRow();
        }

        return $this->getEmptyObject('items');
    }

    /**
     * Initializes an empty object based on database definitions
     * @param string $table_name
     * @return object
     */
    private function getEmptyObject(string $table_name): object
    {
        // Return an empty base parent object, as $item_id is NOT an item
        $empty_obj = new stdClass();

        // Iterate through field definitions to determine how the fields should be initialized
        foreach ($this->db->getFieldData($table_name) as $field) {
            $field_name = $field->name;

            if (in_array($field->type, ['int', 'tinyint', 'decimal'])) {
                $empty_obj->$field_name = ($field->primary_key == 1) ? NEW_ENTRY : 0;
            } else {
                $empty_obj->$field_name = null;
            }
        }

        return $empty_obj;
    }

    /**
     * Gets information about a particular item by item id or number
     */
    /**
     * The prefix that marks a value as an item_id and nothing else.
     *
     * WHY A CODE PICKED FROM A LIST CANNOT BE A BARE NUMBER
     *
     * The register has ONE input box and two kinds of value reach it: what a cashier types or a
     * scanner sends -- where `item_number` has to win, see get_info_by_id_or_number() -- and what
     * the autocomplete puts there when somebody clicks a suggestion, which is an `item_id`. As a
     * bare number the two are indistinguishable, so one of them was always going to lose.
     *
     * It lost in production: in Paraiso de la Canasta, CEBOLLA LARGA is item_id 41 and ZANAHORIA
     * carries item_number 41. Clicking CEBOLLA LARGA in the list posted "41", the resolver read it
     * as a printed code, and the till asked the cashier to weigh a ZANAHORIA. 212 of that shop's
     * 1.184 items collide the same way.
     *
     * The fact that a value came from the list is known at the moment of the click and was being
     * thrown away. This prefix carries it. It mirrors the `KIT 3` token this codebase already uses
     * for item kits, and a scanner cannot produce it: barcodes have no spaces.
     */
    public const ID_TOKEN_PREFIX = 'ID ';

    /**
     * Wraps an item_id so that whoever resolves it later knows it is an id.
     */
    public static function id_token(int|string $item_id): string
    {
        return self::ID_TOKEN_PREFIX . $item_id;
    }

    /**
     * The item_id inside an `ID n` token, or null when the value is not one.
     *
     * Only a token whose payload is a plain positive integer counts. `ID 0012` is not an id -- the
     * same leading-zero rule get_info_by_id_or_number() applies -- and neither is `ID abc`.
     */
    public static function parse_id_token(string $value): ?string
    {
        if (stripos($value, self::ID_TOKEN_PREFIX) !== 0) {
            return null;
        }

        $payload = substr($value, strlen(self::ID_TOKEN_PREFIX));

        if ($payload === '' || !ctype_digit($payload) || str_starts_with($payload, '0')) {
            return null;
        }

        return $payload;
    }

    /**
     * Rewrites the `value` of every item suggestion into an `ID n` token.
     *
     * For the screens that feed the answer straight back into an "add this to the cart" form, which
     * are the ones where a bare id is ambiguous. Screens that look the value up by id themselves
     * (write-offs) keep the plain id and must NOT call this.
     *
     * Entries with no `value` -- category and supplier suggestions are label-only -- and entries
     * that are not arrays are left exactly as they are.
     */
    public static function as_id_token_suggestions(array $suggestions): array
    {
        foreach ($suggestions as &$suggestion) {
            if (is_array($suggestion) && isset($suggestion['value'])) {
                $suggestion['value'] = self::id_token($suggestion['value']);
            }
        }

        unset($suggestion);

        return $suggestions;
    }

    public function get_info_by_id_or_number(string $item_id, bool $include_deleted = true): stdClass|string
    {
        // A value that says it is an id is an id, and skips the item_number rule below entirely.
        // That rule exists for codes a human typed or a scanner sent; this value came from a list
        // the user clicked, where the id is not a guess.
        $token = self::parse_id_token($item_id);

        if ($token !== null) {
            $byToken = $this->db->table('items')->where('items.item_id', $token);

            if (!$include_deleted) {
                $byToken->where('items.deleted', 0);
            }

            $query = $byToken->limit(1)->get();

            return $query->getNumRows() === 1 ? $query->getRow() : '';
        }

        // THE ITEM NUMBER WINS, ALWAYS. Two queries, not one with an OR.
        //
        // Upstream ran a single query with `item_number = X OR item_id = X` and a LIMIT 1, and its
        // own comment admitted the problem -- "in case two are returned due to barcode and item_id
        // clash" -- while leaving which one survives to whatever the database felt like returning
        // first. No ORDER BY, so it is arbitrary.
        //
        // That is not academic. A shop whose codes are short numbers hits it on nearly every one:
        // Paraiso de la Canasta imported 1.184 references of which 212 use codes like 56 or 214,
        // and ALL 212 collided with some other item's item_id. Typing 56 for an avocado rang up a
        // cherry jelly. A wrong-product sale is silent -- the cashier sees a name they were not
        // looking at only if they read the line.
        //
        // item_number is what the business prints, types and scans. item_id is a surrogate key
        // nobody outside the database ever sees. When both could match, the printed code is the
        // answer; the id is only a fallback for a caller that already has one.
        $byNumber = $this->db->table('items')->where('items.item_number', $item_id);

        if (!$include_deleted) {
            $byNumber->where('items.deleted', 0);
        }

        $query = $byNumber->limit(1)->get();

        if ($query->getNumRows() === 1) {
            return $query->getRow();
        }

        // Only now the surrogate key, and only when the input can be one. A value starting with 0
        // is a barcode with a leading zero (00012345), never an id.
        if (!ctype_digit(strval($item_id)) || str_starts_with($item_id, '0')) {
            return '';
        }

        $byId = $this->db->table('items')->where('items.item_id', $item_id);

        if (!$include_deleted) {
            $byId->where('items.deleted', 0);
        }

        $query = $byId->limit(1)->get();

        return $query->getNumRows() === 1 ? $query->getRow() : '';
    }

    /**
     * Get an item id given an item number
     */
    public function get_item_id(string $item_number, bool $ignore_deleted = false, bool $deleted = false): bool|int
    {
        // Same rule as get_info_by_id_or_number(): the printed code wins over the surrogate key.
        // Kept in step with it deliberately -- two lookups that disagree about what a code means
        // would be worse than either rule on its own.
        $byNumber = $this->db->table('items')->where('item_number', $item_number);

        if (!$ignore_deleted) {
            $byNumber->where('items.deleted', $deleted);
        }

        $query = $byNumber->get();

        if ($query->getNumRows() === 1) {
            return $query->getRow()->item_id;
        }

        $byId = $this->db->table('items')->where('item_id', $item_number);

        if (!$ignore_deleted) {
            $byId->where('items.deleted', $deleted);
        }

        $query = $byId->get();

        return $query->getNumRows() === 1 ? $query->getRow()->item_id : false;
    }

    /**
     * Gets information about multiple items
     */
    public function get_multiple_info(array $item_ids, int $location_id): ResultInterface
    {
        $format = $this->db->escape(dateformat_mysql());

        $builder = $this->db->table('items');
        $builder->select('items.*');
        $builder->select('MAX(company_name) AS company_name');
        $builder->select('GROUP_CONCAT(DISTINCT CONCAT_WS(\'_\', definition_id, attribute_value) ORDER BY definition_id SEPARATOR \'|\') AS attribute_values');
        $builder->select("GROUP_CONCAT(DISTINCT CONCAT_WS('_', definition_id, DATE_FORMAT(attribute_date, $format)) ORDER BY definition_id SEPARATOR '|') AS attribute_dtvalues");
        $builder->select('GROUP_CONCAT(DISTINCT CONCAT_WS(\'_\', definition_id, attribute_decimal) ORDER BY definition_id SEPARATOR \'|\') AS attribute_dvalues');
        $builder->select('MAX(quantity) as quantity');

        $builder->join('suppliers', 'suppliers.person_id = items.supplier_id', 'left');
        $builder->join('item_quantities', 'item_quantities.item_id = items.item_id', 'left');
        $builder->join('attribute_links', 'attribute_links.item_id = items.item_id AND sale_id IS NULL AND receiving_id IS NULL', 'left');
        $builder->join('attribute_values', 'attribute_links.attribute_id = attribute_values.attribute_id', 'left');

        $builder->where('location_id', $location_id);
        $builder->whereIn('items.item_id', $item_ids);

        $builder->groupBy('items.item_id');

        return $builder->get();
    }

    /**
     * Inserts or updates an item
     */
    public function save_value(array &$item_data, int $item_id = NEW_ENTRY): bool    // TODO: need to bring this in line with parent or change the name
    {
        $builder = $this->db->table('items');

        if ($item_id < 1 || !$this->exists($item_id, true)) {
            if ($builder->insert($item_data)) {
                $item_data['item_id'] = (int)$this->db->insertID();
                if ($item_id < 1) {
                    $builder = $this->db->table('items');
                    $builder->where('item_id', $item_data['item_id']);
                    $builder->update(['low_sell_item_id' => $item_data['item_id']]);
                }

                // Un artículo nuevo estrena historial sin precio anterior. Eso es literalmente
                // «el primero», y es lo que distingue esa fila de una observación posterior.
                $this->record_price_change((int) $item_data['item_id'], null, $item_data);

                return true;
            }

            return false;
        } else {
            $item_data['item_id'] = $item_id;
        }

        // El precio de ANTES, leído antes de pisarlo. Solo se consulta cuando el guardado trae un
        // precio, así que quien no lo toca --change_cost_price(), update_pic_filename()-- no paga
        // esta consulta.
        $previous_price = array_key_exists('unit_price', $item_data)
            ? $this->current_unit_price($item_id)
            : null;

        $builder = $this->db->table('items');
        $builder->where('item_id', $item_id);

        $saved = $builder->update($item_data);

        if ($saved) {
            $this->record_price_change($item_id, $previous_price, $item_data);
        }

        return $saved;
    }

    /**
     * De dónde viene el cambio de precio que se está a punto de escribir.
     *
     * POR QUÉ ESTADO ESTÁTICO Y NO UN PARÁMETRO
     *
     * `save_value()` se llama desde ocho sitios, uno de ellos dentro del bucle de la importación
     * CSV. Añadir un parámetro obliga a acertar en los ocho, y **el sitio que se olvide produce una
     * fila anónima que parece perfectamente legítima** -- que es la peor forma de fallar para un
     * registro cuyo valor entero es que se pueda confiar en él.
     *
     * El estado estático suele ser un olor. Aquí es lo correcto precisamente porque la alternativa
     * es la que se rompe en silencio. PHP-FPM da un proceso por petición y el caso CLI es de un solo
     * hilo, así que no hay carrera posible. Las pruebas lo limpian en tearDown().
     */
    private static ?array $price_change_context = null;

    public static function with_price_change_context(string $source, ?int $employee_id = null, ?int $sale_id = null): void
    {
        self::$price_change_context = [
            'source'      => $source,
            'employee_id' => $employee_id,
            'sale_id'     => $sale_id,
        ];
    }

    public static function clear_price_change_context(): void
    {
        self::$price_change_context = null;
    }

    /**
     * El precio que el artículo tiene ahora mismo, o null si no se puede leer.
     */
    private function current_unit_price(int $item_id): ?string
    {
        return $this->get_unit_prices([$item_id])[$item_id] ?? null;
    }

    /**
     * Deja constancia de un precio nuevo. Nunca lanza y nunca impide guardar el artículo.
     *
     * Se registra desde aquí, y no desde cada pantalla, porque este método es el único sitio por el
     * que pasa toda escritura a `items`: la ficha, la edición masiva, el guardado en línea, la
     * importación CSV y el costo promedio de recepciones. Un historial que solo conociera una de
     * esas puertas respondería «¿cuánto costaba en marzo?» con seguridad y mal.
     */
    private function record_price_change(int $item_id, ?string $previous_price, array $item_data): void
    {
        // Un guardado que no trae precio no es un cambio de precio. `Items::postSave()` reescribe la
        // fila entera en cada guardado, así que sin esta comprobación corregir una descripción
        // dejaría una fila de precio.
        if (!array_key_exists('unit_price', $item_data)) {
            return;
        }

        try {
            $new_price = (string) $item_data['unit_price'];

            if ($previous_price !== null && bccomp($previous_price, $new_price, 2) === 0) {
                return;
            }

            $context = self::$price_change_context;

            model(Item_price_history::class)->record(
                $item_id,
                $previous_price,
                $new_price,
                $context['source'] ?? Item_price_history::SOURCE_UNKNOWN,
                // Sin contexto se cae a la sesión, igual que PlatformActivity::record() resuelve el
                // actor. Bajo `php spark` no hay sesión y la fila es genuinamente anónima, que es
                // honesto: mejor un NULL que un autor inventado.
                $context['employee_id'] ?? self::person_in_session(),
                $context['sale_id'] ?? null,
            );
        } catch (Throwable $e) {
            // Observar no puede tumbar lo observado. Esto corre en el camino de guardado de todo
            // artículo, incluido el que ocurre justo después de cobrar una venta.
            log_message('critical', 'No se pudo registrar el cambio de precio del articulo ' . $item_id . ': ' . $e->getMessage());
        }
    }

    /**
     * El empleado de la sesión, o null cuando no hay ninguna (CLI, migraciones, pruebas).
     */
    private static function person_in_session(): ?int
    {
        $person_id = session()->get('person_id');

        return $person_id === null ? null : (int) $person_id;
    }

    /**
     * Updates multiple items at once
     */
    public function update_multiple(array $item_data, string $item_ids): bool
    {
        $ids = array_values(array_filter(array_map('intval', explode(':', $item_ids))));

        // ESTA ES LA SEGUNDA PUERTA DE ESCRITURA, Y CASI SE NOS PASA.
        //
        // La edición masiva no llama a save_value(): escribe con un solo UPDATE sobre muchas filas.
        // Así que el historial también se captura aquí, o cambiar 40 precios de un clic --que es
        // justo lo que un negocio hace cuando le suben los costos-- no dejaría rastro de ninguno.
        //
        // Se leen los precios ANTES, y solo cuando el guardado trae precio: una edición masiva de
        // categoría o de proveedor no paga esta consulta.
        $previous_prices = array_key_exists('unit_price', $item_data) && $ids !== []
            ? $this->get_unit_prices($ids)
            : [];

        $builder = $this->db->table('items');
        $builder->whereIn('item_id', $ids);

        $saved = $builder->update($item_data);

        if ($saved) {
            foreach ($previous_prices as $item_id => $previous_price) {
                $this->record_price_change($item_id, $previous_price, $item_data);
            }
        }

        return $saved;
    }

    /**
     * El precio de venta de varios artículos de una vez: `[item_id => '4500.00']`.
     *
     * Deliberadamente NO es get_multiple_info(): esa exige un `location_id` y hace cuatro joins
     * --proveedores, existencias, atributos-- para algo que necesita una sola columna. La pantalla
     * de venta va a pedir esto en cada recarga del carrito.
     */
    public function get_unit_prices(array $item_ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $item_ids)));

        if ($ids === []) {
            return [];
        }

        $prices = [];

        foreach ($this->db->table('items')->select('item_id, unit_price')->whereIn('item_id', $ids)->get()->getResult() as $row) {
            $prices[(int) $row->item_id] = (string) $row->unit_price;
        }

        return $prices;
    }

    /**
     * Deletes one item
     */
    public function delete($item_id = null, bool $purge = false): bool|int|string
    {
        $this->db->transStart();

        // Set to 0 quantities
        $item_quantity = model(Item_quantity::class);
        $item_quantity->reset_quantity($item_id);

        $builder = $this->db->table('items');
        $builder->where('item_id', $item_id);
        $success = $builder->update(['deleted' => 1]);

        $inventory = model(Inventory::class);
        $success &= $inventory->reset_quantity($item_id);

        $this->db->transComplete();

        $success &= $this->db->transStatus();

        return $success;
    }

    /**
     * Undeletes one item
     */
    public function undelete(int $item_id): bool
    {
        $builder = $this->db->table('items');
        $builder->where('item_id', $item_id);

        return $builder->update(['deleted' => 0]);
    }

    /**
     * Deletes a list of items
     */
    public function delete_list(array $item_ids): bool
    {
        // Run these queries as a transaction, we want to make sure we do all or nothing
        $this->db->transStart();

        // Set to 0 quantities
        $item_quantity = model(Item_quantity::class);
        $item_quantity->reset_quantity_list($item_ids);

        $builder = $this->db->table('items');
        $builder->whereIn('item_id', $item_ids);
        $success = $builder->update(['deleted' => 1]);

        $inventory = model(Inventory::class);

        foreach ($item_ids as $item_id) {
            $success &= $inventory->reset_quantity($item_id);
        }

        $this->db->transComplete();

        $success &= $this->db->transStatus();

        return $success;
    }

    /**
     * @param string|null $seed
     * @return string
     */
    public function get_search_suggestion_format(?string $seed = null): string
    {
        $config = config(OSPOS::class)->settings;

        $suggestionsFirstColumn = $this->suggestionColumnIsAllowed($config['suggestions_first_column'])
            ? $config['suggestions_first_column']
            : 'name';
        $seed .= ',' . $suggestionsFirstColumn;

        if ($config['suggestions_second_column'] !== '' && $this->suggestionColumnIsAllowed($config['suggestions_second_column'])) {
            $seed .= ',' . $config['suggestions_second_column'];
        }

        if ($config['suggestions_third_column'] !== '' && $this->suggestionColumnIsAllowed($config['suggestions_third_column'])) {
            $seed .= ',' . $config['suggestions_third_column'];
        }

        return $seed;
    }

    /**
     * @param object $result_row
     * @return string
     */
    public function get_search_suggestion_label(object $result_row): string
    {
        $config = config(OSPOS::class)->settings;

        $label = '';
        $label1 = $this->suggestionColumnIsAllowed($config['suggestions_first_column'])
            ? $config['suggestions_first_column']
            : 'name';
        $label2 = $this->suggestionColumnIsAllowed($config['suggestions_second_column'])
            ? $config['suggestions_second_column']
            : '';
        $label3 = $this->suggestionColumnIsAllowed($config['suggestions_third_column'])
            ? $config['suggestions_third_column']
            : '';

        $this->format_result_numbers($result_row);

        // If multi_pack enabled then if "name" is part of the search suggestions then append pack
        if ($config['multi_pack_enabled']) {
            $this->append_label($label, $label1, $result_row);
            $this->append_label($label, $label2, $result_row);
            $this->append_label($label, $label3, $result_row);
        } else {
            $label = $result_row->$label1;

            if ($label2 !== '') {
                $label .= NAME_SEPARATOR . $result_row->$label2;
            }

            if ($label3 !== '') {
                $label .= NAME_SEPARATOR . $result_row->$label3;
            }
        }

        return $label;
    }

    /**
     * Validates if a column name is in the allowed suggestions columns.
     *
     * @param string $columnName
     * @return bool
     */
    private function suggestionColumnIsAllowed(string $columnName): bool
    {
        return in_array($columnName, self::ALLOWED_SUGGESTIONS_COLUMNS, true);
    }

    /**
     * Converts decimal money values to their correct locale format.
     *
     * @param object $result_row
     * @return void
     */
    private function format_result_numbers(object &$result_row): void
    {
        if (isset($result_row->cost_price)) {
            $result_row->cost_price = to_currency_no_money($result_row->cost_price);
        }
        if (isset($result_row->unit_price)) {
            $result_row->unit_price = to_currency_no_money($result_row->unit_price);
        }
    }

    /**
     * @param string $label
     * @param string $item_field_name
     * @param object $item_info
     * @return void
     */
    private function append_label(string &$label, string $item_field_name, object $item_info): void
    {
        if ($item_field_name !== '') {
            if ($label == '') {
                if ($item_field_name == 'name') {    // TODO: This needs to be replaced with Ternary notation if possible
                    $label .= implode(NAME_SEPARATOR, [$item_info->name, $item_info->pack_name]);    // TODO: no need for .= operator.  If it gets here then that means label is an empty string.
                } else {
                    $label .= $item_info->$item_field_name;
                }
            } else {
                if ($item_field_name == 'name') {
                    $label .= implode(NAME_SEPARATOR, ['', $item_info->name, $item_info->pack_name]);
                } else {
                    $label .= NAME_SEPARATOR . $item_info->$item_field_name;
                }
            }
        }
    }

    /**
     * @param string $search
     * @param array $filters
     * @param bool $unique
     * @param int $limit
     * @return array
     */
    public function get_search_suggestions(string $search, array $filters = ['is_deleted' => false, 'search_custom' => false], bool $unique = false, int $limit = 25): array
    {
        $suggestions = [];
        $non_kit = [ITEM, ITEM_AMOUNT_ENTRY];

        $builder = $this->db->table('items');
        $builder->select($this->get_search_suggestion_format('item_id, name, pack_name'));
        $builder->where('deleted', $filters['is_deleted']);
        $builder->whereIn('item_type', $non_kit); // Standard, exclude kit items since kits will be picked up later
        $builder->like('name', $search);    // TODO: this and the next 11 lines are duplicated directly below.  We should extract a method here.
        $builder->orderBy('name', 'asc');

        foreach ($builder->get()->getResult() as $row) {
            $suggestions[] = ['value' => $row->item_id, 'label' => $this->get_search_suggestion_label($row)];
        }

        $builder = $this->db->table('items');
        $builder->select($this->get_search_suggestion_format('item_id, item_number, pack_name'));
        $builder->where('deleted', $filters['is_deleted']);
        $builder->whereIn('item_type', $non_kit); // Standard, exclude kit items since kits will be picked up later
        $builder->like('item_number', $search);
        $builder->orderBy('item_number', 'asc');

        foreach ($builder->get()->getResult() as $row) {
            $suggestions[] = ['value' => $row->item_id, 'label' => $this->get_search_suggestion_label($row)];
        }

        if (!$unique) {
            // Search by category
            $builder = $this->db->table('items');
            $builder->select('category');
            $builder->where('deleted', $filters['is_deleted']);
            $builder->distinct();    // TODO: duplicate code.  Refactor method.
            $builder->like('category', $search);
            $builder->orderBy('category', 'asc');

            foreach ($builder->get()->getResult() as $row) {
                $suggestions[] = ['label' => $row->category];
            }

            $builder = $this->db->table('suppliers');

            // Search by supplier
            $builder->select('company_name');
            $builder->like('company_name', $search);

            // Restrict to non deleted companies only if is_deleted is false
            $builder->where('deleted', $filters['is_deleted']);
            $builder->distinct();
            $builder->orderBy('company_name', 'asc');

            foreach ($builder->get()->getResult() as $row) {
                $suggestions[] = ['label' => $row->company_name];
            }

            // Search by description
            $builder = $this->db->table('items');
            $builder->select($this->get_search_suggestion_format('item_id, name, pack_name, description'));
            $builder->where('deleted', $filters['is_deleted']);
            $builder->like('description', $search);    // TODO: duplicate code, refactor method.
            $builder->orderBy('description', 'asc');

            foreach ($builder->get()->getResult() as $row) {
                $entry = ['value' => $row->item_id, 'label' => $this->get_search_suggestion_label($row)];

                if (!array_walk($suggestions, function ($value, $label) use ($entry) {
                    return $entry['label'] != $label;
                })) {
                    $suggestions[] = $entry;
                }
            }

            // Search in attributes
            if ($filters['search_custom'] !== false) {
                $builder = $this->db->table('attribute_links');
                $builder->join('attribute_values', 'attribute_links.attribute_id = attribute_values.attribute_id');
                $builder->join('attribute_definitions', 'attribute_definitions.definition_id = attribute_links.definition_id');
                $builder->like('attribute_value', $search);
                $builder->where('definition_type', TEXT);
                $builder->where('deleted', $filters['is_deleted']);
                $builder->whereIn('item_type', $non_kit); // Standard, exclude kit items since kits will be picked up later

                foreach ($builder->get()->getResult() as $row) {
                    $suggestions[] = ['value' => $row->item_id, 'label' => $this->get_search_suggestion_label($row)];
                }
            }
        }

        // Only return $limit suggestions
        if (count($suggestions) > $limit) {
            $suggestions = array_slice($suggestions, 0, $limit);
        }

        return array_unique($suggestions, SORT_REGULAR);
    }


    /**
     * @param string $search
     * @param array $filters
     * @param bool $unique
     * @param int $limit
     * @return array
     */
    public function get_stock_search_suggestions(string $search, array $filters = ['is_deleted' => false, 'search_custom' => false], bool $unique = false, int $limit = 25): array
    {
        $suggestions = [];
        $non_kit = [ITEM, ITEM_AMOUNT_ENTRY];

        $builder = $this->db->table('items');
        $builder->select($this->get_search_suggestion_format('item_id, name, pack_name'));
        $builder->where('deleted', $filters['is_deleted']);
        $builder->whereIn('item_type', $non_kit); // Standard, exclude kit items since kits will be picked up later
        $builder->where('stock_type', '0'); // Stocked items only
        $builder->like('name', $search);
        $builder->orderBy('name', 'asc');

        foreach ($builder->get()->getResult() as $row) {
            $suggestions[] = ['value' => $row->item_id, 'label' => $this->get_search_suggestion_label($row)];
        }

        $builder = $this->db->table('items');
        $builder->select($this->get_search_suggestion_format('item_id, item_number, pack_name'));
        $builder->where('deleted', $filters['is_deleted']);
        $builder->whereIn('item_type', $non_kit); // Standard, exclude kit items since kits will be picked up later
        $builder->where('stock_type', '0'); // Stocked items only
        $builder->like('item_number', $search);
        $builder->orderBy('item_number', 'asc');

        foreach ($builder->get()->getResult() as $row) {
            $suggestions[] = ['value' => $row->item_id, 'label' => $this->get_search_suggestion_label($row)];
        }

        if (!$unique) {
            // Search by category
            $builder = $this->db->table('items');
            $builder->select('category');
            $builder->where('deleted', $filters['is_deleted']);
            $builder->whereIn('item_type', $non_kit); // Standard, exclude kit items since kits will be picked up later
            $builder->where('stock_type', '0'); // Stocked items only
            $builder->distinct();
            $builder->like('category', $search);
            $builder->orderBy('category', 'asc');

            foreach ($builder->get()->getResult() as $row) {
                $suggestions[] = ['label' => $row->category];
            }

            // Search by supplier
            $builder = $this->db->table('suppliers');
            $builder->select('company_name');
            $builder->like('company_name', $search);

            // Restrict to non deleted companies only if is_deleted is false
            $builder->where('deleted', $filters['is_deleted']);
            $builder->distinct();
            $builder->orderBy('company_name', 'asc');

            foreach ($builder->get()->getResult() as $row) {
                $suggestions[] = ['label' => $row->company_name];
            }

            // Search by description
            $builder = $this->db->table('items');
            $builder->select($this->get_search_suggestion_format('item_id, name, pack_name, description'));
            $builder->where('deleted', $filters['is_deleted']);
            $builder->whereIn('item_type', $non_kit); // Standard, exclude kit items since kits will be picked up later
            $builder->where('stock_type', '0'); // Stocked items only
            $builder->like('description', $search);    // TODO: duplicated code, refactor method.
            $builder->orderBy('description', 'asc');

            foreach ($builder->get()->getResult() as $row) {
                $entry = ['value' => $row->item_id, 'label' => $this->get_search_suggestion_label($row)];
                if (!array_walk($suggestions, function ($value, $label) use ($entry) {
                    return $entry['label'] != $label;
                })) {
                    $suggestions[] = $entry;
                }
            }

            // Search by custom fields
            if ($filters['search_custom'] !== false) {    // TODO: duplicated code.  We should refactor out a method... this can be replaced with `if ($filters['search_custom']`... no need for the double negative
                $builder = $this->db->table('attribute_links');
                $builder->join('attribute_values', 'attribute_links.attribute_id = attribute_values.attribute_id');
                $builder->join('attribute_definitions', 'attribute_definitions.definition_id = attribute_links.definition_id');
                $builder->like('attribute_value', $search);
                $builder->where('definition_type', TEXT);
                $builder->where('stock_type', '0'); // Stocked items only
                $builder->where('deleted', $filters['is_deleted']);

                foreach ($builder->get()->getResult() as $row) {
                    $suggestions[] = ['value' => $row->item_id, 'label' => $this->get_search_suggestion_label($row)];
                }
            }
        }

        // Only return $limit suggestions
        if (count($suggestions) > $limit) {
            $suggestions = array_slice($suggestions, 0, $limit);
        }

        return array_unique($suggestions, SORT_REGULAR);
    }

    /**
     * @param string $search
     * @param array $filters
     * @param bool $unique
     * @param int $limit
     * @return array
     */
    public function get_kit_search_suggestions(string $search, array $filters = ['is_deleted' => false, 'search_custom' => false], bool $unique = false, int $limit = 25): array
    {
        $suggestions = [];
        $non_kit = [ITEM, ITEM_AMOUNT_ENTRY];    // TODO: This variable is never used.

        $builder = $this->db->table('items');
        $builder->select('item_id, name');
        $builder->where('deleted', $filters['is_deleted']);
        $builder->where('item_type', ITEM_KIT);
        $builder->like('name', $search);
        $builder->orderBy('name', 'asc');

        foreach ($builder->get()->getResult() as $row) {
            $suggestions[] = ['value' => $row->item_id, 'label' => $row->name];
        }

        $builder = $this->db->table('items');
        $builder->select('item_id, item_number');
        $builder->where('deleted', $filters['is_deleted']);
        $builder->like('item_number', $search);
        $builder->where('item_type', ITEM_KIT);
        $builder->orderBy('item_number', 'asc');

        foreach ($builder->get()->getResult() as $row) {
            $suggestions[] = ['value' => $row->item_id, 'label' => $row->item_number];
        }

        if (!$unique) {
            // Search by category
            $builder = $this->db->table('items');
            $builder->select('category');
            $builder->where('deleted', $filters['is_deleted']);
            $builder->where('item_type', ITEM_KIT);
            $builder->distinct();    // TODO: duplicated code, refactor method.
            $builder->like('category', $search);
            $builder->orderBy('category', 'asc');

            foreach ($builder->get()->getResult() as $row) {
                $suggestions[] = ['label' => $row->category];
            }

            // Search by supplier
            $builder = $this->db->table('suppliers');
            $builder->select('company_name');
            $builder->like('company_name', $search);

            // Restrict to non deleted companies only if is_deleted is false
            $builder->where('deleted', $filters['is_deleted']);
            $builder->distinct();
            $builder->orderBy('company_name', 'asc');

            foreach ($builder->get()->getResult() as $row) {
                $suggestions[] = ['label' => $row->company_name];
            }

            // Search by description
            $builder = $this->db->table('items');
            $builder->select('item_id, name, description');
            $builder->where('deleted', $filters['is_deleted']);
            $builder->where('item_type', ITEM_KIT);
            $builder->like('description', $search);
            $builder->orderBy('description', 'asc');

            foreach ($builder->get()->getResult() as $row) {
                $entry = ['value' => $row->item_id, 'label' => $row->name];
                if (!array_walk($suggestions, function ($value, $label) use ($entry) {
                    return $entry['label'] != $label;
                })) {
                    $suggestions[] = $entry;
                }
            }

            // Search in attributes
            if ($filters['search_custom'] !== false) {    // TODO: Duplicate code... same as above... no double negatives
                $builder = $this->db->table('attribute_links');
                $builder->join('attribute_values', 'attribute_links.attribute_id = attribute_values.attribute_id');
                $builder->join('attribute_definitions', 'attribute_definitions.definition_id = attribute_links.definition_id');
                $builder->like('attribute_value', $search);
                $builder->where('definition_type', TEXT);
                $builder->where('stock_type', '0'); // Stocked items only
                $builder->where('deleted', $filters['is_deleted']);

                foreach ($builder->get()->getResult() as $row) {
                    $suggestions[] = ['value' => $row->item_id, 'label' => $this->get_search_suggestion_label($row)];
                }
            }
        }

        // Only return $limit suggestions
        if (count($suggestions) > $limit) {
            $suggestions = array_slice($suggestions, 0, $limit);
        }

        return array_unique($suggestions, SORT_REGULAR);
    }

    /**
     * @param string $search
     * @return array
     */
    public function get_low_sell_suggestions(string $search): array
    {
        $suggestions = [];

        $builder = $this->db->table('items');
        $builder->select($this->get_search_suggestion_format('item_id, pack_name'));
        $builder->where('deleted', '0');
        $builder->where('stock_type', '0'); // Stocked items only    // TODO: '0' should be replaced with a constant.
        $builder->like('name', $search);
        $builder->orderBy('name', 'asc');

        foreach ($builder->get()->getResult() as $row) {
            $suggestions[] = ['value' => $row->item_id, 'label' => $this->get_search_suggestion_label($row)];
        }

        return $suggestions;
    }

    /**
     * @param string $search
     * @return array
     */
    public function get_category_suggestions(string $search): array
    {
        $suggestions = [];

        $builder = $this->db->table('items');
        $builder->distinct();
        $builder->select('category');
        $builder->like('category', $search);
        $builder->where('deleted', 0);
        $builder->orderBy('category', 'asc');

        foreach ($builder->get()->getResult() as $row) {
            $suggestions[] = ['label' => $row->category];
        }

        return $suggestions;
    }

    /**
     * @param string $search
     * @return array
     */
    public function get_location_suggestions(string $search): array
    {
        $suggestions = [];

        $builder = $this->db->table('items');
        $builder->distinct();
        $builder->select('location');
        $builder->like('location', $search);
        $builder->where('deleted', 0);
        $builder->orderBy('location', 'asc');
        foreach ($builder->get()->getResult() as $row) {
            $suggestions[] = ['label' => $row->location];
        }

        return $suggestions;
    }

    /**
     * @return ResultInterface|false|string
     */
    public function get_categories(): ResultInterface|bool    // TODO: This function is never called in the code.
    {
        $builder = $this->db->table('items');
        $builder->select('category');
        $builder->where('deleted', 0);
        $builder->distinct();
        $builder->orderBy('category', 'asc');

        return $builder->get();
    }

    /**
     * changes the cost price of a given item
     * calculates the average price between received items and items on stock
     * $item_id : the item which price should be changed
     * $items_received : the amount of new items received
     * $new_price : the cost-price for the newly received items
     * $old_price (optional) : the current-cost-price
     *
     * used in receiving-process to update cost-price if changed
     * caution: must be used before item_quantities gets updated, otherwise the average price is wrong!
     *
     */
    public function change_cost_price(int $item_id, float $items_received, float $new_price, ?float $old_price = null): bool
    {
        if ($old_price === null) {
            $item_info = $this->get_info($item_id);
            $old_price = $item_info->cost_price;
        }

        $builder = $this->db->table('item_quantities');
        $builder->selectSum('quantity');
        $builder->where('item_id', $item_id);
        $builder->join('stock_locations', 'stock_locations.location_id=item_quantities.location_id');
        $builder->where('stock_locations.deleted', 0);
        $old_total_quantity = $builder->get()->getRow()->quantity;

        $total_quantity = $old_total_quantity + $items_received;
        $average_price = bcdiv(bcadd(bcmul((string)$items_received, (string)$new_price), bcmul((string)$old_total_quantity, (string)$old_price)), (string)$total_quantity);

        $data = ['cost_price' => $average_price];

        return $this->save_value($data, $item_id);
    }

    /**
     * @param int $item_id
     * @param string $item_number
     * @return void
     */
    public function update_item_number(int $item_id, string $item_number): void
    {
        $builder = $this->db->table('items');
        $builder->where('item_id', $item_id);
        $builder->update(['item_number' => $item_number]);    // TODO: this function should probably return the result of update() and add ": bool" to the function signature
    }

    /**
     * @param int $item_id
     * @param string $item_name
     * @return void
     */
    public function update_item_name(int $item_id, string $item_name): void    // TODO: this function should probably return the result of update() and add ": bool" to the function signature
    {
        $builder = $this->db->table('items');
        $builder->where('item_id', $item_id);
        $builder->update(['name' => $item_name]);
    }

    /**
     * @param int $item_id
     * @param string $item_description
     * @return void
     */
    public function update_item_description(int $item_id, string $item_description): void    // TODO: this function should probably return the result of update() and add ": bool" to the function signature
    {
        $builder = $this->db->table('items');
        $builder->where('item_id', $item_id);
        $builder->update(['description' => $item_description]);
    }

    /**
     * Determine the item name to use taking into consideration that
     * for a multipack environment then the item name should have the
     * pack appended to it
     */
    public function get_item_name(?string $as_name = null): string
    {
        $config = config(OSPOS::class)->settings;

        if ($as_name == null) {    // TODO: Replace with ternary notation
            $as_name = '';
        } else {
            $as_name = ' AS ' . $as_name;
        }

        if ($config['multi_pack_enabled']) {    // TODO: Replace with ternary notation
            $item_name = "concat(items.name,'" . NAME_SEPARATOR . '\', items.pack_name)' . $as_name;
        } else {
            $item_name = 'items.name' . $as_name;
        }

        return $item_name;
    }
}
