<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\Presale_report;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * What a campaign has committed, against the database: only OPEN presales count, split by delivery
 * date, with the stock of the location they deliver from and the shortfall.
 *
 * SHARED DATABASE: own campaign, customer and items (all named COMMITTED-TEST), removed in tearDown by
 * id. Never a truncate.
 *
 * @internal
 */
final class PresaleReportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';
    private Presale_report $report;
    private int $campaign_id;
    private int $other_campaign_id;
    private int $customer_id;
    private int $location_id;
    private int $apple_id;
    private int $pear_id;

    /**
     * @var list<int>
     */
    private array $presale_ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->report = model(Presale_report::class);

        $location          = $this->db->table('stock_locations')->select('location_id')->orderBy('location_id')->limit(1)->get()->getRow();
        $this->location_id = $location === null ? 1 : (int) $location->location_id;

        $this->customer_id = $this->makeCustomer();
        $this->apple_id    = $this->makeItem('COMMITTED-TEST manzana', 'A-1', 'unit');
        $this->pear_id     = $this->makeItem('COMMITTED-TEST pera', null, 'kg');

        $this->campaign_id       = $this->makeCampaign();
        $this->other_campaign_id = $this->makeCampaign();
    }

    protected function tearDown(): void
    {
        if ($this->presale_ids !== []) {
            $this->db->table('presale_items')->whereIn('presale_id', $this->presale_ids)->delete();
            $this->db->table('presales')->whereIn('presale_id', $this->presale_ids)->delete();
        }

        $items = [$this->apple_id ?? 0, $this->pear_id ?? 0];
        $this->db->table('item_quantities')->whereIn('item_id', $items)->delete();
        $this->db->table('items')->whereIn('item_id', $items)->delete();
        $this->db->table('presale_campaigns')->whereIn('campaign_id', [$this->campaign_id ?? 0, $this->other_campaign_id ?? 0])->delete();
        $this->db->table('customers')->where('person_id', $this->customer_id ?? 0)->delete();
        $this->db->table('people')->where('person_id', $this->customer_id ?? 0)->delete();

        parent::tearDown();
    }

    public function testOnlyOpenPresalesAreCounted(): void
    {
        $this->presale($this->campaign_id, 'open', '2026-12-20', [[$this->apple_id, '2']]);
        $this->presale($this->campaign_id, 'delivered', '2026-12-20', [[$this->apple_id, '5']]);
        $this->presale($this->campaign_id, 'canceled', '2026-12-20', [[$this->apple_id, '7']]);

        $result = $this->report->committed($this->campaign_id);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('2.000', $result['rows'][0]['total']);
        $this->assertSame(['2026-12-20'], $result['dates']);
    }

    public function testQuantityIsSplitByDeliveryDateAndTotalled(): void
    {
        $this->presale($this->campaign_id, 'open', '2026-12-20', [[$this->apple_id, '2']]);
        $this->presale($this->campaign_id, 'open', '2026-12-20', [[$this->apple_id, '1']]);
        $this->presale($this->campaign_id, 'open', '2026-12-24', [[$this->apple_id, '4'], [$this->pear_id, '0.750']]);

        $result = $this->report->committed($this->campaign_id);

        $this->assertSame(['2026-12-20', '2026-12-24'], $result['dates']);

        $rows = array_column($result['rows'], null, 'item_id');

        $this->assertSame(['2026-12-20' => '3.000', '2026-12-24' => '4.000'], $rows[$this->apple_id]['by_date']);
        $this->assertSame('7.000', $rows[$this->apple_id]['total']);
        $this->assertSame(['2026-12-24' => '0.750'], $rows[$this->pear_id]['by_date']);
        $this->assertSame('A-1', $rows[$this->apple_id]['item_number']);
        $this->assertSame('kg', $rows[$this->pear_id]['unit_of_measure']);
    }

    public function testAnotherCampaignsPresalesAreNotCounted(): void
    {
        $this->presale($this->other_campaign_id, 'open', '2026-12-20', [[$this->apple_id, '9']]);

        $this->assertSame([], $this->report->committed($this->campaign_id)['rows']);
    }

    public function testShortfallIsCommittedMinusStockWhenPositive(): void
    {
        $this->presale($this->campaign_id, 'open', '2026-12-20', [[$this->apple_id, '10'], [$this->pear_id, '2']]);
        $this->stock($this->apple_id, $this->location_id, '4');
        $this->stock($this->pear_id, $this->location_id, '5.500');

        $rows = array_column($this->report->committed($this->campaign_id)['rows'], null, 'item_id');

        $this->assertSame('4.000', $rows[$this->apple_id]['stock']);
        $this->assertSame('6.000', $rows[$this->apple_id]['shortfall']);
        $this->assertSame('5.500', $rows[$this->pear_id]['stock']);
        $this->assertSame('0.000', $rows[$this->pear_id]['shortfall']);
    }

    public function testAnItemWithNoStockRowIsAllShortfall(): void
    {
        $this->presale($this->campaign_id, 'open', '2026-12-20', [[$this->apple_id, '3']]);

        $row = $this->report->committed($this->campaign_id)['rows'][0];

        $this->assertSame('0.000', $row['stock']);
        $this->assertSame('3.000', $row['shortfall']);
    }

    public function testACampaignWithoutOpenPresalesIsEmpty(): void
    {
        $this->presale($this->campaign_id, 'canceled', '2026-12-20', [[$this->apple_id, '3']]);

        $this->assertSame(['dates' => [], 'rows' => []], $this->report->committed($this->campaign_id));
    }

    /**
     * @param list<array{0: int, 1: string}> $lines item_id, quantity
     */
    private function presale(int $campaign_id, string $status, string $date, array $lines): int
    {
        $this->db->table('presales')->insert([
            'campaign_id'      => $campaign_id,
            'customer_id'      => $this->customer_id,
            'employee_id'      => 1,
            'location_id'      => $this->location_id,
            'created_at'       => '2026-11-10 10:00:00',
            'delivery_date_id' => 0,
            'delivery_date'    => $date,
            'status'           => $status,
            'total'            => '0.00',
        ]);

        $id                  = (int) $this->db->insertID();
        $this->presale_ids[] = $id;
        $line                = 0;

        foreach ($lines as [$item_id, $quantity]) {
            $this->db->table('presale_items')->insert([
                'presale_id' => $id,
                'line'       => ++$line,
                'item_id'    => $item_id,
                'quantity'   => $quantity,
                'unit_price' => '1.00',
            ]);
        }

        return $id;
    }

    private function stock(int $item_id, int $location_id, string $quantity): void
    {
        $this->db->table('item_quantities')->replace(['item_id' => $item_id, 'location_id' => $location_id, 'quantity' => $quantity]);
    }

    private function makeCampaign(): int
    {
        $this->db->table('presale_campaigns')->insert([
            'name'                => 'COMMITTED-TEST campana',
            'sale_starts'         => '2026-11-01',
            'sale_ends'           => '2026-12-15',
            'discount_percent'    => '0',
            'min_initial_percent' => '0',
            'active'              => 1,
            'deleted'             => 0,
            'created_by'          => 1,
            'created_at'          => '2026-10-01 00:00:00',
        ]);

        return (int) $this->db->insertID();
    }

    private function makeCustomer(): int
    {
        $this->db->table('people')->insert([
            'first_name'   => 'Ana',
            'last_name'    => 'COMMITTED-TEST',
            'phone_number' => '3000000000',
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

        $this->db->table('customers')->insert(['person_id' => $person_id, 'taxable' => 1, 'deleted' => 0, 'employee_id' => 1]);

        return $person_id;
    }

    private function makeItem(string $name, ?string $number, string $unit): int
    {
        $this->db->table('items')->insert([
            'name'                  => $name,
            'category'              => 'Test',
            'item_number'           => $number,
            'description'           => '',
            'cost_price'            => '0.00',
            'unit_price'            => '1.00',
            'unit_of_measure'       => $unit,
            'reorder_level'         => '0',
            'receiving_quantity'    => '1',
            'allow_alt_description' => 0,
            'is_serialized'         => 0,
        ]);

        return (int) $this->db->insertID();
    }
}
