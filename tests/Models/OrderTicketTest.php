<?php

namespace Tests\Models;

use App\Database\Migrations\Migration_AddOrderTickets;
use App\Models\Order_ticket;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The order ticket ("comanda") and its state machine.
 *
 * What matters here is the behaviour a till can observe: which moves the ticket accepts, which it
 * refuses without raising, and that charging a sale can never fail because of the ticket register.
 * Only the order_tickets table is touched -- the test database is shared between test FILES.
 *
 * @internal
 */
final class OrderTicketTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    private const LOCATION_ID       = 1;
    private const OTHER_LOCATION_ID = 2;
    private const EMPLOYEE_ID       = 1;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';
    private Order_ticket $tickets;

    protected function setUp(): void
    {
        parent::setUp();

        // The driver answers tableExists() from a schema list built when the process started.
        $this->db->resetDataCache();

        $this->tickets = model(Order_ticket::class, false);
        $this->db->table('order_tickets')->truncate();
    }

    // ========== The schema contract ==========

    /**
     * CodeIgniter drops a field missing from $allowedFields in silence. The migration constant is
     * referenced HERE and never in a data provider: providers are resolved before MigrationRunner
     * has loaded the migration classes, which are not PSR-4 loadable because of their date prefix.
     */
    public function testAllowedFieldsCoverEveryWritableColumn(): void
    {
        $expected = Migration_AddOrderTickets::WRITABLE_COLUMNS_TICKETS;
        sort($expected);

        $allowed = $this->tickets->allowedFields;
        sort($allowed);

        $this->assertSame($expected, $allowed);
    }

    // ========== The state machine ==========

    /**
     * D14 excludes it outright: once the money is in the drawer the ticket cannot be cancelled,
     * from any screen. The refusal is a false and the row is left exactly as it was.
     */
    public function testTheStateMachineRefusesToCancelAChargedTicket(): void
    {
        $id = $this->tickets->create_ticket('ANDREA', self::LOCATION_ID, self::EMPLOYEE_ID);
        $this->assertTrue($this->tickets->attach_sale($id, 501));
        $this->assertSame(1, $this->tickets->mark_charged_by_sale(501));

        $this->assertFalse($this->tickets->cancel($id, self::EMPLOYEE_ID, 'el cliente se fue'));

        $row = $this->tickets->get_info($id);
        $this->assertSame(Order_ticket::STATUS_CHARGED, $row['status']);
        $this->assertNull($row['cancelled_by']);
        $this->assertNull($row['cancelled_at']);
        $this->assertSame('', $row['cancel_reason']);
    }

    /**
     * All sixteen pairs of the four statuses, so a destination added to TRANSITIONS by mistake --
     * or one removed -- fails here by name.
     */
    public function testCanTransitionAnswersEveryPairOfStatuses(): void
    {
        $expected = [
            'open'      => ['open' => false, 'delivered' => true, 'cancelled' => true, 'charged' => true],
            'delivered' => ['open' => false, 'delivered' => false, 'cancelled' => true, 'charged' => true],
            'cancelled' => ['open' => false, 'delivered' => false, 'cancelled' => false, 'charged' => false],
            'charged'   => ['open' => false, 'delivered' => false, 'cancelled' => false, 'charged' => false],
        ];

        $checked = 0;

        foreach ($expected as $from => $destinations) {
            foreach ($destinations as $to => $allowed) {
                $this->assertSame($allowed, Order_ticket::can_transition($from, $to), "{$from} -> {$to}");
                $checked++;
            }
        }

        $this->assertSame(16, $checked);
        $this->assertFalse(Order_ticket::can_transition('nonsense', 'open'));
        $this->assertFalse(Order_ticket::can_transition('open', 'nonsense'));
    }

    public function testDeliveringAnOpenTicketRecordsWhoAndWhen(): void
    {
        $id = $this->tickets->create_ticket('mesa 4', self::LOCATION_ID, self::EMPLOYEE_ID);

        $this->assertTrue($this->tickets->mark_delivered($id, 7));

        $row = $this->tickets->get_info($id);
        $this->assertSame(Order_ticket::STATUS_DELIVERED, $row['status']);
        $this->assertSame(7, (int) $row['delivered_by']);
        $this->assertNotNull($row['delivered_at']);

        $this->assertFalse($this->tickets->mark_delivered($id, 7), 'Delivered is not a destination of delivered.');
    }

    // ========== Creating ==========

    public function testABlankNameOpensNothing(): void
    {
        $this->assertSame(0, $this->tickets->create_ticket('', self::LOCATION_ID, self::EMPLOYEE_ID));
        $this->assertSame(0, $this->tickets->create_ticket("   \t ", self::LOCATION_ID, self::EMPLOYEE_ID));

        $this->assertSame(0, $this->db->table('order_tickets')->countAllResults());
    }

    public function testANewTicketIsOpenWithNoSaleAndATrimmedName(): void
    {
        $id = $this->tickets->create_ticket('  domicilio Juan  ', self::LOCATION_ID, self::EMPLOYEE_ID, '  sin cebolla ');

        $this->assertGreaterThan(0, $id);

        $row = $this->tickets->get_info($id);
        $this->assertSame('domicilio Juan', $row['name']);
        $this->assertSame('sin cebolla', $row['note']);
        $this->assertSame(Order_ticket::STATUS_OPEN, $row['status']);
        $this->assertNull($row['sale_id']);
        $this->assertSame(self::LOCATION_ID, (int) $row['location_id']);
        $this->assertSame(self::EMPLOYEE_ID, (int) $row['opened_by']);
        $this->assertNotNull($row['opened_at']);
    }

    /**
     * Cut by characters, not bytes: a byte cut would split an "ñ" in half and store garbage.
     */
    public function testALongNameIsCutToSixtyFourCharactersWithoutBreakingAccents(): void
    {
        $name = str_repeat('Ñandú', 16);
        $this->assertSame(80, mb_strlen($name));

        $id = $this->tickets->create_ticket($name, self::LOCATION_ID, self::EMPLOYEE_ID);

        $stored = $this->tickets->get_info($id)['name'];
        $this->assertSame(mb_substr($name, 0, 64), $stored);
        $this->assertSame(64, mb_strlen($stored));
        $this->assertTrue(mb_check_encoding($stored, 'UTF-8'));
    }

    // ========== Cancelling ==========

    public function testCancellingWithoutAReasonIsRefusedAndTouchesNothing(): void
    {
        $id = $this->tickets->create_ticket('ANDREA', self::LOCATION_ID, self::EMPLOYEE_ID);

        $this->assertFalse($this->tickets->cancel($id, self::EMPLOYEE_ID, ''));
        $this->assertFalse($this->tickets->cancel($id, self::EMPLOYEE_ID, '    '));

        $row = $this->tickets->get_info($id);
        $this->assertSame(Order_ticket::STATUS_OPEN, $row['status']);
        $this->assertNull($row['cancelled_by']);
        $this->assertNull($row['cancelled_at']);
    }

    public function testATicketCanBeCancelledOnlyOnce(): void
    {
        $id = $this->tickets->create_ticket('ANDREA', self::LOCATION_ID, self::EMPLOYEE_ID);

        $this->assertTrue($this->tickets->cancel($id, 3, '  el cliente se fue  '));
        $this->assertFalse($this->tickets->cancel($id, 4, 'otra vez'));

        $row = $this->tickets->get_info($id);
        $this->assertSame(Order_ticket::STATUS_CANCELLED, $row['status']);
        $this->assertSame(3, (int) $row['cancelled_by'], 'The second attempt must not overwrite who cancelled.');
        $this->assertSame('el cliente se fue', $row['cancel_reason']);
        $this->assertNotNull($row['cancelled_at']);
    }

    // ========== Charging, on the path of the money ==========

    public function testChargingASaleChargesItsOpenAndDeliveredTicketsButNotACancelledOne(): void
    {
        $open      = $this->insertTicket(Order_ticket::STATUS_OPEN, 900);
        $delivered = $this->insertTicket(Order_ticket::STATUS_DELIVERED, 900);
        $cancelled = $this->insertTicket(Order_ticket::STATUS_CANCELLED, 900);
        $otherSale = $this->insertTicket(Order_ticket::STATUS_OPEN, 901);

        $this->assertSame(2, $this->tickets->mark_charged_by_sale(900));

        $this->assertSame(Order_ticket::STATUS_CHARGED, $this->tickets->get_info($open)['status']);
        $this->assertNotNull($this->tickets->get_info($open)['charged_at']);
        $this->assertSame(Order_ticket::STATUS_CHARGED, $this->tickets->get_info($delivered)['status']);
        $this->assertSame(Order_ticket::STATUS_CANCELLED, $this->tickets->get_info($cancelled)['status']);
        $this->assertNull($this->tickets->get_info($cancelled)['charged_at']);
        $this->assertSame(Order_ticket::STATUS_OPEN, $this->tickets->get_info($otherSale)['status']);

        $this->assertSame(0, $this->tickets->mark_charged_by_sale(900), 'Charging twice charges nothing new.');
        $this->assertSame(0, $this->tickets->mark_charged_by_sale(123456), 'A sale with no ticket is the normal case.');
    }

    /**
     * Charging a sale must survive the ticket table being absent. The entrypoint migrates every
     * schema before serving (since 8f92b4901), so this is not the ordinary deploy path any more --
     * but SKIP_MIGRATIONS=1 and a table dropped by hand both still reach it, and in the second case
     * is_latest() stays true and the till keeps selling.
     *
     * The real table is NOT dropped: the test database is shared between files. A subclass pointing
     * at a table that does not exist reproduces the same thing without touching anybody else.
     */
    public function testChargingSurvivesAMissingTableInsteadOfBreakingTheSale(): void
    {
        $withoutTable = new class () extends Order_ticket {
            protected $table = 'order_tickets_no_existe_xyz';
        };

        $this->assertSame(0, $withoutTable->mark_charged_by_sale(900), 'Returns 0; never throws.');
    }

    // ========== Reading ==========

    public function testTheLiveListShowsOnlyOpenAndDeliveredTicketsOfThatSiteNewestFirst(): void
    {
        $older     = $this->insertTicket(Order_ticket::STATUS_OPEN, null, self::LOCATION_ID, '2026-09-23 12:00:00');
        $delivered = $this->insertTicket(Order_ticket::STATUS_DELIVERED, null, self::LOCATION_ID, '2026-09-23 12:30:00');
        $sameSecA  = $this->insertTicket(Order_ticket::STATUS_OPEN, null, self::LOCATION_ID, '2026-09-23 13:00:00');
        $sameSecB  = $this->insertTicket(Order_ticket::STATUS_OPEN, null, self::LOCATION_ID, '2026-09-23 13:00:00');
        $this->insertTicket(Order_ticket::STATUS_CANCELLED, null, self::LOCATION_ID, '2026-09-23 14:00:00');
        $this->insertTicket(Order_ticket::STATUS_CHARGED, null, self::LOCATION_ID, '2026-09-23 14:00:00');
        $this->insertTicket(Order_ticket::STATUS_OPEN, null, self::OTHER_LOCATION_ID, '2026-09-23 14:00:00');

        $ids = array_map('intval', array_column($this->tickets->get_live_for_location(self::LOCATION_ID), 'order_ticket_id'));

        $this->assertSame([$sameSecB, $sameSecA, $delivered, $older], $ids);
    }

    public function testATicketIsFoundByTheSaleThatBillsIt(): void
    {
        $id = $this->tickets->create_ticket('ANDREA', self::LOCATION_ID, self::EMPLOYEE_ID);

        $this->assertNull($this->tickets->get_by_sale_id(777));
        $this->assertTrue($this->tickets->attach_sale($id, 777));
        $this->assertTrue($this->tickets->attach_sale($id, 777), 'Attaching the same sale again is not a refusal.');

        $this->assertSame($id, (int) $this->tickets->get_by_sale_id(777)['order_ticket_id']);
        $this->assertNull($this->tickets->get_info(999999));
    }

    public function testAClosedTicketCannotBeAttachedToASale(): void
    {
        $id = $this->insertTicket(Order_ticket::STATUS_CANCELLED, null);

        $this->assertFalse($this->tickets->attach_sale($id, 555));
        $this->assertNull($this->tickets->get_info($id)['sale_id']);
    }

    /**
     * Seeds a row directly in a given status, bypassing the model, so each reading test states its
     * starting point instead of walking the state machine to reach it.
     */
    private function insertTicket(string $status, ?int $saleId, int $locationId = self::LOCATION_ID, string $openedAt = '2026-09-23 12:00:00'): int
    {
        $this->db->table('order_tickets')->insert([
            'sale_id'     => $saleId,
            'name'        => 'comanda ' . $status,
            'status'      => $status,
            'location_id' => $locationId,
            'note'        => '',
            'opened_by'   => self::EMPLOYEE_ID,
            'opened_at'   => $openedAt,
        ]);

        return (int) $this->db->insertID();
    }
}
