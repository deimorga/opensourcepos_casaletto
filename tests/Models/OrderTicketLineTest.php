<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Database\Migrations\Migration_AddOrderTicketLineBilling;
use App\Database\Migrations\Migration_AddOrderTickets;
use App\Models\Order_ticket_line;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The lines of an order ticket: what was ordered, and whether the kitchen already has it.
 *
 * What is worth proving here is the kitchen's side of the story. A line that reaches the kitchen
 * twice is a dish cooked twice, and a line that silently changes after the kitchen has it is a dish
 * cooked wrong. Everything below is one of those two failures, written down.
 *
 * Only order_ticket_lines is touched. Ticket and round ids are invented: the tables have no foreign
 * keys, on purpose (see the migration), so there is nothing to satisfy.
 */
class OrderTicketLineTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const TICKET       = 101;
    private const OTHER_TICKET = 202;
    private const EMPLOYEE     = 1;

    private Order_ticket_line $lines;

    protected function setUp(): void
    {
        parent::setUp();

        // The driver answers tableExists() and friends from a schema list built when the process
        // started, before the migration of this very run created the table.
        $this->db->resetDataCache();
        $this->db->table('order_ticket_lines')->truncate();

        $this->lines = model(Order_ticket_line::class, false);
    }

    // ---------------------------------------------------------------------------------------------
    // Schema agreement
    // ---------------------------------------------------------------------------------------------

    /**
     * CodeIgniter drops a field missing from $allowedFields without raising anything, and this
     * project has already lost data to that twice. The migration class is referenced here and never
     * from a data provider: providers are resolved before MigrationRunner has loaded the migration
     * files, which are not PSR-4 loadable.
     */
    public function testAllowedFieldsCoverEveryWritableColumn(): void
    {
        // The table was built by two migrations; the model has to cover both.
        $expected = array_merge(
            Migration_AddOrderTickets::WRITABLE_COLUMNS_LINES,
            Migration_AddOrderTicketLineBilling::ADDED_COLUMNS
        );
        sort($expected);

        $allowed = $this->lines->allowedFields;
        sort($allowed);

        $this->assertSame($expected, $allowed);
    }

    /**
     * The same check against the live table, so a column added later to the migration's createTable
     * but forgotten in its constant still fails here.
     */
    public function testAllowedFieldsMatchTheColumnsOfTheTable(): void
    {
        $columns  = $this->db->getFieldNames('order_ticket_lines');
        $writable = array_values(array_diff($columns, ['order_ticket_line_id']));
        sort($writable);

        $allowed = $this->lines->allowedFields;
        sort($allowed);

        $this->assertSame($writable, $allowed);
    }

    // ---------------------------------------------------------------------------------------------
    // add_line
    // ---------------------------------------------------------------------------------------------

    public function testAddLineStartsPendingAndUnsent(): void
    {
        $id = $this->addLine(self::TICKET, '2', '3500');

        $this->assertGreaterThan(0, $id);

        $line = $this->lines->get_info($id);

        $this->assertNotNull($line);
        $this->assertSame(Order_ticket_line::STATUS_PENDING, $line['status']);
        $this->assertNull($line['round_id']);
        $this->assertSame(0, (int) $line['changed_after_send']);
        $this->assertSame('2.000', (string) $line['quantity']);
        $this->assertSame('3500.00', (string) $line['unit_price']);
        $this->assertSame(self::TICKET, (int) $line['order_ticket_id']);
        $this->assertSame(self::EMPLOYEE, (int) $line['captured_by']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $line['captured_at']);
    }

    public function testAddLineTrimsNameAndNote(): void
    {
        $id = $this->lines->add_line(self::TICKET, 7, '  Sándwich cubano  ', '1', '12000', '  sin cebolla ', self::EMPLOYEE);

        $line = $this->lines->get_info($id);

        $this->assertSame('Sándwich cubano', $line['item_name']);
        $this->assertSame('sin cebolla', $line['kitchen_note']);
    }

    public function testAddLineRefusesZeroQuantity(): void
    {
        $this->assertSame(0, $this->addLine(self::TICKET, '0', '3500'));
        $this->assertSame(0, $this->addLine(self::TICKET, '0.000', '3500'));
        $this->assertSame(0, $this->countRows());
    }

    public function testAddLineRefusesNegativeQuantity(): void
    {
        $this->assertSame(0, $this->addLine(self::TICKET, '-1', '3500'));
        $this->assertSame(0, $this->countRows());
    }

    public function testAddLineRefusesNonNumericQuantity(): void
    {
        $this->assertSame(0, $this->addLine(self::TICKET, 'dos', '3500'));
        $this->assertSame(0, $this->addLine(self::TICKET, '', '3500'));
        $this->assertSame(0, $this->addLine(self::TICKET, '1e3', '3500'));
        $this->assertSame(0, $this->countRows());
    }

    /**
     * Half a kilo of cheese has to stay half a kilo: quantities are decimal strings at scale 3.
     */
    public function testAddLineAcceptsADecimalQuantity(): void
    {
        $id = $this->addLine(self::TICKET, '0.500', '3500');

        $this->assertGreaterThan(0, $id);
        $this->assertSame('0.500', (string) $this->lines->get_info($id)['quantity']);
    }

    public function testAddLineRefusesNegativeOrNonNumericPrice(): void
    {
        $this->assertSame(0, $this->addLine(self::TICKET, '1', '-100'));
        $this->assertSame(0, $this->addLine(self::TICKET, '1', 'gratis'));
        $this->assertSame(0, $this->addLine(self::TICKET, '1', ''));
        $this->assertSame(0, $this->countRows());
    }

    /**
     * A gift line (the 3x2 promotion) is a real line at price zero, so zero must be accepted.
     */
    public function testAddLineAcceptsAZeroPrice(): void
    {
        $id = $this->addLine(self::TICKET, '1', '0');

        $this->assertGreaterThan(0, $id);
        $this->assertSame('0.00', (string) $this->lines->get_info($id)['unit_price']);
    }

    public function testKitchenNoteIsCutAt255CharactersWithoutBreakingMultibyte(): void
    {
        $note = str_repeat('ñá', 150);   // 300 characters, 600 bytes
        $this->assertSame(300, mb_strlen($note));

        $id = $this->lines->add_line(self::TICKET, 7, 'Arepa', '1', '5000', $note, self::EMPLOYEE);

        $stored = (string) $this->lines->get_info($id)['kitchen_note'];

        $this->assertSame(255, mb_strlen($stored));
        $this->assertSame(mb_substr($note, 0, 255), $stored);
    }

    // ---------------------------------------------------------------------------------------------
    // get_pending / get_lines / get_by_round (D8)
    // ---------------------------------------------------------------------------------------------

    public function testPendingExcludesVoidedAndAlreadySentLines(): void
    {
        $sent = $this->addLine(self::TICKET, '1', '1000');
        $this->lines->assign_to_round(self::TICKET, 1);

        $voided  = $this->addLine(self::TICKET, '1', '2000');
        $pending = $this->addLine(self::TICKET, '1', '3000');
        $this->assertTrue($this->lines->void_line($voided));

        $ids = $this->idsOf($this->lines->get_pending(self::TICKET));

        $this->assertSame([$pending], $ids);
        $this->assertNotContains($sent, $ids);
    }

    public function testPendingOnlyReturnsItsOwnTicket(): void
    {
        $mine = $this->addLine(self::TICKET, '1', '1000');
        $this->addLine(self::OTHER_TICKET, '1', '1000');

        $this->assertSame([$mine], $this->idsOf($this->lines->get_pending(self::TICKET)));
    }

    public function testGetLinesIsOrderedAndCanLeaveOutVoided(): void
    {
        $first  = $this->addLine(self::TICKET, '1', '1000');
        $second = $this->addLine(self::TICKET, '1', '2000');
        $third  = $this->addLine(self::TICKET, '1', '3000');
        $this->lines->void_line($second);

        $this->assertSame([$first, $second, $third], $this->idsOf($this->lines->get_lines(self::TICKET)));
        $this->assertSame([$first, $third], $this->idsOf($this->lines->get_lines(self::TICKET, false)));
    }

    public function testGetByRoundExcludesVoidedLines(): void
    {
        $kept   = $this->addLine(self::TICKET, '1', '1000');
        $voided = $this->addLine(self::TICKET, '1', '2000');
        $this->lines->assign_to_round(self::TICKET, 5);
        $this->lines->void_line($voided);

        $this->assertSame([$kept], $this->idsOf($this->lines->get_by_round(5)));
    }

    // ---------------------------------------------------------------------------------------------
    // assign_to_round
    // ---------------------------------------------------------------------------------------------

    /**
     * The double tap on "send to kitchen": the second press finds nothing new and must not move the
     * lines the first press already sent.
     */
    public function testAssigningTwiceDoesNotReassignTheSameLine(): void
    {
        $a = $this->addLine(self::TICKET, '1', '1000');
        $b = $this->addLine(self::TICKET, '2', '2000');

        $this->assertSame(2, $this->lines->assign_to_round(self::TICKET, 1));
        $this->assertSame(0, $this->lines->assign_to_round(self::TICKET, 2));

        foreach ([$a, $b] as $id) {
            $line = $this->lines->get_info($id);

            $this->assertSame(1, (int) $line['round_id']);
            $this->assertSame(Order_ticket_line::STATUS_SENT, $line['status']);
        }

        $this->assertSame([], $this->lines->get_by_round(2));
    }

    public function testASecondRoundTakesOnlyWhatWasAdded(): void
    {
        $first = $this->addLine(self::TICKET, '1', '1000');
        $this->lines->assign_to_round(self::TICKET, 1);

        $added = $this->addLine(self::TICKET, '1', '2000');

        $this->assertSame(1, $this->lines->assign_to_round(self::TICKET, 2));
        $this->assertSame([$first], $this->idsOf($this->lines->get_by_round(1)));
        $this->assertSame([$added], $this->idsOf($this->lines->get_by_round(2)));
    }

    public function testAssignTouchesOnlyItsOwnTicket(): void
    {
        $mine   = $this->addLine(self::TICKET, '1', '1000');
        $theirs = $this->addLine(self::OTHER_TICKET, '1', '1000');

        $this->assertSame(1, $this->lines->assign_to_round(self::TICKET, 1));

        $this->assertSame(1, (int) $this->lines->get_info($mine)['round_id']);

        $other = $this->lines->get_info($theirs);
        $this->assertNull($other['round_id']);
        $this->assertSame(Order_ticket_line::STATUS_PENDING, $other['status']);
    }

    public function testAVoidedPendingLineIsNeverSent(): void
    {
        $voided = $this->addLine(self::TICKET, '1', '1000');
        $this->lines->void_line($voided);

        $this->assertSame(0, $this->lines->assign_to_round(self::TICKET, 1));

        $line = $this->lines->get_info($voided);
        $this->assertNull($line['round_id']);
        $this->assertSame(Order_ticket_line::STATUS_VOIDED, $line['status']);
    }

    // ---------------------------------------------------------------------------------------------
    // void_line
    // ---------------------------------------------------------------------------------------------

    /**
     * The kitchen already acted on a sent line. Voiding it must leave the row -- and say that it
     * changed after sending, so whoever reads the ticket knows the kitchen has to be told.
     */
    public function testVoidingASentLineKeepsTheRow(): void
    {
        $id = $this->addLine(self::TICKET, '1', '1000');
        $this->lines->assign_to_round(self::TICKET, 1);

        $this->assertTrue($this->lines->void_line($id));

        $line = $this->lines->get_info($id);

        $this->assertNotNull($line);
        $this->assertSame(Order_ticket_line::STATUS_VOIDED, $line['status']);
        $this->assertSame(1, (int) $line['changed_after_send']);
        $this->assertSame(1, (int) $line['round_id']);
        $this->assertSame(1, $this->countRows());
    }

    public function testVoidingAPendingLineDoesNotFlagIt(): void
    {
        $id = $this->addLine(self::TICKET, '1', '1000');

        $this->assertTrue($this->lines->void_line($id));

        $line = $this->lines->get_info($id);
        $this->assertSame(Order_ticket_line::STATUS_VOIDED, $line['status']);
        $this->assertSame(0, (int) $line['changed_after_send']);
    }

    public function testVoidingTwiceIsRefused(): void
    {
        $id = $this->addLine(self::TICKET, '1', '1000');

        $this->assertTrue($this->lines->void_line($id));
        $this->assertFalse($this->lines->void_line($id));
    }

    public function testVoidingAMissingLineIsRefused(): void
    {
        $this->assertFalse($this->lines->void_line(999999));
    }

    // ---------------------------------------------------------------------------------------------
    // edit_line (D9)
    // ---------------------------------------------------------------------------------------------

    public function testEditingASentLineFlagsIt(): void
    {
        $id = $this->addLine(self::TICKET, '1', '1000');
        $this->lines->assign_to_round(self::TICKET, 1);

        $this->assertTrue($this->lines->edit_line($id, ['quantity' => '3', 'kitchen_note' => 'bien asado']));

        $line = $this->lines->get_info($id);

        $this->assertSame('3.000', (string) $line['quantity']);
        $this->assertSame('bien asado', $line['kitchen_note']);
        $this->assertSame(1, (int) $line['changed_after_send']);
        $this->assertSame(Order_ticket_line::STATUS_SENT, $line['status']);
    }

    /**
     * A phone resubmitting the same form -- a double tap, a reload -- is not a change to the dish, and
     * must not tell the kitchen it was altered. "1" against a stored 1.000 is the same quantity.
     */
    public function testResubmittingASentLineWithTheSameValuesDoesNotFlagIt(): void
    {
        $id = $this->addLine(self::TICKET, '1', '1000', 'sin cebolla');
        $this->lines->assign_to_round(self::TICKET, 1);

        $this->assertTrue($this->lines->edit_line($id, ['quantity' => '1', 'kitchen_note' => 'sin cebolla']));

        $this->assertSame(0, (int) $this->lines->get_info($id)['changed_after_send']);
    }

    /**
     * The note column's collation is case-insensitive, so a naive comparison would treat this as "no
     * change". The kitchen reads the paper, and the paper changed.
     */
    public function testChangingOnlyTheCaseOfTheNoteOfASentLineIsStillAChange(): void
    {
        $id = $this->addLine(self::TICKET, '1', '1000', 'sin cebolla');
        $this->lines->assign_to_round(self::TICKET, 1);

        $this->assertTrue($this->lines->edit_line($id, ['kitchen_note' => 'SIN CEBOLLA']));

        $line = $this->lines->get_info($id);
        $this->assertSame('SIN CEBOLLA', $line['kitchen_note']);
        $this->assertSame(1, (int) $line['changed_after_send']);
    }

    public function testEditingAPendingLineDoesNotFlagIt(): void
    {
        $id = $this->addLine(self::TICKET, '1', '1000');

        $this->assertTrue($this->lines->edit_line($id, ['quantity' => '0.750']));

        $line = $this->lines->get_info($id);

        $this->assertSame('0.750', (string) $line['quantity']);
        $this->assertSame(0, (int) $line['changed_after_send']);
        $this->assertSame(Order_ticket_line::STATUS_PENDING, $line['status']);
    }

    public function testEditLineIgnoresKeysItDoesNotAllow(): void
    {
        $id = $this->addLine(self::TICKET, '1', '1000');

        $this->assertTrue($this->lines->edit_line($id, [
            'quantity'   => '2',
            'unit_price' => '1',
            'round_id'   => 9,
            'status'     => Order_ticket_line::STATUS_SENT,
        ]));

        $line = $this->lines->get_info($id);

        $this->assertSame('2.000', (string) $line['quantity']);
        $this->assertSame('1000.00', (string) $line['unit_price']);
        $this->assertNull($line['round_id']);
        $this->assertSame(Order_ticket_line::STATUS_PENDING, $line['status']);
    }

    public function testEditLineWithNothingAllowedIsRefused(): void
    {
        $id = $this->addLine(self::TICKET, '1', '1000');

        $this->assertFalse($this->lines->edit_line($id, []));
        $this->assertFalse($this->lines->edit_line($id, ['unit_price' => '1', 'round_id' => 9]));

        $this->assertSame('1000.00', (string) $this->lines->get_info($id)['unit_price']);
    }

    public function testEditLineRefusesAnInvalidQuantityAndWritesNothing(): void
    {
        $id = $this->addLine(self::TICKET, '1', '1000', 'sin hielo');

        $this->assertFalse($this->lines->edit_line($id, ['quantity' => '0', 'kitchen_note' => 'otra']));
        $this->assertFalse($this->lines->edit_line($id, ['quantity' => '-2']));
        $this->assertFalse($this->lines->edit_line($id, ['quantity' => 'mucho']));

        $line = $this->lines->get_info($id);
        $this->assertSame('1.000', (string) $line['quantity']);
        $this->assertSame('sin hielo', $line['kitchen_note']);
    }

    public function testAVoidedLineCannotBeEdited(): void
    {
        $id = $this->addLine(self::TICKET, '1', '1000');
        $this->lines->void_line($id);

        $this->assertFalse($this->lines->edit_line($id, ['quantity' => '2']));
        $this->assertSame('1.000', (string) $this->lines->get_info($id)['quantity']);
    }

    public function testEditingAMissingLineIsRefused(): void
    {
        $this->assertFalse($this->lines->edit_line(999999, ['quantity' => '2']));
    }

    // ---------------------------------------------------------------------------------------------
    // edit_line_seen: two waiters on the same ticket (Entrega 2)
    // ---------------------------------------------------------------------------------------------

    public function testEditingWithWhatWasSeenSucceeds(): void
    {
        $id = $this->addLine(self::TICKET, '1', '1000', 'sin cebolla');

        $result = $this->lines->edit_line_seen(
            $id,
            ['quantity' => '2', 'kitchen_note' => 'bien asado'],
            ['quantity' => '1', 'kitchen_note' => 'sin cebolla'],
        );

        $this->assertSame(Order_ticket_line::EDIT_OK, $result);

        $line = $this->lines->get_info($id);
        $this->assertSame('2.000', (string) $line['quantity']);
        $this->assertSame('bien asado', $line['kitchen_note']);
    }

    /**
     * THE case of this section. Waiter A and waiter B both opened the ticket while the dish said 1.
     * A saves 3. B, still looking at 1, saves 2. Before the check, B silently erased A's change and A
     * never knew. Now B is told, and A's value is what the kitchen gets.
     */
    public function testEditingAfterSomeoneElseChangedTheLineIsAConflictAndWritesNothing(): void
    {
        $id = $this->addLine(self::TICKET, '1', '1000', 'sin cebolla');
        $this->lines->assign_to_round(self::TICKET, 1);
        $seenByBoth = ['quantity' => '1', 'kitchen_note' => 'sin cebolla'];

        $this->assertSame(
            Order_ticket_line::EDIT_OK,
            $this->lines->edit_line_seen($id, ['quantity' => '3'], $seenByBoth),
            'Waiter A saves first.',
        );
        $this->db->table('order_ticket_lines')->where('order_ticket_line_id', $id)->update(['changed_after_send' => 0]);

        $this->assertSame(
            Order_ticket_line::EDIT_CONFLICT,
            $this->lines->edit_line_seen($id, ['quantity' => '2', 'kitchen_note' => 'doble'], $seenByBoth),
            'Waiter B saw 1, the line says 3: B must be told, not obeyed.',
        );

        $line = $this->lines->get_info($id);
        $this->assertSame('3.000', (string) $line['quantity'], "A's change survives.");
        $this->assertSame('sin cebolla', $line['kitchen_note'], 'Nothing of the refused edit was written.');
        $this->assertSame(0, (int) $line['changed_after_send'], 'A refused edit does not tell the kitchen anything changed.');
    }

    /**
     * The screen shows "2"; the column holds 2.000. Comparing them as text would turn every edit into
     * a conflict.
     */
    public function testSeenQuantityComparesAsANumber(): void
    {
        $id = $this->addLine(self::TICKET, '2', '1000');

        $this->assertSame(
            Order_ticket_line::EDIT_OK,
            $this->lines->edit_line_seen($id, ['quantity' => '5'], ['quantity' => '2', 'kitchen_note' => '']),
        );
        $this->assertSame('5.000', (string) $this->lines->get_info($id)['quantity']);
    }

    /**
     * The note column's collation ignores case, so without BINARY a waiter who saw "sin cebolla"
     * would pass the check against "SIN CEBOLLA" that someone else wrote -- and erase it.
     */
    public function testSeenNoteComparesExactly(): void
    {
        $id = $this->addLine(self::TICKET, '1', '1000', 'SIN CEBOLLA');

        $this->assertSame(
            Order_ticket_line::EDIT_CONFLICT,
            $this->lines->edit_line_seen($id, ['quantity' => '2'], ['quantity' => '1', 'kitchen_note' => 'sin cebolla']),
        );
        $this->assertSame('1.000', (string) $this->lines->get_info($id)['quantity']);
    }

    /**
     * A double tap or a reload sends the same form twice, and the second one carries the "seen" of
     * before the first. The line already says what was asked: that is done, not a conflict, and the
     * waiter must not be told somebody else changed their own dish.
     */
    public function testResubmittingTheSameEditIsOkNotAConflict(): void
    {
        $id      = $this->addLine(self::TICKET, '1', '1000', 'sin cebolla');
        $changes = ['quantity' => '2', 'kitchen_note' => 'sin cebolla ni tomate'];
        $seen    = ['quantity' => '1', 'kitchen_note' => 'sin cebolla'];

        $this->assertSame(Order_ticket_line::EDIT_OK, $this->lines->edit_line_seen($id, $changes, $seen));
        $this->assertSame(Order_ticket_line::EDIT_OK, $this->lines->edit_line_seen($id, $changes, $seen));

        $line = $this->lines->get_info($id);
        $this->assertSame('2.000', (string) $line['quantity']);
        $this->assertSame('sin cebolla ni tomate', $line['kitchen_note']);
    }

    public function testEditingAVoidedLineIsRefused(): void
    {
        $id = $this->addLine(self::TICKET, '1', '1000');
        $this->lines->void_line($id);

        $this->assertSame(
            Order_ticket_line::EDIT_REFUSED,
            $this->lines->edit_line_seen($id, ['quantity' => '2'], ['quantity' => '1', 'kitchen_note' => '']),
            'Voided is not a conflict: there is nothing left to edit.',
        );
        $this->assertSame(
            Order_ticket_line::EDIT_REFUSED,
            $this->lines->edit_line_seen(999999, ['quantity' => '2'], ['quantity' => '1', 'kitchen_note' => '']),
        );
        $this->assertSame('1.000', (string) $this->lines->get_info($id)['quantity']);
    }

    /**
     * Invalid input is refused before anything is compared, and a malformed "seen" is invalid input:
     * treating it as "no check" would quietly turn a guarded edit into an unguarded one.
     */
    public function testInvalidInputOrAnIncompleteSeenIsRefused(): void
    {
        $id = $this->addLine(self::TICKET, '1', '1000');

        $this->assertSame(Order_ticket_line::EDIT_REFUSED, $this->lines->edit_line_seen($id, ['quantity' => '0'], ['quantity' => '1', 'kitchen_note' => '']));
        $this->assertSame(Order_ticket_line::EDIT_REFUSED, $this->lines->edit_line_seen($id, [], ['quantity' => '1', 'kitchen_note' => '']));
        $this->assertSame(Order_ticket_line::EDIT_REFUSED, $this->lines->edit_line_seen($id, ['quantity' => '2'], ['quantity' => '1']));
        $this->assertSame(Order_ticket_line::EDIT_REFUSED, $this->lines->edit_line_seen($id, ['quantity' => '2'], ['quantity' => 'uno', 'kitchen_note' => '']));

        $this->assertSame('1.000', (string) $this->lines->get_info($id)['quantity']);
    }

    /**
     * Without a "seen", edit_line_seen() is edit_line(): the last write wins, as before.
     */
    public function testEditingWithoutSeenIsUnchecked(): void
    {
        $id = $this->addLine(self::TICKET, '1', '1000');
        $this->lines->edit_line($id, ['quantity' => '3']);

        $this->assertSame(Order_ticket_line::EDIT_OK, $this->lines->edit_line_seen($id, ['quantity' => '2'], null));
        $this->assertSame('2.000', (string) $this->lines->get_info($id)['quantity']);
    }

    /**
     * Adding is the half of the two-waiter problem that needs no check: every dish is its own row,
     * an INSERT each, so two waiters adding at once can never overwrite one another.
     */
    public function testTwoWaitersAddingDifferentDishesNeverCollide(): void
    {
        $fromA = $this->lines->add_line(self::TICKET, 7, 'Empanada', '1', '3500', '', 1);
        $fromB = $this->lines->add_line(self::TICKET, 8, 'Jugo de mora', '2', '6000', 'sin hielo', 2);

        $this->assertGreaterThan(0, $fromA);
        $this->assertGreaterThan(0, $fromB);
        $this->assertNotSame($fromA, $fromB);

        $lines = $this->lines->get_lines(self::TICKET);
        $this->assertSame([$fromA, $fromB], $this->idsOf($lines));
        $this->assertSame(['Empanada', 'Jugo de mora'], array_column($lines, 'item_name'));
    }

    /**
     * The list screen's numbers, for every ticket in one query. A voided line is not a dish, and
     * "pending" must be exactly what the next send would carry.
     */
    public function testCountByTicketCountsDishesAndPendingForEveryTicketAskedFor(): void
    {
        $this->addLine(self::TICKET, '1', '1000');
        $this->addLine(self::TICKET, '1', '1000');
        $this->lines->assign_to_round(self::TICKET, 1);
        $this->addLine(self::TICKET, '1', '1000');
        $this->lines->void_line($this->addLine(self::TICKET, '1', '1000'));

        $this->addLine(self::OTHER_TICKET, '2', '500');

        $counts = $this->lines->count_by_ticket([self::TICKET, self::OTHER_TICKET, 303]);

        $this->assertSame(['dishes' => 3, 'pending' => 1], $counts[self::TICKET]);
        $this->assertSame(['dishes' => 1, 'pending' => 1], $counts[self::OTHER_TICKET]);
        $this->assertSame(['dishes' => 0, 'pending' => 0], $counts[303], 'A ticket with no lines still comes back, with zeros.');
        $this->assertSame([], $this->lines->count_by_ticket([]));
    }

    // ---------------------------------------------------------------------------------------------
    // The register's side: billing
    // ---------------------------------------------------------------------------------------------

    /**
     * What the register pulls into the cart: never billed, not voided. A dish voided before it was
     * ever billed simply never reaches the sale.
     */
    public function testUnbilledExcludesBilledAndVoidedLines(): void
    {
        $billed  = $this->addLine(self::TICKET, '1', '1000');
        $waiting = $this->addLine(self::TICKET, '1', '1000');
        $this->lines->void_line($this->addLine(self::TICKET, '1', '1000'));
        $this->lines->mark_billed([$billed]);

        $this->assertSame([$waiting], $this->idsOf($this->lines->get_unbilled(self::TICKET)));
    }

    /**
     * Stamped once. The second stamp is a no-op and the first time is kept -- it is when the dish
     * entered the sale.
     */
    public function testALineIsBilledOnlyOnce(): void
    {
        $id = $this->addLine(self::TICKET, '1', '1000');

        $this->assertSame(1, $this->lines->mark_billed([$id]));
        $this->db->table('order_ticket_lines')->where('order_ticket_line_id', $id)->update(['billed_at' => '2026-09-23 12:00:00']);

        $this->assertSame(0, $this->lines->mark_billed([$id]));
        $this->assertSame('2026-09-23 12:00:00', $this->lines->get_info($id)['billed_at']);
        $this->assertSame(0, $this->lines->mark_billed([]));
    }

    /**
     * D9 on the cashier's side: a dish the register already has changed. Flagged, never applied to
     * the cart behind the cashier's back -- and only when something really changed.
     */
    public function testEditingABilledLineFlagsItForTheCashierOnlyWhenSomethingChanges(): void
    {
        $id = $this->addLine(self::TICKET, '1', '1000', 'sin cebolla');
        $this->lines->mark_billed([$id]);

        $this->lines->edit_line($id, ['quantity' => '1', 'kitchen_note' => 'sin cebolla']);
        $this->assertSame(0, (int) $this->lines->get_info($id)['changed_after_billed'], 'A resubmitted form is not a change.');

        $this->lines->edit_line($id, ['quantity' => '2']);
        $this->assertSame(1, (int) $this->lines->get_info($id)['changed_after_billed']);
    }

    public function testEditingAnUnbilledLineDoesNotConcernTheCashier(): void
    {
        $id = $this->addLine(self::TICKET, '1', '1000');

        $this->lines->edit_line($id, ['quantity' => '3']);

        $this->assertSame(0, (int) $this->lines->get_info($id)['changed_after_billed'], 'The register will pull the new quantity; nothing to tell.');
    }

    /**
     * A voided dish the register already has is still in the cart until the cashier removes it.
     */
    public function testVoidingABilledLineFlagsItButVoidingAnUnbilledOneDoesNot(): void
    {
        $billed   = $this->addLine(self::TICKET, '1', '1000');
        $unbilled = $this->addLine(self::TICKET, '1', '1000');
        $this->lines->mark_billed([$billed]);

        $this->lines->void_line($billed);
        $this->lines->void_line($unbilled);

        $this->assertSame(1, (int) $this->lines->get_info($billed)['changed_after_billed']);
        $this->assertSame(0, (int) $this->lines->get_info($unbilled)['changed_after_billed']);
        $this->assertSame([$billed], $this->idsOf($this->lines->get_billing_changes(self::TICKET)));
    }

    public function testAcknowledgingClearsTheNoticeOfThatTicketOnly(): void
    {
        $mine   = $this->addLine(self::TICKET, '1', '1000');
        $theirs = $this->addLine(self::OTHER_TICKET, '1', '1000');
        $this->lines->mark_billed([$mine, $theirs]);
        $this->lines->void_line($mine);
        $this->lines->void_line($theirs);

        $this->assertSame(1, $this->lines->acknowledge_billing_changes(self::TICKET));

        $this->assertSame([], $this->lines->get_billing_changes(self::TICKET));
        $this->assertCount(1, $this->lines->get_billing_changes(self::OTHER_TICKET));
    }

    /**
     * "2.000 x SANDWICH" on a waiter's phone and on the kitchen's paper reads worse than "2 x". The
     * trailing zeros go; a real fraction -- half a kilo -- stays.
     */
    public function testAQuantityIsShownWithoutItsTrailingZeros(): void
    {
        $this->assertSame('2', Order_ticket_line::display_quantity('2.000'));
        $this->assertSame('0.5', Order_ticket_line::display_quantity('0.500'));
        $this->assertSame('1.25', Order_ticket_line::display_quantity('1.250'));
        $this->assertSame('10', Order_ticket_line::display_quantity('10.000'), 'Only zeros AFTER the point go.');
        $this->assertSame('100', Order_ticket_line::display_quantity('100'));
        $this->assertSame('0.001', Order_ticket_line::display_quantity('0.001'));
    }

    public function testGetInfoOfAMissingLineIsNull(): void
    {
        $this->assertNull($this->lines->get_info(999999));
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------

    private function addLine(int $ticket, string $quantity, string $price, string $note = ''): int
    {
        return $this->lines->add_line($ticket, 7, 'Empanada', $quantity, $price, $note, self::EMPLOYEE);
    }

    private function countRows(): int
    {
        return $this->db->table('order_ticket_lines')->countAllResults();
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    private function idsOf(array $rows): array
    {
        return array_map(static fn (array $row): int => (int) $row['order_ticket_line_id'], $rows);
    }
}
