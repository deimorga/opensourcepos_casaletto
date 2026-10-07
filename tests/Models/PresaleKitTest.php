<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\Item;
use App\Models\Presale;
use App\Models\Presale_campaign;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\OSPOS;

/**
 * A kit sold in presale is expanded into its components the way the register expands it
 * (Sale_lib::add_item_kit()), so the delivery can rebuild the cart line by line and the stock that
 * leaves is the components' (docs/Tecnico/venta-anticipada.md §8.6).
 *
 * The rules copied from the register:
 *
 * - print_option (Sale_lib::add_item() in PRICE_MODE_KIT): ALL prints every line, KIT prints only the
 *   kit's own line, PRICED prints the lines that carry a price;
 * - a kit inside a kit is expanded in place, its quantities multiplied, and its own representative
 *   line is NOT added -- the register does not add it either.
 *
 * The deliberate difference (owner's decision of 2026-10-07): the campaign price of a kit is the price
 * of the WHOLE kit. Its representative line carries it, and every component goes at 0 whatever the
 * kit's price_option says -- the register would price components under ALL and KIT_STOCK, and the
 * customer would pay the kit twice.
 *
 * SHARED DATABASE: everything is created here and removed in tearDown by its own ids.
 *
 * @internal
 */
final class PresaleKitTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    private const TODAY = '2026-11-10';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';
    private Presale $presales;
    private Presale_campaign $campaigns;
    private int $employee_id;
    private int $campaign_id;
    private int $stocked_id;
    private int $unstocked_id;

    /**
     * @var list<int>
     */
    private array $kit_ids = [];

    private mixed $decimalsBefore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->presales  = model(Presale::class);
        $this->campaigns = model(Presale_campaign::class);

        $this->decimalsBefore                               = config(OSPOS::class)->settings['currency_decimals'] ?? null;
        config(OSPOS::class)->settings['currency_decimals'] = 0;

        $employee          = $this->db->table('employees')->select('person_id')->limit(1)->get()->getRow();
        $this->employee_id = $employee === null ? 1 : (int) $employee->person_id;

        $this->stocked_id   = $this->makeItem('PRESALEKIT-TEST harina', '10000.00', ITEM, HAS_STOCK);
        $this->unstocked_id = $this->makeItem('PRESALEKIT-TEST servicio', '5000.00', ITEM, HAS_NO_STOCK);

        $campaign = $this->campaigns->save_campaign([
            'name'                => 'PRESALEKIT-TEST Navidad',
            'sale_starts'         => '2026-11-01',
            'sale_ends'           => '2026-12-15',
            'discount_percent'    => '0',
            'min_initial_percent' => '0',
            'active'              => 1,
        ], NEW_ENTRY, $this->employee_id);

        $this->assertIsInt($campaign);
        $this->campaign_id = $campaign;
    }

    protected function tearDown(): void
    {
        if ($this->kit_ids !== []) {
            $this->db->table('item_kit_items')->whereIn('item_kit_id', $this->kit_ids)->delete();
            $this->db->table('item_kits')->whereIn('item_kit_id', $this->kit_ids)->delete();
        }

        $this->db->table('presale_campaign_items')->where('campaign_id', $this->campaign_id ?? 0)->delete();
        $this->db->table('presale_campaigns')->where('campaign_id', $this->campaign_id ?? 0)->delete();
        $this->db->table('items')->like('name', 'PRESALEKIT-TEST', 'after')->delete();

        if ($this->decimalsBefore === null) {
            unset(config(OSPOS::class)->settings['currency_decimals']);
        } else {
            config(OSPOS::class)->settings['currency_decimals'] = $this->decimalsBefore;
        }

        parent::tearDown();
    }

    public function testPriceOptionKitPutsThePriceOnlyOnTheKitLine(): void
    {
        $kit = $this->makeKit('PRESALEKIT-TEST ancheta', '30000.00', PRICE_OPTION_KIT, PRINT_ALL, [
            [$this->stocked_id, '2'],
            [$this->unstocked_id, '1'],
        ]);

        $lines = $this->priced($kit, '2');

        $this->assertSame([
            [$kit, '2.000', '30000.00', '60000.00', PRINT_YES, ITEM_KIT],
            [$this->stocked_id, '4.000', '0.00', '0.00', PRINT_YES, ITEM],
            [$this->unstocked_id, '2.000', '0.00', '0.00', PRINT_YES, ITEM],
        ], $lines);
    }

    /**
     * Under ALL the register would price every component at its catalogue price. In a presale the
     * kit's campaign price is the whole kit: components at 0.
     */
    public function testPriceOptionAllStillPutsTheComponentsAtZero(): void
    {
        $kit = $this->makeKit('PRESALEKIT-TEST ancheta', '30000.00', PRICE_OPTION_ALL, PRINT_ALL, [
            [$this->stocked_id, '2'],
            [$this->unstocked_id, '1'],
        ]);

        $this->assertSame([
            [$kit, '2.000', '30000.00', '60000.00', PRINT_YES, ITEM_KIT],
            [$this->stocked_id, '4.000', '0.00', '0.00', PRINT_YES, ITEM],
            [$this->unstocked_id, '2.000', '0.00', '0.00', PRINT_YES, ITEM],
        ], $this->priced($kit, '2'));
    }

    /**
     * Under KIT_STOCK the register would price the components that keep stock. Here: at 0 too.
     */
    public function testPriceOptionKitStockStillPutsTheComponentsAtZero(): void
    {
        $kit = $this->makeKit('PRESALEKIT-TEST ancheta', '30000.00', PRICE_OPTION_KIT_STOCK, PRINT_ALL, [
            [$this->stocked_id, '2'],
            [$this->unstocked_id, '1'],
        ]);

        $this->assertSame([
            [$kit, '2.000', '30000.00', '60000.00', PRINT_YES, ITEM_KIT],
            [$this->stocked_id, '4.000', '0.00', '0.00', PRINT_YES, ITEM],
            [$this->unstocked_id, '2.000', '0.00', '0.00', PRINT_YES, ITEM],
        ], $this->priced($kit, '2'));
    }

    /**
     * PRICED prints the lines that carry a price: with every component at 0, only the kit's own line.
     * That is what the register prints for a line at 0.
     */
    public function testPrintOptionPricedPrintsOnlyTheKitLine(): void
    {
        $kit = $this->makeKit('PRESALEKIT-TEST ancheta', '30000.00', PRICE_OPTION_KIT_STOCK, PRINT_PRICED, [
            [$this->stocked_id, '1'],
            [$this->unstocked_id, '1'],
        ]);

        $this->assertSame([PRINT_YES, PRINT_NO, PRINT_NO], array_column($this->priced($kit, '1'), 4));
    }

    public function testPrintOptionKitPrintsOnlyTheKitLine(): void
    {
        $kit = $this->makeKit('PRESALEKIT-TEST ancheta', '30000.00', PRICE_OPTION_ALL, PRINT_KIT, [
            [$this->stocked_id, '1'],
            [$this->unstocked_id, '1'],
        ]);

        $this->assertSame([PRINT_YES, PRINT_NO, PRINT_NO], array_column($this->priced($kit, '1'), 4));
    }

    /**
     * The campaign's discount lands on the kit line, the one that carries the campaign price.
     */
    public function testTheKitLineCarriesTheCampaignPrice(): void
    {
        $this->db->table('presale_campaigns')->where('campaign_id', $this->campaign_id)->update(['discount_percent' => '10.00']);

        $kit = $this->makeKit('PRESALEKIT-TEST ancheta', '30000.00', PRICE_OPTION_KIT, PRINT_ALL, [[$this->stocked_id, '1']]);

        $this->assertSame('27000.00', $this->priced($kit, '1')[0][2]);
    }

    /**
     * A kit inside a kit is expanded into ITS components, quantities multiplied, and its own line is
     * not added: the register does not add it either (Sale_lib::add_item_kit()).
     */
    public function testANestedKitIsExpandedWithMultipliedQuantities(): void
    {
        $inner = $this->makeKit('PRESALEKIT-TEST relleno', '0.00', PRICE_OPTION_ALL, PRINT_ALL, [[$this->unstocked_id, '3']], false);
        $outer = $this->makeKit('PRESALEKIT-TEST ancheta', '30000.00', PRICE_OPTION_KIT, PRINT_ALL, [
            [$inner, '1'],
            [$this->stocked_id, '1'],
        ]);

        $this->assertSame([
            [$outer, '2.000', '30000.00', '60000.00', PRINT_YES, ITEM_KIT],
            [$this->unstocked_id, '6.000', '0.00', '0.00', PRINT_YES, ITEM],
            [$this->stocked_id, '2.000', '0.00', '0.00', PRINT_YES, ITEM],
        ], $this->priced($outer, '2'));
    }

    /**
     * The register cannot add a deleted component, so neither can a presale: it would promise a
     * delivery the till then refuses.
     */
    public function testAKitWithADeletedComponentIsRefused(): void
    {
        $kit = $this->makeKit('PRESALEKIT-TEST ancheta', '30000.00', PRICE_OPTION_KIT, PRINT_ALL, [[$this->stocked_id, '1']]);

        $this->db->table('items')->where('item_id', $this->stocked_id)->update(['deleted' => 1]);

        $this->assertSame('Presales.kit_component_missing', $this->presales->price_lines($this->campaign_id, [['item_id' => $kit, 'quantity' => '1']]));
    }

    public function testAKitIsSoldInWholeUnits(): void
    {
        $kit = $this->makeKit('PRESALEKIT-TEST ancheta', '30000.00', PRICE_OPTION_KIT, PRINT_ALL, [[$this->stocked_id, '1']]);

        $this->assertSame('Presales.quantity_must_be_whole', $this->presales->price_lines($this->campaign_id, [['item_id' => $kit, 'quantity' => '1.5']]));
    }

    /**
     * Registered, every line is stored with what the delivery needs to rebuild it: the kit line and
     * each component, with their own price, print option and type. The total is the kit's campaign
     * price and nothing more, even under price_option ALL.
     */
    public function testARegisteredKitStoresEveryLine(): void
    {
        $kit = $this->makeKit('PRESALEKIT-TEST ancheta', '30000.00', PRICE_OPTION_ALL, PRINT_KIT, [[$this->stocked_id, '2']]);

        $customer_id = $this->makeCustomer();
        $cashup_id   = $this->openShift();
        $this->assertTrue($this->campaigns->add_date($this->campaign_id, '2026-12-23'));
        $date_id = (int) $this->campaigns->get_dates($this->campaign_id)[0]['date_id'];

        try {
            $id = $this->presales->create([
                'campaign_id'      => $this->campaign_id,
                'customer_id'      => $customer_id,
                'delivery_date_id' => $date_id,
                'location_id'      => 1,
                'lines'            => [['item_id' => $kit, 'quantity' => '1']],
                'installments'     => [['due_date' => self::TODAY, 'amount' => '30000']],
                'payment'          => ['payment_type_code' => 'cash', 'amount' => '30000'],
            ], $this->employee_id, self::TODAY);

            $this->assertIsInt($id);
            $this->assertSame('30000.00', $this->presales->get_info($id)['total']);

            $stored = array_map(static fn (array $line): array => [
                (int) $line['line'], (int) $line['item_id'], (string) $line['quantity'], (string) $line['unit_price'], (int) $line['print_option'], (int) $line['item_type'],
            ], $this->presales->get_lines($id));

            $this->assertSame([
                [1, $kit, '1.000', '30000.00', PRINT_YES, ITEM_KIT],
                [2, $this->stocked_id, '2.000', '0.00', PRINT_NO, ITEM],
            ], $stored);
        } finally {
            $ids = array_column($this->db->table('presales')->select('presale_id')->where('campaign_id', $this->campaign_id)->get()->getResultArray(), 'presale_id');

            if ($ids !== []) {
                foreach (['presale_events', 'presale_payments', 'presale_installments', 'presale_items', 'presales'] as $table) {
                    $this->db->table($table)->whereIn('presale_id', $ids)->delete();
                }
            }

            $this->db->table('presale_campaign_dates')->where('campaign_id', $this->campaign_id)->delete();
            $this->db->table('cash_up')->where('cashup_id', $cashup_id)->delete();
            $this->db->table('customers')->where('person_id', $customer_id)->delete();
            $this->db->table('people')->where('person_id', $customer_id)->delete();
        }
    }

    /**
     * price_lines() for one kit, flattened to [item_id, quantity, unit_price, amount, print_option, item_type].
     */
    private function priced(int $kit_item_id, string $quantity): array
    {
        $lines = $this->presales->price_lines($this->campaign_id, [['item_id' => $kit_item_id, 'quantity' => $quantity]]);

        $this->assertIsArray($lines, is_string($lines) ? $lines : '');

        return array_map(static fn (array $line): array => [
            $line['item_id'], $line['quantity'], $line['unit_price'], $line['amount'], $line['print_option'], $line['item_type'],
        ], $lines);
    }

    /**
     * A kit: its representative item, the item_kits row and the components. Returns the
     * representative item_id, which is what a campaign sells.
     *
     * @param list<array{0: int, 1: string}> $components [item_id, quantity]
     */
    private function makeKit(string $name, string $price, int $price_option, int $print_option, array $components, bool $in_campaign = true): int
    {
        $item_id = $this->makeItem($name, $price, ITEM_KIT, HAS_NO_STOCK);

        $this->db->table('item_kits')->insert([
            'item_kit_number'   => null,
            'name'              => $name,
            'description'       => '',
            'item_id'           => $item_id,
            'kit_discount'      => '0.00',
            'kit_discount_type' => PERCENT,
            'price_option'      => $price_option,
            'print_option'      => $print_option,
        ]);

        $kit_id          = (int) $this->db->insertID();
        $this->kit_ids[] = $kit_id;

        foreach ($components as $sequence => [$component_id, $quantity]) {
            $this->db->table('item_kit_items')->insert([
                'item_kit_id'  => $kit_id,
                'item_id'      => $component_id,
                'quantity'     => $quantity,
                'kit_sequence' => $sequence + 1,
            ]);
        }

        if ($in_campaign) {
            $this->assertTrue($this->campaigns->add_item($this->campaign_id, $item_id));
        }

        return $item_id;
    }

    private function makeItem(string $name, string $price, int $item_type, int $stock_type): int
    {
        $this->db->table('items')->insert([
            'name'                  => $name,
            'category'              => 'Test',
            'item_number'           => null,
            'description'           => '',
            'cost_price'            => '0.00',
            'unit_price'            => $price,
            'unit_of_measure'       => Item::UNIT_OF_MEASURE_UNIT,
            'reorder_level'         => '0',
            'receiving_quantity'    => '1',
            'allow_alt_description' => 0,
            'is_serialized'         => 0,
            'item_type'             => $item_type,
            'stock_type'            => $stock_type,
        ]);

        return (int) $this->db->insertID();
    }

    private function makeCustomer(): int
    {
        $this->db->table('people')->insert([
            'first_name'   => 'Kit',
            'last_name'    => 'PRESALEKIT-TEST',
            'phone_number' => '',
            'email'        => '',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
        ]);

        $person_id = (int) $this->db->insertID();

        $this->db->table('customers')->insert(['person_id' => $person_id, 'taxable' => 1, 'deleted' => 0, 'employee_id' => $this->employee_id]);

        return $person_id;
    }

    private function openShift(): int
    {
        $this->db->table('cash_up')->insert([
            'status'               => 'open',
            'open_date'            => '2099-01-01 08:00:00',
            'close_date'           => null,
            'open_amount_cash'     => '0.00',
            'transfer_amount_cash' => '0.00',
            'note'                 => 0,
            'closed_amount_cash'   => '0.00',
            'closed_amount_card'   => '0.00',
            'closed_amount_check'  => '0.00',
            'closed_amount_due'    => '0.00',
            'closed_amount_total'  => '0.00',
            'description'          => 'PresaleKitTest fixture',
            'open_employee_id'     => $this->employee_id,
            'close_employee_id'    => $this->employee_id,
            'deleted'              => 0,
        ]);

        return (int) $this->db->insertID();
    }
}
