<?php

namespace Tests\Models;

use App\Database\Migrations\Migration_AddOrderTickets;
use App\Models\Order_ticket;
use App\Models\Order_ticket_line;
use App\Models\Order_ticket_round;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use LogicException;

/**
 * Sending an order ticket to the kitchen.
 *
 * The property everything here protects is that the kitchen never receives the same dish twice. The
 * failure it guards against does not look like a failure: two sheets come out, both look right, and
 * the kitchen cooks the order twice.
 *
 * Touches only the three order ticket tables -- the test database is shared between test FILES.
 *
 * @internal
 */
final class OrderTicketRoundTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    private const LOCATION_ID = 1;
    private const WAITER_ID   = 5;
    private const CASHIER_ID  = 6;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private Order_ticket $tickets;
    private Order_ticket_line $lines;
    private Order_ticket_round $rounds;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->resetDataCache();

        foreach (['order_tickets', 'order_ticket_lines', 'order_ticket_rounds'] as $table) {
            $this->db->table($table)->truncate();
        }

        $this->tickets = model(Order_ticket::class, false);
        $this->lines   = model(Order_ticket_line::class, false);
        $this->rounds  = model(Order_ticket_round::class, false);
    }

    public function testAllowedFieldsCoverEveryWritableColumn(): void
    {
        // Referenced here and never in a data provider: providers are resolved before
        // MigrationRunner loads the migration classes, which are not PSR-4 loadable.
        $expected = Migration_AddOrderTickets::WRITABLE_COLUMNS_ROUNDS;
        sort($expected);

        $allowed = $this->rounds->allowedFields;
        sort($allowed);

        $this->assertSame($expected, $allowed);
    }

    /**
     * THE test of this file. A double tap, a reload that resubmits, or the waiter and the cashier at
     * once: the second send must find nothing and create nothing.
     */
    public function testSendingTwiceDoesNotSendTheSameLineAgain(): void
    {
        $ticket = $this->openTicket();
        $this->addLine($ticket, 'Empanada');
        $this->addLine($ticket, 'Jugo de mora');

        $first = $this->rounds->send($ticket, self::WAITER_ID);

        $this->assertNotNull($first);
        $this->assertSame(1, $first['number']);
        $this->assertSame(2, $first['lines']);

        $this->assertNull($this->rounds->send($ticket, self::CASHIER_ID), 'The second send has nothing left to send.');

        $this->assertCount(1, $this->rounds->get_rounds($ticket), 'No second round, and above all no empty one.');
        $this->assertCount(2, $this->lines->get_by_round($first['round_id']));
        $this->assertSame([], $this->lines->get_pending($ticket));
    }

    /**
     * D8: the second sheet carries only what was added after the first.
     */
    public function testASecondRoundCarriesOnlyWhatWasAddedAndIsNumberedTwo(): void
    {
        $ticket = $this->openTicket();
        $this->addLine($ticket, 'Empanada');
        $first = $this->rounds->send($ticket, self::WAITER_ID);

        $added  = $this->addLine($ticket, 'Postre');
        $second = $this->rounds->send($ticket, self::WAITER_ID);

        $this->assertNotNull($second);
        $this->assertSame(2, $second['number']);
        $this->assertSame(1, $second['lines']);

        $onSecondSheet = array_map('intval', array_column($this->lines->get_by_round($second['round_id']), 'order_ticket_line_id'));
        $this->assertSame([$added], $onSecondSheet);
        $this->assertCount(1, $this->lines->get_by_round($first['round_id']), 'The first sheet is unchanged.');
    }

    public function testATicketWithNothingPendingCreatesNoRound(): void
    {
        $ticket = $this->openTicket();

        $this->assertNull($this->rounds->send($ticket, self::WAITER_ID));
        $this->assertSame(0, $this->db->table('order_ticket_rounds')->countAllResults());
    }

    /**
     * A line voided before it was ever sent is not part of any round, and a ticket whose only
     * pending lines were voided has nothing to send.
     */
    public function testVoidedLinesAreNeverSent(): void
    {
        $ticket = $this->openTicket();
        $kept   = $this->addLine($ticket, 'Empanada');
        $voided = $this->addLine($ticket, 'Gaseosa');
        $this->lines->void_line($voided);

        $round = $this->rounds->send($ticket, self::WAITER_ID);

        $this->assertSame(1, $round['lines']);
        $this->assertSame([$kept], array_map('intval', array_column($this->lines->get_by_round($round['round_id']), 'order_ticket_line_id')));

        $onlyVoided = $this->openTicket('solo anuladas');
        $this->lines->void_line($this->addLine($onlyVoided, 'Gaseosa'));

        $this->assertNull($this->rounds->send($onlyVoided, self::WAITER_ID));
    }

    /**
     * A cancelled or charged ticket is closed. Its lines are nobody's to cook any more, and they stay
     * exactly as they were.
     */
    public function testAClosedTicketSendsNothing(): void
    {
        $cancelled = $this->openTicket('cancelada');
        $line      = $this->addLine($cancelled, 'Empanada');
        $this->tickets->cancel($cancelled, self::CASHIER_ID, 'el cliente se fue');

        $this->assertNull($this->rounds->send($cancelled, self::WAITER_ID));
        $this->assertSame(Order_ticket_line::STATUS_PENDING, $this->lines->get_info($line)['status']);

        $charged = $this->openTicket('cobrada');
        $this->addLine($charged, 'Empanada');
        $this->tickets->attach_sale($charged, 4242);
        $this->tickets->mark_charged_by_sale(4242);

        $this->assertNull($this->rounds->send($charged, self::WAITER_ID));
        $this->assertSame(0, $this->db->table('order_ticket_rounds')->countAllResults());
    }

    public function testADeliveredTicketCanStillReceiveMoreDishes(): void
    {
        $ticket = $this->openTicket();
        $this->addLine($ticket, 'Empanada');
        $this->rounds->send($ticket, self::WAITER_ID);
        $this->tickets->mark_delivered($ticket, self::WAITER_ID);

        $this->addLine($ticket, 'Café');

        $this->assertNotNull($this->rounds->send($ticket, self::WAITER_ID), 'A table that was served can order dessert.');
    }

    public function testAnUnknownTicketSendsNothing(): void
    {
        $this->assertNull($this->rounds->send(987654, self::WAITER_ID));
    }

    /**
     * Refusing must never leave a transaction open: the next request on this connection would run
     * inside it, and commit or lose work that has nothing to do with the ticket.
     */
    public function testARefusedSendLeavesNoTransactionOpen(): void
    {
        $ticket = $this->openTicket();

        $this->rounds->send($ticket, self::WAITER_ID);

        $this->assertSame(0, $this->db->transDepth);
    }

    /**
     * CodeIgniter only rolls back the outermost transaction. Inside another one, the rollback of an
     * empty round would silently not happen, so the call is refused loudly instead.
     */
    public function testSendingInsideAnotherTransactionIsRefused(): void
    {
        $ticket = $this->openTicket();
        $this->addLine($ticket, 'Empanada');

        $this->db->transBegin();

        try {
            $this->expectException(LogicException::class);
            $this->rounds->send($ticket, self::WAITER_ID);
        } finally {
            $this->db->transRollback();
        }
    }

    public function testTheRoundRecordsWhoSentItAndIsNotYetPrinted(): void
    {
        $ticket = $this->openTicket();
        $this->addLine($ticket, 'Empanada');

        $round = $this->rounds->get_info($this->rounds->send($ticket, self::WAITER_ID)['round_id']);

        $this->assertSame(self::WAITER_ID, (int) $round['sent_by']);
        $this->assertNotNull($round['sent_at']);
        $this->assertNull($round['printed_at'], 'Sent from the phone, not printed yet: a real, visible state.');
    }

    /**
     * The first print is what is kept; a reprint must not hide how long the kitchen waited.
     */
    public function testMarkingPrintedKeepsTheFirstPrintTime(): void
    {
        $ticket = $this->openTicket();
        $this->addLine($ticket, 'Empanada');
        $round_id = $this->rounds->send($ticket, self::WAITER_ID)['round_id'];

        $this->db->table('order_ticket_rounds')->where('round_id', $round_id)->update(['printed_at' => '2026-09-23 12:00:00']);

        $this->assertTrue($this->rounds->mark_printed($round_id), 'Asking again on a printed round is not a refusal.');
        $this->assertSame('2026-09-23 12:00:00', $this->rounds->get_info($round_id)['printed_at']);

        $this->assertFalse($this->rounds->mark_printed(999999));
    }

    public function testRoundsComeBackInTheOrderTheyWereSent(): void
    {
        $ticket = $this->openTicket();

        foreach (['uno', 'dos', 'tres'] as $dish) {
            $this->addLine($ticket, $dish);
            $this->rounds->send($ticket, self::WAITER_ID);
        }

        $this->assertSame([1, 2, 3], array_map('intval', array_column($this->rounds->get_rounds($ticket), 'number')));
    }

    private function openTicket(string $name = 'ANDREA'): int
    {
        return $this->tickets->create_ticket($name, self::LOCATION_ID, self::WAITER_ID);
    }

    private function addLine(int $ticket, string $dish): int
    {
        return $this->lines->add_line($ticket, 7, $dish, '1', '3500', '', self::WAITER_ID);
    }
}
