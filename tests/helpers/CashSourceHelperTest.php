<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The server-side decision for an expense's cash source.
 *
 * These run without a database on purpose: the rule is the security boundary, so it has to be
 * provable on its own rather than only through a controller that needs a live schema to boot.
 */
class CashSourceHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../../app/Helpers/cash_source_helper.php';
    }

    public function testTransferHasNoCashSourceEvenWhenOneIsSubmitted(): void
    {
        $this->assertNull(resolve_expense_cash_source('bank_transfer', true, 'collected'));
    }

    public function testCardPaymentByACashierHasNoCashSource(): void
    {
        $this->assertNull(resolve_expense_cash_source('debit', false, 'register'));
    }

    public function testUnrecognisedPaymentTypeHasNoCashSource(): void
    {
        $this->assertNull(resolve_expense_cash_source(null, true, 'register'));
    }

    public function testCashierCashExpenseAlwaysComesFromTheRegister(): void
    {
        $this->assertSame('register', resolve_expense_cash_source('cash', false, null));
    }

    public function testCashierCannotTalkTheServerIntoCollectedCash(): void
    {
        $this->assertSame('register', resolve_expense_cash_source('cash', false, 'collected'));
    }

    public function testAdministratorGetsTheSourceTheyChose(): void
    {
        $this->assertSame('collected', resolve_expense_cash_source('cash', true, 'collected'));
        $this->assertSame('register', resolve_expense_cash_source('cash', true, 'register'));
    }

    public function testAdministratorChoiceIsTrimmedBeforeItIsMatched(): void
    {
        $this->assertSame('collected', resolve_expense_cash_source('cash', true, '  collected  '));
    }

    /**
     * Cash-up 29 was saved with an opening amount of zero because nothing objected. An
     * administrator who did not answer the question gets a rejection, not the innocent default.
     */
    public function testAdministratorWhoChoseNothingIsRejected(): void
    {
        $this->assertFalse(resolve_expense_cash_source('cash', true, null));
        $this->assertFalse(resolve_expense_cash_source('cash', true, ''));
    }

    public function testValueOutsideTheAllowlistIsRejectedRatherThanStored(): void
    {
        $this->assertFalse(resolve_expense_cash_source('cash', true, 'petty_cash'));
        $this->assertFalse(resolve_expense_cash_source('cash', true, "register'; DROP TABLE"));
    }

    public function testOnlyTheTwoKnownCodesAreCashSources(): void
    {
        $this->assertSame(['register', 'collected'], array_keys(cash_source_code_map()));
    }

    /**
     * lang() echoes the key back when it is not defined, so asserting "not empty" alone would pass
     * on a language file that never got the key. The label has to be a translation.
     */
    public function testLabelsAreResolvedForTheKnownCodesAndEmptyOtherwise(): void
    {
        foreach (cash_source_code_map() as $code => $key) {
            $this->assertNotSame('', cash_source_label($code));
            $this->assertNotSame($key, cash_source_label($code));
        }

        $this->assertSame('', cash_source_label(null));
        $this->assertSame('', cash_source_label('petty_cash'));
    }

    /**
     * The dropdown has to put the code in the value attribute, because the value attribute is what
     * gets posted and then stored.
     */
    public function testDropdownOptionsAreKeyedByCodeNotByLabel(): void
    {
        $options = cash_source_options();

        $this->assertSame(array_keys(cash_source_code_map()), array_keys($options));

        foreach ($options as $code => $label) {
            $this->assertSame(cash_source_label($code), $label);
        }
    }

    /**
     * The label must never leak back out as a code: that round trip is exactly what left the
     * expenses payment filters matching nothing for months.
     */
    public function testLabelIsNotAValidCode(): void
    {
        $this->assertFalse(is_cash_source_code(cash_source_label('register')));
    }

    /**
     * Editing is not deciding. A cashier who opens an expense an administrator filed as collected
     * and fixes the description must not move that money back into the drawer: the cash-up would
     * come up short for a day with nothing on the record to explain it.
     */
    public function testEmployeeWhoCannotChooseKeepsTheStoredSource(): void
    {
        $this->assertSame('collected', resolve_expense_cash_source('cash', false, 'register', 'collected'));
        $this->assertSame('register', resolve_expense_cash_source('cash', false, 'collected', 'register'));
    }

    /**
     * With nothing on file there is no decision to preserve, and the drawer is the only pocket a
     * cashier reaches anyway. This is the new-expense path and the was-a-transfer-now-cash path.
     */
    public function testEmployeeWhoCannotChooseFallsBackToRegisterWithNothingStored(): void
    {
        $this->assertSame('register', resolve_expense_cash_source('cash', false, 'collected', null));
        $this->assertSame('register', resolve_expense_cash_source('cash', false, 'collected', ''));
    }

    /**
     * A stored value that is not a known code carries no decision worth keeping, so it must not
     * survive into the column the reconciliation reads.
     */
    public function testStoredGarbageDoesNotSurvive(): void
    {
        $this->assertSame('register', resolve_expense_cash_source('cash', false, null, 'petty_cash'));
        $this->assertSame('register', resolve_expense_cash_source('cash', false, null, cash_source_label('collected')));
    }

    /**
     * The stored value informs the employee who cannot choose; it never answers for the one who
     * can. An administrator who submits nothing is still refused, whatever is already on file.
     */
    public function testStoredValueDoesNotAnswerForAnAdministrator(): void
    {
        $this->assertFalse(resolve_expense_cash_source('cash', true, null, 'collected'));
        $this->assertFalse(resolve_expense_cash_source('cash', true, '', 'register'));
    }

    /**
     * A non-cash expense has no source regardless of what an earlier cash version of it stored.
     */
    public function testStoredValueIsDroppedWhenThePaymentIsNoLongerCash(): void
    {
        $this->assertNull(resolve_expense_cash_source('bank_transfer', false, null, 'collected'));
    }
}
