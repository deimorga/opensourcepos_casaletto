<?php

namespace Tests\Libraries;

use App\Libraries\Order_ticket_request_guard;
use CodeIgniter\Session\Handlers\ArrayHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockSession;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A waiter on a bad signal must not add the same dish twice.
 *
 * The request reaches the server and is saved, the response is lost, the browser shows an error, the
 * waiter reloads and accepts "resend the form". The guard answers that second submission with false,
 * because the form's single-use token was already claimed in the same session.
 *
 * No database: the state lives in the waiter's session, and the session here is CodeIgniter's
 * MockSession over an ArrayHandler -- nothing touches PHP's real session or a cookie.
 *
 * @internal
 */
final class OrderTicketRequestGuardTest extends CIUnitTestCase
{
    private const KEY = 'order_ticket_claimed_tokens';

    private MockSession $waiterSession;
    private Order_ticket_request_guard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION = [];

        $config              = config('Session');
        $this->waiterSession = new MockSession(new ArrayHandler($config, '0.0.0.0'), $config);
        $this->waiterSession->setLogger(service('logger'));
        $this->waiterSession->start();

        $this->guard = new Order_ticket_request_guard($this->waiterSession);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];

        parent::tearDown();
    }

    public function testFieldNameIsTheContract(): void
    {
        $this->assertSame('request_token', Order_ticket_request_guard::FIELD);
    }

    public function testIssueGivesThirtyTwoLowercaseHexCharactersAndNeverTheSameTwice(): void
    {
        $first  = $this->guard->issue();
        $second = $this->guard->issue();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $second);
        $this->assertNotSame($first, $second);
    }

    public function testIssueDoesNotStoreAnything(): void
    {
        $this->guard->issue();

        $this->assertNull($this->waiterSession->get(self::KEY));
    }

    public function testTheResubmissionIsRefused(): void
    {
        $token = $this->guard->issue();

        $this->assertTrue($this->guard->claim($token), 'the first submission goes through');
        $this->assertFalse($this->guard->claim($token), 'the resent form must not add the dish again');
        $this->assertFalse($this->guard->claim($token), 'nor on the third try');
    }

    public function testTokensOfTwoFormsAreClaimedIndependently(): void
    {
        $addForm    = $this->guard->issue();
        $cancelForm = $this->guard->issue();

        $this->assertTrue($this->guard->claim($addForm));
        $this->assertTrue($this->guard->claim($cancelForm));
        $this->assertFalse($this->guard->claim($addForm));
        $this->assertFalse($this->guard->claim($cancelForm));
    }

    public function testAWellFormedTokenThatWasNeverIssuedCanBeClaimedOnce(): void
    {
        $token = str_repeat('ab', 16);

        $this->assertTrue($this->guard->claim($token));
        $this->assertFalse($this->guard->claim($token));
    }

    #[DataProvider('provideAMalformedTokenIsRefusedAndTheSessionIsNotTouched')]
    public function testAMalformedTokenIsRefusedAndTheSessionIsNotTouched(?string $token): void
    {
        $this->assertFalse($this->guard->claim($token));
        $this->assertNull($this->waiterSession->get(self::KEY), 'nothing may be written for a bad token');

        $valid = $this->guard->issue();
        $this->guard->claim($valid);
        $before = $this->waiterSession->get(self::KEY);

        $this->assertFalse($this->guard->claim($token));
        $this->assertSame($before, $this->waiterSession->get(self::KEY));
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function provideAMalformedTokenIsRefusedAndTheSessionIsNotTouched(): iterable
    {
        return [
            'null'          => [null],
            'empty'         => [''],
            'uppercase'     => [strtoupper(str_repeat('ab', 16))],
            '31 characters' => [str_repeat('a', 31)],
            '33 characters' => [str_repeat('a', 33)],
            'not hex'       => [str_repeat('g', 32)],
            'trailing nl'   => [str_repeat('a', 32) . "\n"],
        ];
    }

    public function testOnlyTheLastTwoHundredClaimsAreRemembered(): void
    {
        $tokens = [];

        for ($i = 0; $i < 201; $i++) {
            $tokens[] = $this->guard->issue();
            $this->assertTrue($this->guard->claim($tokens[$i]));
        }

        $stored = $this->waiterSession->get(self::KEY);
        $this->assertIsArray($stored);
        $this->assertCount(200, $stored);

        $this->assertTrue($this->guard->claim($tokens[0]), 'the oldest claim has been forgotten');
        $this->assertFalse($this->guard->claim($tokens[200]), 'the newest claim is still remembered');
        $this->assertCount(200, $this->waiterSession->get(self::KEY));
    }

    public function testTwoInstancesOnTheSameSessionShareTheState(): void
    {
        $token = $this->guard->issue();

        $this->assertTrue($this->guard->claim($token));

        $nextRequest = new Order_ticket_request_guard($this->waiterSession);

        $this->assertFalse($nextRequest->claim($token));
    }
}
