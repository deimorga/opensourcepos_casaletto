<?php

namespace Tests\Libraries;

use App\Libraries\Order_ticket_register;
use App\Libraries\Sale_lib;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The register's bridge to order tickets must never be what stops a till from charging.
 *
 * The ticket tables can be missing: SKIP_MIGRATIONS=1 starts a container behind its schema, and a
 * table can be lost by hand while the migrations table still says it is there -- in which case
 * is_latest() stays true and the till keeps selling. Every call the register makes then has to answer
 * the harmless default and nothing else.
 *
 * The real table is NOT dropped: the test database is shared between files. A double answers
 * "no tables" instead, which is exactly the question the bridge asks first.
 *
 * @internal
 */
final class OrderTicketRegisterTest extends CIUnitTestCase
{
    private Order_ticket_register $withoutTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutTables = new class () extends Order_ticket_register {
            protected function tables_present(): bool
            {
                return false;
            }
        };
    }

    /**
     * Charging is the one that matters: a paid sale, and a ticket register that cannot record it.
     * Nothing may be thrown back into postComplete().
     */
    public function testChargingASaleIsANoOpWithoutTheTicketTables(): void
    {
        $this->withoutTables->mark_charged(123);

        $this->addToAssertionCount(1);
    }

    /**
     * No ticket found means the register treats the sale as the ordinary sale it is: no pull, no
     * refusal to charge, no notice.
     */
    public function testNoSaleBelongsToATicketWithoutTheTicketTables(): void
    {
        $this->assertNull($this->withoutTables->ticket_for_sale(123));
    }

    public function testCancellingATabIsANoOpWithoutTheTicketTables(): void
    {
        $this->withoutTables->cancel_from_register(123, 1);

        $this->addToAssertionCount(1);
    }

    /**
     * A sale that was never saved has no ticket, tables or not: no query is worth making.
     */
    public function testANewSaleIsNeverATicket(): void
    {
        $bridge = new Order_ticket_register();

        $this->assertNull($bridge->ticket_for_sale(NEW_ENTRY));
        $this->assertNull($bridge->ticket_for_sale(0));
    }

    /**
     * The classification helpers read nothing but the row they are given.
     */
    public function testTheStatusHelpers(): void
    {
        $bridge = new Order_ticket_register();

        $this->assertTrue($bridge->is_live(['status' => 'open']));
        $this->assertTrue($bridge->is_live(['status' => 'delivered']));
        $this->assertFalse($bridge->is_live(['status' => 'charged']));
        $this->assertFalse($bridge->is_live(['status' => 'cancelled']));
        $this->assertTrue($bridge->is_cancelled(['status' => 'cancelled']));
        $this->assertFalse($bridge->is_cancelled(['status' => 'open']));
    }

    /**
     * The contract Sales relies on to avoid adding dishes twice: with nothing to pull, nothing is
     * added and nothing is reported skipped.
     */
    public function testNothingIsPulledForATicketThatDoesNotExist(): void
    {
        // A double: a real Sale_lib needs a session and a database, and the point is that it is never
        // touched when there are no ticket tables.
        $cart = $this->createMock(Sale_lib::class);
        $cart->expects($this->never())->method('add_item');
        $cart->expects($this->never())->method('add_item_kit');

        $result = $this->withoutTables->add_unbilled_to_cart($cart, 987654, 1);

        $this->assertSame(['added' => [], 'skipped' => 0], $result);
    }
}
