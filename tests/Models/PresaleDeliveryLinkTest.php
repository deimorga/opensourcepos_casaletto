<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\Presale;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The link between a presale and the register tab that delivers it (presales.sale_id, D17), and
 * what it means for cancelling.
 *
 * - attach_delivery_sale() writes it only on an open presale, over no link or over the stale link the
 *   caller saw: one tab per presale, and two tills at once produce one tab and one refusal.
 * - detach_delivery_sale() undoes it, never on a delivered presale.
 * - cancel() is refused while the linked tab is open, and drops a link to a tab that no longer is.
 *
 * SHARED DATABASE: presales and sales made here are removed by their ids in tearDown. No refund is
 * taken here, so no shift is needed.
 *
 * @internal
 */
final class PresaleDeliveryLinkTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';
    private Presale $presales;

    /**
     * @var list<int>
     */
    private array $presaleIds = [];

    /**
     * @var list<int>
     */
    private array $saleIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->presales = model(Presale::class);
    }

    protected function tearDown(): void
    {
        if ($this->presaleIds !== []) {
            foreach (['presale_events', 'presale_payments', 'presales'] as $table) {
                $this->db->table($table)->whereIn('presale_id', $this->presaleIds)->delete();
            }
        }

        if ($this->saleIds !== []) {
            $this->db->table('sales')->whereIn('sale_id', $this->saleIds)->delete();
        }

        parent::tearDown();
    }

    public function testAnOpenPresaleWithoutATabIsLinked(): void
    {
        $presale = $this->makePresale();
        $sale    = $this->makeOpenSale();

        $this->assertTrue($this->presales->attach_delivery_sale($presale, $sale, null));
        $this->assertSame($sale, $this->linkedSale($presale));
    }

    /**
     * A second till that read "no link" a moment before the first one wrote it is refused: one tab.
     */
    public function testAPresaleGetsOneTabOnly(): void
    {
        $presale = $this->makePresale();
        $first   = $this->makeOpenSale();
        $second  = $this->makeOpenSale();

        $this->assertTrue($this->presales->attach_delivery_sale($presale, $first, null));
        $this->assertFalse($this->presales->attach_delivery_sale($presale, $second, null));
        $this->assertSame($first, $this->linkedSale($presale));
    }

    /**
     * A link to a tab that is gone is replaced, but only by whoever saw that same stale link.
     */
    public function testAStaleLinkIsReplacedOnlyByWhoeverSawIt(): void
    {
        $presale = $this->makePresale();
        $stale   = $this->makeOpenSale();
        $fresh   = $this->makeOpenSale();
        $other   = $this->makeOpenSale();

        $this->assertTrue($this->presales->attach_delivery_sale($presale, $stale, null));

        $this->assertFalse($this->presales->attach_delivery_sale($presale, $fresh, $other), 'Not the link the caller saw.');
        $this->assertTrue($this->presales->attach_delivery_sale($presale, $fresh, $stale));
        $this->assertSame($fresh, $this->linkedSale($presale));
    }

    public function testOnlyAnOpenPresaleIsLinked(): void
    {
        $canceled = $this->makePresale(Presale::STATUS_CANCELED);
        $sale     = $this->makeOpenSale();

        $this->assertFalse($this->presales->attach_delivery_sale($canceled, $sale, null));
        $this->assertNull($this->linkedSale($canceled));
    }

    public function testDetachingUnlinksOnlyThatTab(): void
    {
        $presale = $this->makePresale();
        $sale    = $this->makeOpenSale();

        $this->presales->attach_delivery_sale($presale, $sale, null);

        $this->presales->detach_delivery_sale($presale, $sale + 1_000_000);
        $this->assertSame($sale, $this->linkedSale($presale), 'Another tab id leaves the link alone.');

        $this->presales->detach_delivery_sale($presale, $sale);
        $this->assertNull($this->linkedSale($presale));
    }

    /**
     * A delivered presale's sale_id is its delivery: nothing undoes it.
     */
    public function testDetachingNeverTouchesADeliveredPresale(): void
    {
        $sale      = $this->makeOpenSale();
        $delivered = $this->makePresale(Presale::STATUS_DELIVERED, $sale);

        $this->presales->detach_delivery_sale($delivered, $sale);

        $this->assertSame($sale, $this->linkedSale($delivered));
    }

    /**
     * The delivery tab would stay in the register with its presale payment and could still be
     * charged. The cashier takes it back to presales from the register first.
     */
    public function testCancellingIsRefusedWhileTheDeliveryTabIsOpen(): void
    {
        $presale = $this->makePresale();
        $sale    = $this->makeOpenSale();
        $this->presales->attach_delivery_sale($presale, $sale, null);

        $result = $this->presales->cancel($presale, 1, 'El cliente desistió', '0');

        $this->assertSame('Presales.cancel_open_in_register', $result);
        $this->assertSame(Presale::STATUS_OPEN, $this->presales->get_info($presale)['status']);
        $this->assertSame($sale, $this->linkedSale($presale));
        $this->assertSame(0, $this->db->table('presale_events')->where('presale_id', $presale)->countAllResults(), 'Nothing written.');

        $this->presales->detach_delivery_sale($presale, $sale);

        $this->assertTrue($this->presales->cancel($presale, 1, 'El cliente desistió', '0'));
        $this->assertSame(Presale::STATUS_CANCELED, $this->presales->get_info($presale)['status']);
    }

    /**
     * A link to a sale that is no longer an open tab (deleted, or something else closed it) does not
     * block the cancellation, and the canceled presale points at no sale.
     */
    public function testAStaleLinkDoesNotBlockTheCancellationAndIsDropped(): void
    {
        $presale = $this->makePresale();
        $sale    = $this->makeOpenSale();
        $this->presales->attach_delivery_sale($presale, $sale, null);
        $this->db->table('sales')->where('sale_id', $sale)->delete();

        $this->assertTrue($this->presales->cancel($presale, 1, 'El cliente desistió', '0'));

        $row = $this->presales->get_info($presale);
        $this->assertSame(Presale::STATUS_CANCELED, $row['status']);
        $this->assertNull($row['sale_id']);
    }

    /**
     * Once canceled, the register cannot link it: attach waits for cancel()'s row lock and then finds
     * the presale no longer open.
     */
    public function testACanceledPresaleCannotBeSentToTheRegister(): void
    {
        $presale = $this->makePresale();
        $this->assertTrue($this->presales->cancel($presale, 1, 'El cliente desistió', '0'));

        $this->assertFalse($this->presales->attach_delivery_sale($presale, $this->makeOpenSale(), null));
    }

    private function linkedSale(int $presale_id): ?int
    {
        $sale_id = $this->presales->get_info($presale_id)['sale_id'];

        return $sale_id === null ? null : (int) $sale_id;
    }

    private function makePresale(string $status = Presale::STATUS_OPEN, ?int $sale_id = null): int
    {
        $this->db->table('presales')->insert([
            'campaign_id'      => 0,
            'customer_id'      => 1,
            'employee_id'      => 1,
            'location_id'      => 1,
            'created_at'       => date('Y-m-d H:i:s'),
            'delivery_date_id' => 0,
            'delivery_date'    => '2099-12-24',
            'status'           => $status,
            'total'            => '100.00',
            'comment'          => 'PRLINK-TEST',
            'sale_id'          => $sale_id,
        ]);

        $id                 = (int) $this->db->insertID();
        $this->presaleIds[] = $id;

        return $id;
    }

    /**
     * An open register tab: a sale with status OPENED.
     */
    private function makeOpenSale(): int
    {
        $this->db->table('sales')->insert([
            'sale_time'   => date('Y-m-d H:i:s'),
            'customer_id' => null,
            'employee_id' => 1,
            'comment'     => 'PRLINK-TEST',
            'sale_status' => OPENED,
        ]);

        $id              = (int) $this->db->insertID();
        $this->saleIds[] = $id;

        return $id;
    }
}
