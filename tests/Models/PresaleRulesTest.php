<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\Presale;
use App\Models\Presale_campaign;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\OSPOS;

/**
 * The pure rules of the presales module: the price a campaign sells at, how money is rounded, the
 * least first instalment, and the state a presale is in on a given day.
 *
 * Pure on purpose -- everything is passed in -- so the edge cases are tested here without building a
 * presale in the database. The database trait is only there because config(OSPOS::class) loads the
 * business's settings from it.
 *
 * @internal
 */
final class PresaleRulesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';
    private mixed $decimalsBefore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->decimalsBefore = config(OSPOS::class)->settings['currency_decimals'] ?? null;
    }

    protected function tearDown(): void
    {
        // The settings object is shared by every test file in the run.
        if ($this->decimalsBefore === null) {
            unset(config(OSPOS::class)->settings['currency_decimals']);
        } else {
            config(OSPOS::class)->settings['currency_decimals'] = $this->decimalsBefore;
        }

        parent::tearDown();
    }

    private function decimals(int $decimals): void
    {
        config(OSPOS::class)->settings['currency_decimals'] = $decimals;
    }

    // ---------------------------------------------------------------------------------------------
    // Price (D14, D19, T14)
    // ---------------------------------------------------------------------------------------------

    public function testAProductWithNoPriceOrDiscountOfItsOwnTakesTheCampaignDiscount(): void
    {
        $this->decimals(0);

        $price = Presale_campaign::effective_price(
            ['base_price' => '45000.00', 'campaign_price' => null, 'discount_percent' => null],
            ['discount_percent' => '10.00'],
        );

        $this->assertSame('40500.00', $price);
    }

    public function testAProductDiscountReplacesTheCampaignsEvenWhenItIsZero(): void
    {
        $this->decimals(0);

        $campaign = ['discount_percent' => '10.00'];

        $this->assertSame('43200.00', Presale_campaign::effective_price(['base_price' => '48000.00', 'campaign_price' => null, 'discount_percent' => '10.00'], $campaign));
        $this->assertSame(
            '48000.00',
            Presale_campaign::effective_price(['base_price' => '48000.00', 'campaign_price' => null, 'discount_percent' => '0.00'], $campaign),
            'A product discount of 0 means "no discount for this one", not "inherit".',
        );
    }

    public function testACampaignPriceWinsOverEveryDiscount(): void
    {
        $price = Presale_campaign::effective_price(
            ['base_price' => '45000.00', 'campaign_price' => '39900.00', 'discount_percent' => '50.00'],
            ['discount_percent' => '10.00'],
        );

        $this->assertSame('39900.00', $price);
    }

    /**
     * A peso business has no cents. The presale total has to be what the register will charge, and the
     * register rounds to the currency decimals.
     */
    public function testThePriceIsRoundedToTheBusinessCurrencyDecimalsHalfUp(): void
    {
        $item = ['base_price' => '33335.00', 'campaign_price' => null, 'discount_percent' => '10.00'];

        $this->decimals(0);
        $this->assertSame('30002.00', Presale_campaign::effective_price($item, ['discount_percent' => '0']), '30001.5 rounds up.');

        $this->decimals(2);
        $this->assertSame('30001.50', Presale_campaign::effective_price($item, ['discount_percent' => '0']));
    }

    public function testRoundingNeverGoesBeyondTwoDecimals(): void
    {
        $this->decimals(4);

        $this->assertSame('10.01', Presale_campaign::round_money('10.005'));
    }

    // ---------------------------------------------------------------------------------------------
    // Least first instalment (D21, T17)
    // ---------------------------------------------------------------------------------------------

    public function testTheMinimumIsRoundedUpSoThirtyPercentIsNeverMetByLess(): void
    {
        $this->decimals(0);

        $this->assertSame('30001.00', Presale::minimum_initial('100001.00', '30.00'), '30000.30 rounds UP to 30001.');
        $this->assertSame('30000.00', Presale::minimum_initial('100000.00', '30.00'));
    }

    public function testAZeroPercentMinimumMeansNoMinimum(): void
    {
        $this->assertSame('0.00', Presale::minimum_initial('100000.00', '0.00'));
    }

    public function testTheMinimumRespectsCents(): void
    {
        $this->decimals(2);

        $this->assertSame('30.01', Presale::minimum_initial('100.01', '30'));
    }

    // ---------------------------------------------------------------------------------------------
    // Derived state (T6)
    // ---------------------------------------------------------------------------------------------

    /**
     * @return list<array{due_date: string, amount: string}>
     */
    private function plan(): array
    {
        return [
            ['due_date' => '2026-11-01', 'amount' => '30000.00'],
            ['due_date' => '2026-11-15', 'amount' => '35000.00'],
            ['due_date' => '2026-12-01', 'amount' => '35000.00'],
        ];
    }

    public function testAnInstalmentDueTodayIsNotLateYet(): void
    {
        $state = Presale::derive(Presale::STATUS_OPEN, '100000.00', '30000.00', $this->plan(), '2026-11-15');

        $this->assertSame(Presale::STATE_UP_TO_DATE, $state['state']);
        $this->assertSame('30000.00', $state['due_to_date']);
        $this->assertSame('2026-11-15', $state['next_due_date']);
        $this->assertSame('35000.00', $state['next_due_amount']);
        $this->assertSame(0, $state['days_late']);
    }

    public function testAnInstalmentDueYesterdayAndNotCoveredIsLate(): void
    {
        $state = Presale::derive(Presale::STATUS_OPEN, '100000.00', '30000.00', $this->plan(), '2026-11-16');

        $this->assertSame(Presale::STATE_LATE, $state['state']);
        $this->assertSame('65000.00', $state['due_to_date']);
        $this->assertSame(1, $state['days_late']);
        $this->assertSame('2026-11-15', $state['next_due_date']);
    }

    /**
     * Payments are not tied to an instalment: a partial payment counts towards the oldest one still
     * open, and the presale is late until the total catches up.
     */
    public function testAPartialPaymentLeavesItLateWithWhatIsStillMissing(): void
    {
        $state = Presale::derive(Presale::STATUS_OPEN, '100000.00', '50000.00', $this->plan(), '2026-11-20');

        $this->assertSame(Presale::STATE_LATE, $state['state']);
        $this->assertSame(5, $state['days_late']);
        $this->assertSame('15000.00', $state['next_due_amount']);
    }

    public function testPayingAheadKeepsItUpToDate(): void
    {
        $state = Presale::derive(Presale::STATUS_OPEN, '100000.00', '70000.00', $this->plan(), '2026-11-20');

        $this->assertSame(Presale::STATE_UP_TO_DATE, $state['state']);
        $this->assertSame('2026-12-01', $state['next_due_date']);
        $this->assertSame('30000.00', $state['next_due_amount']);
    }

    public function testPaidExactlyIsPaidAndHasNothingDue(): void
    {
        $state = Presale::derive(Presale::STATUS_OPEN, '100000.00', '100000.00', $this->plan(), '2026-12-20');

        $this->assertSame(Presale::STATE_PAID, $state['state']);
        $this->assertSame('0.00', $state['balance']);
        $this->assertNull($state['next_due_date']);
        $this->assertSame(0, $state['days_late']);
    }

    public function testDeliveredAndCanceledAreFinalWhateverTheMoneySays(): void
    {
        $this->assertSame(Presale::STATUS_DELIVERED, Presale::derive(Presale::STATUS_DELIVERED, '100000.00', '100000.00', $this->plan(), '2026-12-24')['state']);
        $this->assertSame(Presale::STATUS_CANCELED, Presale::derive(Presale::STATUS_CANCELED, '100000.00', '30000.00', $this->plan(), '2026-12-24')['state']);
    }
}
