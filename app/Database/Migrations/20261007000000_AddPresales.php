<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;

/**
 * Creates the tables of the presales module ("Preventas"): selling in advance inside a campaign,
 * collecting the price in instalments, and delivering only once it is fully paid.
 *
 * WHY TABLES OF ITS OWN AND NOT A STATUS ON sales
 *
 * A presale does not touch `sales`, the stock or the cash-up until it is delivered. `sales` feeds
 * every report, the inventory and the shift reconciliation, and a row there with a new status would
 * have to be taught to every query that filters on sale_status. The mechanisms that already exist
 * for "take money now, finish later" -- suspended sales and work orders -- go through
 * Sale::clear_suspended_sale_detail(), which deletes and reinserts the payments when the sale is
 * completed: an instalment taken in October would end up in December's shift.
 *
 * WHY A PAYMENT CARRIES ITS OWN cashup_id
 *
 * Each instalment has to land in the shift that physically received it. sales_payments has no shift
 * of its own -- the seal lives on the sale -- so the instalment is a movement of its own, sealed when
 * it is taken. That is what keeps a cash instalment in the drawer it went into.
 *
 * NO FOREIGN KEYS, DATETIME AND NOT TIMESTAMP, STATUS AS A TEXT CODE
 *
 * Same reasoning, word for word, as 20260923000000_AddOrderTickets: an FK failure inside a write path
 * turns a record into an outage; a TIMESTAMP can silently acquire ON UPDATE CURRENT_TIMESTAMP; and a
 * number whose meaning lives in another file is what sale_status already taught us not to repeat.
 *
 * See docs/Tecnico/venta-anticipada.md sections 3 and 4.
 */
class Migration_AddPresales extends Migration
{
    public const TABLE_CAMPAIGNS      = 'presale_campaigns';
    public const TABLE_CAMPAIGN_ITEMS = 'presale_campaign_items';
    public const TABLE_CAMPAIGN_DATES = 'presale_campaign_dates';
    public const TABLE_PRESALES       = 'presales';
    public const TABLE_ITEMS          = 'presale_items';
    public const TABLE_INSTALLMENTS   = 'presale_installments';
    public const TABLE_PAYMENTS       = 'presale_payments';
    public const TABLE_EVENTS         = 'presale_events';

    /**
     * Every column of each table except its auto-increment primary key, so each model's
     * $allowedFields can be checked against it. CodeIgniter drops a field missing from $allowedFields
     * without raising anything, and this project has already lost data to that twice.
     */
    public const WRITABLE_COLUMNS_CAMPAIGNS = [
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

    // Composite primary key: every column is written.
    public const WRITABLE_COLUMNS_CAMPAIGN_ITEMS = [
        'campaign_id',
        'item_id',
        'base_price',
        'campaign_price',
        'discount_percent',
    ];
    public const WRITABLE_COLUMNS_CAMPAIGN_DATES = [
        'campaign_id',
        'delivery_date',
    ];
    public const WRITABLE_COLUMNS_PRESALES = [
        'campaign_id',
        'customer_id',
        'employee_id',
        'location_id',
        'created_at',
        'delivery_date_id',
        'delivery_date',
        'status',
        'total',
        'comment',
        'sale_id',
        'delivered_at',
        'delivered_by',
        'canceled_at',
        'canceled_by',
        'cancel_reason',
    ];

    // Composite primary key: every column is written.
    public const WRITABLE_COLUMNS_ITEMS = [
        'presale_id',
        'line',
        'item_id',
        'description',
        'quantity',
        'unit_price',
        'discount',
        'discount_type',
        'print_option',
        'item_type',
    ];
    public const WRITABLE_COLUMNS_INSTALLMENTS = [
        'presale_id',
        'due_date',
        'amount',
    ];
    public const WRITABLE_COLUMNS_PAYMENTS = [
        'presale_id',
        'kind',
        'payment_type_code',
        'amount',
        'payment_time',
        'employee_id',
        'cashup_id',
        'reference_code',
    ];
    public const WRITABLE_COLUMNS_EVENTS = [
        'presale_id',
        'event_time',
        'employee_id',
        'event_type',
        'detail',
        'reason',
    ];

    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        // Before any tableExists(): the driver answers from a schema list built when the process
        // started, and a stale list says "not there" in the very deploy that created the table.
        $this->db->resetDataCache();

        $this->createCampaigns();
        $this->createCampaignItems();
        $this->createCampaignDates();
        $this->createPresales();
        $this->createItems();
        $this->createInstallments();
        $this->createPayments();
        $this->createEvents();
    }

    /**
     * Revert a migration step. Children first, so a reader who adds foreign keys later finds the order
     * already correct.
     */
    public function down(): void
    {
        foreach ([
            self::TABLE_EVENTS,
            self::TABLE_PAYMENTS,
            self::TABLE_INSTALLMENTS,
            self::TABLE_ITEMS,
            self::TABLE_PRESALES,
            self::TABLE_CAMPAIGN_DATES,
            self::TABLE_CAMPAIGN_ITEMS,
            self::TABLE_CAMPAIGNS,
        ] as $table) {
            $this->forge->dropTable($table, true);
        }
    }

    /**
     * The season a business sells in advance: what, at what price, delivered when, sold until when.
     */
    private function createCampaigns(): void
    {
        if ($this->exists(self::TABLE_CAMPAIGNS)) {
            return;
        }

        $this->forge->addField([
            'campaign_id' => $this->id(),
            'name'        => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => false],
            // The selling window, inclusive at both ends. Outside it no new presale is registered;
            // instalments, deliveries and cancellations carry on regardless (D16).
            'sale_starts' => ['type' => 'DATE', 'null' => false],
            'sale_ends'   => ['type' => 'DATE', 'null' => false],
            // Applies to every product of the campaign that has no discount of its own (D19).
            'discount_percent' => $this->percent(false),
            // The least share of the total the first instalment may be. Zero means no minimum (D21).
            'min_initial_percent' => $this->percent(false),
            // Inactive hides the campaign from registration and nothing else.
            'active' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 1],
            // A campaign with presales is never removed: the presales point at it.
            'deleted'    => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0],
            'created_by' => $this->int(false),
            'created_at' => ['type' => 'DATETIME', 'null' => false],
        ]);

        $this->forge->addKey('campaign_id', true);
        $this->forge->addKey(['deleted', 'active', 'sale_ends'], false, false, 'idx_selling');

        $this->create(self::TABLE_CAMPAIGNS);
    }

    /**
     * The products a campaign sells, and the price each one is sold at.
     */
    private function createCampaignItems(): void
    {
        if ($this->exists(self::TABLE_CAMPAIGN_ITEMS)) {
            return;
        }

        $this->forge->addField([
            'campaign_id' => $this->int(false),
            'item_id'     => $this->int(false),
            // The catalogue price copied when the product was added. The campaign does not follow
            // later catalogue changes (D14); keeping the copy shows where the price came from.
            'base_price' => $this->money(false),
            // A price of the campaign's own. When set it wins over everything else.
            'campaign_price' => $this->money(true),
            // NULL inherits the campaign's discount; a value, even 0, replaces it for this product.
            'discount_percent' => $this->percent(true),
        ]);

        $this->forge->addPrimaryKey(['campaign_id', 'item_id']);
        $this->forge->addKey('item_id', false, false, 'idx_item_id');

        $this->create(self::TABLE_CAMPAIGN_ITEMS);
    }

    /**
     * The delivery days a campaign offers. A presale picks one of these and nothing else (D15).
     */
    private function createCampaignDates(): void
    {
        if ($this->exists(self::TABLE_CAMPAIGN_DATES)) {
            return;
        }

        $this->forge->addField([
            'date_id'       => $this->id(),
            'campaign_id'   => $this->int(false),
            'delivery_date' => ['type' => 'DATE', 'null' => false],
        ]);

        $this->forge->addKey('date_id', true);
        $this->forge->addUniqueKey(['campaign_id', 'delivery_date'], 'idx_campaign_date');

        $this->create(self::TABLE_CAMPAIGN_DATES);
    }

    /**
     * The agreement with one customer.
     */
    private function createPresales(): void
    {
        if ($this->exists(self::TABLE_PRESALES)) {
            return;
        }

        $this->forge->addField([
            'presale_id'  => $this->id(),
            'campaign_id' => $this->int(false),
            'customer_id' => $this->int(false),
            'employee_id' => $this->int(false),
            // Where the stock will come out of when it is delivered.
            'location_id'      => $this->int(false),
            'created_at'       => ['type' => 'DATETIME', 'null' => false],
            'delivery_date_id' => $this->int(false),
            // A copy of the chosen campaign date, so lists can filter and sort without a join.
            'delivery_date' => ['type' => 'DATE', 'null' => false],
            // open | delivered | canceled. "Up to date", "late" and "paid" are derived on read from
            // the instalments and the payments, never stored: a stored derivation goes stale the
            // moment somebody pays.
            'status'  => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false],
            'total'   => $this->money(false),
            'comment' => ['type' => 'TEXT', 'null' => true],
            // The sale the delivery became. UNIQUE: one presale is delivered once.
            'sale_id'       => $this->int(true),
            'delivered_at'  => ['type' => 'DATETIME', 'null' => true],
            'delivered_by'  => $this->int(true),
            'canceled_at'   => ['type' => 'DATETIME', 'null' => true],
            'canceled_by'   => $this->int(true),
            'cancel_reason' => ['type' => 'TEXT', 'null' => true],
        ]);

        $this->forge->addKey('presale_id', true);
        $this->forge->addUniqueKey('sale_id', 'idx_sale_id');
        $this->forge->addKey(['status', 'delivery_date'], false, false, 'idx_status_delivery');
        $this->forge->addKey(['campaign_id', 'status'], false, false, 'idx_campaign_status');
        $this->forge->addKey('customer_id', false, false, 'idx_customer_id');

        $this->create(self::TABLE_PRESALES);
    }

    /**
     * What was agreed, line by line, at the price it was agreed at.
     */
    private function createItems(): void
    {
        if ($this->exists(self::TABLE_ITEMS)) {
            return;
        }

        $this->forge->addField([
            'presale_id'  => $this->int(false),
            'line'        => ['type' => 'SMALLINT', 'constraint' => 5, 'null' => false],
            'item_id'     => $this->int(false),
            'description' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            // Three decimals: a product sold by weight is agreed at an initial weight (D22).
            'quantity'      => ['type' => 'DECIMAL', 'constraint' => '15,3', 'null' => false],
            'unit_price'    => $this->money(false),
            'discount'      => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => false, 'default' => 0],
            'discount_type' => ['type' => 'TINYINT', 'constraint' => 2, 'null' => false, 'default' => 0],
            // print_option and item_type let the register rebuild a kit's lines exactly as they were
            // when the presale was registered, without reading the kit's current definition.
            'print_option' => ['type' => 'TINYINT', 'constraint' => 2, 'null' => false, 'default' => 0],
            'item_type'    => ['type' => 'TINYINT', 'constraint' => 2, 'null' => false, 'default' => 0],
        ]);

        $this->forge->addPrimaryKey(['presale_id', 'line']);
        $this->forge->addKey('item_id', false, false, 'idx_item_id');

        $this->create(self::TABLE_ITEMS);
    }

    /**
     * The payment plan as agreed. Instalments are a commitment, not money: payments are never
     * assigned to one of them, the totals are compared instead.
     */
    private function createInstallments(): void
    {
        if ($this->exists(self::TABLE_INSTALLMENTS)) {
            return;
        }

        $this->forge->addField([
            'installment_id' => $this->id(),
            'presale_id'     => $this->int(false),
            'due_date'       => ['type' => 'DATE', 'null' => false],
            'amount'         => $this->money(false),
        ]);

        $this->forge->addKey('installment_id', true);
        $this->forge->addKey(['presale_id', 'due_date'], false, false, 'idx_presale_due');

        $this->create(self::TABLE_INSTALLMENTS);
    }

    /**
     * Money in and money out, each in the shift that handled it.
     */
    private function createPayments(): void
    {
        if ($this->exists(self::TABLE_PAYMENTS)) {
            return;
        }

        $this->forge->addField([
            'payment_id' => $this->id(),
            'presale_id' => $this->int(false),
            // payment | refund. A refund is a row of its own with a positive amount, so the history
            // keeps both movements instead of one being edited into the other.
            'kind'              => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => false],
            'payment_type_code' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => false],
            'amount'            => $this->money(false),
            'payment_time'      => ['type' => 'DATETIME', 'null' => false],
            'employee_id'       => $this->int(false),
            // NOT NULL on purpose: an instalment taken with no shift open is money nobody reconciles,
            // so it is refused rather than tolerated the way a sale without a shift is.
            'cashup_id'      => $this->int(false),
            'reference_code' => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
        ]);

        $this->forge->addKey('payment_id', true);
        $this->forge->addKey('presale_id', false, false, 'idx_presale_id');
        $this->forge->addKey('cashup_id', false, false, 'idx_cashup_id');
        $this->forge->addKey('payment_time', false, false, 'idx_payment_time');

        $this->create(self::TABLE_PAYMENTS);
    }

    /**
     * Who did what to a presale, and why. Insert only: nothing updates or deletes these rows.
     */
    private function createEvents(): void
    {
        if ($this->exists(self::TABLE_EVENTS)) {
            return;
        }

        $this->forge->addField([
            'event_id'    => $this->id(),
            'presale_id'  => $this->int(false),
            'event_time'  => ['type' => 'DATETIME', 'null' => false],
            'employee_id' => $this->int(false),
            'event_type'  => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            // Before and after, as JSON. TEXT and not JSON: the JSON column type is an alias on
            // MariaDB and adds nothing but a portability question.
            'detail' => ['type' => 'TEXT', 'null' => true],
            'reason' => ['type' => 'TEXT', 'null' => true],
        ]);

        $this->forge->addKey('event_id', true);
        $this->forge->addKey(['presale_id', 'event_time'], false, false, 'idx_presale_time');

        $this->create(self::TABLE_EVENTS);
    }

    private function id(): array
    {
        return ['type' => 'INT', 'constraint' => 10, 'null' => false, 'auto_increment' => true];
    }

    private function int(bool $null): array
    {
        return ['type' => 'INT', 'constraint' => 10, 'null' => $null];
    }

    private function money(bool $null): array
    {
        return ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => $null];
    }

    private function percent(bool $null): array
    {
        return $null
            ? ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true]
            : ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => false, 'default' => 0];
    }

    private function exists(string $table): bool
    {
        if ($this->db->tableExists($table)) {
            $this->report('AddPresales: ' . $table . ' already exists, nothing to do.');

            return true;
        }

        return false;
    }

    private function create(string $table): void
    {
        $this->forge->createTable($table, true);

        $this->report('AddPresales: created ' . $this->db->prefixTable($table) . '.');
    }

    /**
     * Migrations are also run from the web installer, where CLI output has nowhere to go.
     */
    private function report(string $message): void
    {
        if (is_cli()) {
            CLI::write($message);
        }
    }
}
