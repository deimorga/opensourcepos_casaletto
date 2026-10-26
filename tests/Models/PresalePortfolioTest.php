<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\Presale_report;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The presales portfolio against the database (docs/Tecnico/preventas-cartera-y-avisos.md §3): the
 * four figures, the overdue by age, what falls due in the next seven days and the late list, checked
 * against a sum done by hand on a fixed set.
 *
 * THE FIXED SET, on 2026-11-20, campaign A unless said otherwise:
 *
 *   P1 up to date        100.000, plan 01/11 30.000 · 20/11 35.000 · 01/12 35.000, paid 30.000.
 *                        The instalment due TODAY is not overdue; it falls due this week.
 *   P2 late 1-7 days      60.000, plan 01/11 20.000 · 15/11 20.000 · 10/12 20.000, paid 20.000.
 *                        Overdue 20.000, 5 days. Delivered on 24/12, the others on 20/12.
 *   P3 late 8-30 days     50.000, plan 01/11 25.000 · 25/11 25.000, paid 10.000.
 *                        Overdue 15.000, 19 days; 25.000 falls due this week.
 *   P4 late 31+ days      40.000, plan 01/10 20.000 · 01/12 20.000, paid 5.000.
 *                        Overdue 15.000, 50 days.
 *   P5 paid, not delivered 80.000, plan 01/11 80.000, paid 80.000. Nothing left to collect.
 *   P6 canceled, refunded 70.000, plan 01/11 70.000, paid 30.000 and refunded 30.000. Left out.
 *   P7 delivered          90.000, paid 90.000. Left out.
 *   P8 campaign B, late   10.000, plan 01/11 10.000, paid 0. Overdue 10.000, 19 days.
 *
 * By hand, campaign A: sold 330.000, collected 145.000, to collect 185.000, overdue 50.000;
 * 1-7: 20.000 (1), 8-30: 15.000 (1), 31+: 15.000 (1); next seven days 60.000 (P1 35.000, P3 25.000).
 *
 * SHARED DATABASE: own campaigns, customer and presales (PORTFOLIO-TEST), removed by id. The
 * "all campaigns" test only looks at its own presales, since other files may leave open ones.
 *
 * @internal
 */
final class PresalePortfolioTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    private const TODAY = '2026-11-20';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';
    private Presale_report $report;
    private int $campaign_a  = 0;
    private int $campaign_b  = 0;
    private int $customer_id = 0;

    /**
     * @var array<string, int> P1..P8 => presale_id
     */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->report = model(Presale_report::class);

        $this->customer_id = $this->makeCustomer();
        $this->campaign_a  = $this->makeCampaign('PORTFOLIO-TEST A');
        $this->campaign_b  = $this->makeCampaign('PORTFOLIO-TEST B');

        $a = $this->campaign_a;

        $this->p['P1'] = $this->presale($a, 'open', '2026-12-20', '100000', [['2026-11-01', '30000'], ['2026-11-20', '35000'], ['2026-12-01', '35000']], [['payment', '30000']]);
        $this->p['P2'] = $this->presale($a, 'open', '2026-12-24', '60000', [['2026-11-01', '20000'], ['2026-11-15', '20000'], ['2026-12-10', '20000']], [['payment', '20000']]);
        $this->p['P3'] = $this->presale($a, 'open', '2026-12-20', '50000', [['2026-11-01', '25000'], ['2026-11-25', '25000']], [['payment', '10000']]);
        $this->p['P4'] = $this->presale($a, 'open', '2026-12-20', '40000', [['2026-10-01', '20000'], ['2026-12-01', '20000']], [['payment', '5000']]);
        $this->p['P5'] = $this->presale($a, 'open', '2026-12-20', '80000', [['2026-11-01', '80000']], [['payment', '80000']]);
        $this->p['P6'] = $this->presale($a, 'canceled', '2026-12-20', '70000', [['2026-11-01', '70000']], [['payment', '30000'], ['refund', '30000']]);
        $this->p['P7'] = $this->presale($a, 'delivered', '2026-12-20', '90000', [['2026-11-01', '90000']], [['payment', '90000']]);
        $this->p['P8'] = $this->presale($this->campaign_b, 'open', '2026-12-20', '10000', [['2026-11-01', '10000']], []);
    }

    protected function tearDown(): void
    {
        $ids = array_values($this->p);

        if ($ids !== []) {
            foreach (['presale_payments', 'presale_installments', 'presales'] as $table) {
                $this->db->table($table)->whereIn('presale_id', $ids)->delete();
            }
        }

        $this->db->table('presale_campaigns')->whereIn('campaign_id', [$this->campaign_a, $this->campaign_b])->delete();
        $this->db->table('customers')->where('person_id', $this->customer_id)->delete();
        $this->db->table('people')->where('person_id', $this->customer_id)->delete();

        parent::tearDown();
    }

    public function testTheFourFiguresMatchTheSumDoneByHand(): void
    {
        $result = $this->report->portfolio(self::TODAY, $this->campaign_a);

        $this->assertSame([
            'sold'       => '330000.00',
            'collected'  => '145000.00',
            'receivable' => '185000.00',
            'overdue'    => '50000.00',
        ], $result['totals']);
    }

    public function testOverdueIsSplitByAge(): void
    {
        $aging = $this->report->portfolio(self::TODAY, $this->campaign_a)['aging'];

        $this->assertSame(['amount' => '20000.00', 'count' => 1], $aging['1_7']);
        $this->assertSame(['amount' => '15000.00', 'count' => 1], $aging['8_30']);
        $this->assertSame(['amount' => '15000.00', 'count' => 1], $aging['31_plus']);
    }

    /**
     * Today's instalment counts this week, not as overdue; P3's partial payment already went to its
     * overdue instalment, so the whole 25.000 of the next one is still to come.
     */
    public function testTheNextSevenDaysCountWhatIsStillMissing(): void
    {
        $upcoming = $this->report->portfolio(self::TODAY, $this->campaign_a)['upcoming'];

        $this->assertSame('60000.00', $upcoming['amount']);
        $this->assertSame([$this->p['P1'], $this->p['P3']], array_column($upcoming['rows'], 'presale_id'));
        $this->assertSame(['35000.00', '25000.00'], array_column($upcoming['rows'], 'amount'));
        $this->assertSame(['2026-11-20', '2026-11-25'], array_column($upcoming['rows'], 'due_date'));
    }

    /**
     * Seven days is today and the six that follow: an instalment on 27/11 is next week.
     */
    public function testTheSeventhDayAfterTodayIsOutOfTheWindow(): void
    {
        // On 18/11 the window is 18/11 to 24/11: P1's 20/11 (35.000) is in, P3's 25/11 is not.
        $this->assertSame('35000.00', $this->report->portfolio('2026-11-18', $this->campaign_a)['upcoming']['amount']);

        // One day later, 25/11 is the last day of the window.
        $this->assertSame('60000.00', $this->report->portfolio('2026-11-19', $this->campaign_a)['upcoming']['amount']);

        $later = $this->report->portfolio('2026-11-25', $this->campaign_a)['upcoming'];

        // On 25/11: P1 01/12 35.000, P3 25/11 25.000 and P4 01/12 20.000; P2's 10/12 is out.
        $this->assertSame('80000.00', $later['amount']);
        $this->assertNotContains($this->p['P2'], array_column($later['rows'], 'presale_id'));
    }

    public function testTheLateListGoesFromTheMostLateWithContactData(): void
    {
        $late = $this->report->portfolio(self::TODAY, $this->campaign_a)['late'];

        $this->assertSame([$this->p['P4'], $this->p['P3'], $this->p['P2']], array_column($late, 'presale_id'));
        $this->assertSame([50, 19, 5], array_column($late, 'days_late'));
        $this->assertSame(['15000.00', '15000.00', '20000.00'], array_column($late, 'overdue'));

        $this->assertSame('3001112233', $late[0]['phone_number']);
        $this->assertSame('ana@example.com', $late[0]['email']);
        $this->assertSame('PORTFOLIO-TEST A', $late[0]['campaign_name']);
        $this->assertSame('2026-12-20', $late[0]['delivery_date']);
    }

    public function testCanceledDeliveredAndPaidPresalesAreNeverLate(): void
    {
        $ids = array_column($this->report->portfolio(self::TODAY)['late'], 'presale_id');

        foreach (['P5', 'P6', 'P7'] as $name) {
            $this->assertNotContains($this->p[$name], $ids, $name);
        }

        $rows = array_column($this->report->portfolio(self::TODAY, $this->campaign_a)['rows'], null, 'presale_id');

        $this->assertArrayHasKey($this->p['P5'], $rows, 'Paid but not delivered is still in the portfolio');
        $this->assertSame('0.00', $rows[$this->p['P5']]['balance']);
        $this->assertArrayNotHasKey($this->p['P6'], $rows);
        $this->assertArrayNotHasKey($this->p['P7'], $rows);
    }

    public function testWithoutACampaignEveryCampaignCounts(): void
    {
        $all = array_column($this->report->portfolio(self::TODAY)['late'], 'presale_id');

        $this->assertContains($this->p['P8'], $all);
        $this->assertContains($this->p['P4'], $all);

        $a = array_column($this->report->portfolio(self::TODAY, $this->campaign_a)['late'], 'presale_id');

        $this->assertNotContains($this->p['P8'], $a);
    }

    public function testTheDeliveryDateNarrowsThePortfolio(): void
    {
        $result = $this->report->portfolio(self::TODAY, $this->campaign_a, '2026-12-24');

        $this->assertSame([$this->p['P2']], array_column($result['rows'], 'presale_id'));
        $this->assertSame('60000.00', $result['totals']['sold']);
        $this->assertSame('20000.00', $result['totals']['overdue']);
    }

    public function testAnEmptyFilterGivesZeros(): void
    {
        $result = $this->report->portfolio(self::TODAY, $this->campaign_a, '2030-01-01');

        $this->assertSame(['sold' => '0.00', 'collected' => '0.00', 'receivable' => '0.00', 'overdue' => '0.00'], $result['totals']);
        $this->assertSame([], $result['late']);
        $this->assertSame(['amount' => '0.00', 'rows' => []], $result['upcoming']);
        $this->assertSame(['amount' => '0.00', 'count' => 0], $result['aging']['31_plus']);
    }

    public function testTheOpenDeliveryDatesAreOffered(): void
    {
        $dates = $this->report->open_delivery_dates();

        $this->assertContains('2026-12-20', $dates);
        $this->assertContains('2026-12-24', $dates);
        $this->assertSame($dates, array_values(array_unique($dates)));
    }

    /**
     * @param list<array{0: string, 1: string}> $plan     due_date, amount
     * @param list<array{0: string, 1: string}> $payments kind, amount
     */
    private function presale(int $campaign_id, string $status, string $delivery_date, string $total, array $plan, array $payments): int
    {
        $this->db->table('presales')->insert([
            'campaign_id'      => $campaign_id,
            'customer_id'      => $this->customer_id,
            'employee_id'      => 1,
            'location_id'      => 1,
            'created_at'       => '2026-10-01 10:00:00',
            'delivery_date_id' => 0,
            'delivery_date'    => $delivery_date,
            'status'           => $status,
            'total'            => $total,
        ]);

        $id = (int) $this->db->insertID();

        foreach ($plan as [$due, $amount]) {
            $this->db->table('presale_installments')->insert(['presale_id' => $id, 'due_date' => $due, 'amount' => $amount]);
        }

        foreach ($payments as [$kind, $amount]) {
            $this->db->table('presale_payments')->insert([
                'presale_id'        => $id,
                'kind'              => $kind,
                'payment_type_code' => 'cash',
                'amount'            => $amount,
                'payment_time'      => '2026-10-01 10:00:00',
                'employee_id'       => 1,
                'cashup_id'         => 0,
            ]);
        }

        return $id;
    }

    private function makeCampaign(string $name): int
    {
        $this->db->table('presale_campaigns')->insert([
            'name'                => $name,
            'sale_starts'         => '2026-10-01',
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
            'last_name'    => 'PORTFOLIO-TEST',
            'phone_number' => '3001112233',
            'email'        => 'ana@example.com',
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
}
