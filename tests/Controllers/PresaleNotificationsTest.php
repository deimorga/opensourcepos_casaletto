<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Database\Migrations\Migration_AddPresaleNotifications;
use App\Libraries\Email_lib;
use App\Libraries\Presale_notifier;
use App\Models\Item;
use App\Models\Presale;
use App\Models\Presale_campaign;
use App\Models\Presale_notification;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\OSPOS;
use ReflectionProperty;

/**
 * The customer's presale e-mails (docs/Tecnico/preventas-cartera-y-avisos.md §4.2 to §4.8): what is
 * queued and when, the outbox's states, the reminders, what the e-mail says, and the screens around
 * them (the "no e-mail" notice, Resend, the configuration tab).
 *
 * No test reaches a real mail server: "unreachable" points at a closed local port.
 *
 * SHARED DATABASE: own customer, item, open shift (open_date 2099, the one the model picks) and
 * campaign, removed by their ids together with their presales' outbox rows. The settings it writes
 * (TOUCHED_KEYS) are put back, and so are the PLATFORM_* variables it sets. Person 1 gets `presales`
 * only if it did not have it.
 *
 * @internal
 */
final class PresaleNotificationsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private const TOUCHED_KEYS = ['presales_enable', 'presales_prefix', 'presales_terms', 'presales_notify_email', 'presales_reminder_days_before', 'presales_reminder_late', 'company'];
    private const ENV          = ['PLATFORM_SMTP_HOST', 'PLATFORM_SMTP_PORT', 'PLATFORM_MAIL_FROM'];
    private const EMAIL        = 'cliente.preventa@example.com';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';
    private int $customer_id;
    private int $cashup_id;
    private int $campaign_id;
    private int $date_id;
    private int $item_id;

    /**
     * @var array<string, string|null>
     */
    private array $configBefore = [];

    /**
     * @var array<string, false|string>
     */
    private array $envBefore = [];

    private bool $grantedHere = false;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::TOUCHED_KEYS as $key) {
            $row                      = $this->db->table('app_config')->where('key', $key)->get()->getRow();
            $this->configBefore[$key] = $row === null ? null : (string) $row->value;
        }

        foreach (self::ENV as $var) {
            $this->envBefore[$var] = getenv($var);
            putenv($var);
        }

        $this->setConfig(['presales_enable' => '1', 'presales_notify_email' => '1', 'company' => 'Panadería Prueba']);

        if ($this->db->table('grants')->where(['permission_id' => 'presales', 'person_id' => 1])->countAllResults() === 0) {
            $this->db->table('grants')->insert(['permission_id' => 'presales', 'person_id' => 1, 'menu_group' => 'home']);
            $this->grantedHere = true;
        }

        $this->customer_id = $this->makeCustomer(self::EMAIL);
        $this->cashup_id   = $this->openShift();
        $this->item_id     = $this->makeItem();

        $campaigns = model(Presale_campaign::class);
        $campaign  = $campaigns->save_campaign([
            'name'                => 'PRNOTIFY-TEST Navidad',
            'sale_starts'         => $this->day(-5),
            'sale_ends'           => $this->day(40),
            'discount_percent'    => '0',
            'min_initial_percent' => '10',
            'active'              => 1,
        ], NEW_ENTRY, 1);

        $this->assertIsInt($campaign);
        $this->campaign_id = $campaign;

        $this->assertTrue($campaigns->add_date($this->campaign_id, $this->day(60)));
        $this->date_id = (int) $campaigns->get_dates($this->campaign_id)[0]['date_id'];
        $this->assertTrue($campaigns->add_item($this->campaign_id, $this->item_id));
    }

    protected function tearDown(): void
    {
        $ids = array_column($this->db->table('presales')->select('presale_id')->where('campaign_id', $this->campaign_id ?? 0)->get()->getResultArray(), 'presale_id');

        if ($ids !== []) {
            foreach (['presale_notifications', 'presale_events', 'presale_payments', 'presale_installments', 'presale_items', 'presales'] as $table) {
                $this->db->table($table)->whereIn('presale_id', $ids)->delete();
            }
        }

        $this->db->table('presale_campaign_items')->where('campaign_id', $this->campaign_id ?? 0)->delete();
        $this->db->table('presale_campaign_dates')->where('campaign_id', $this->campaign_id ?? 0)->delete();
        $this->db->table('presale_campaigns')->where('campaign_id', $this->campaign_id ?? 0)->delete();
        $this->db->table('items')->where('item_id', $this->item_id ?? 0)->delete();
        $this->db->table('cash_up')->where('cashup_id', $this->cashup_id ?? 0)->delete();
        $this->db->table('customers')->like('comments', 'PRNOTIFY-TEST')->delete();
        $this->db->table('people')->like('comments', 'PRNOTIFY-TEST')->delete();

        if ($this->grantedHere) {
            $this->db->table('grants')->where(['permission_id' => 'presales', 'person_id' => 1])->delete();
        }

        foreach ($this->configBefore as $key => $value) {
            if ($value === null) {
                $this->db->table('app_config')->where('key', $key)->delete();
            } else {
                $this->db->table('app_config')->replace(['key' => $key, 'value' => $value]);
            }
        }

        config(OSPOS::class)->update_settings();

        foreach ($this->envBefore as $var => $value) {
            putenv($value === false ? $var : "{$var}={$value}");
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    // What is queued, and when (K7, A4)
    // ---------------------------------------------------------------------------------------------

    public function testTheOutboxColumnsMatchTheModel(): void
    {
        $allowed = (new ReflectionProperty(Presale_notification::class, 'allowedFields'))->getValue(model(Presale_notification::class));

        $this->assertSame(Migration_AddPresaleNotifications::WRITABLE_COLUMNS, $allowed);
    }

    public function testWithTheSwitchOffNothingIsQueued(): void
    {
        $this->setConfig(['presales_notify_email' => '0']);

        $id = $this->register('30000');
        model(Presale::class)->add_payment($id, 'cash', '10000', 1);

        $this->assertSame([], $this->rows($id));
    }

    public function testRegisteringQueuesTheCreatedEmailToTheCustomer(): void
    {
        $id   = $this->register('30000');
        $rows = $this->rows($id);

        $this->assertCount(1, $rows);
        $this->assertSame(Presale_notification::KIND_CREATED, $rows[0]['kind']);
        $this->assertSame(0, (int) $rows[0]['ref_id']);
        $this->assertSame(Presale_notification::STATUS_PENDING, $rows[0]['status']);
        $this->assertSame(self::EMAIL, $rows[0]['to_email']);
    }

    public function testAPaymentAndACancellationQueueTheirOwnEmails(): void
    {
        $id         = $this->register('30000');
        $payment_id = model(Presale::class)->add_payment($id, 'cash', '10000', 1);

        $this->assertIsInt($payment_id);
        $this->assertTrue(model(Presale::class)->cancel($id, 1, 'El cliente viaja', '0'));

        $kinds = array_map(static fn (array $row): string => $row['kind'] . ':' . $row['ref_id'], $this->rows($id));

        $this->assertSame(['created:0', 'payment:' . $payment_id, 'canceled:0'], $kinds);
    }

    public function testAFactRolledBackTakesItsEmailWithIt(): void
    {
        $id = $this->register('30000');

        $this->db->transBegin();
        model(Presale_notification::class)->enqueue($id, Presale_notification::KIND_PAYMENT, 999_999, 1);
        $this->db->transRollback();

        $this->assertSame(0, $this->db->table('presale_notifications')->where(['presale_id' => $id, 'ref_id' => 999_999])->countAllResults());
    }

    public function testQueuingTheSameFactTwiceKeepsOneRow(): void
    {
        $id = $this->register('30000');

        $this->assertNull(model(Presale_notification::class)->enqueue($id, Presale_notification::KIND_CREATED, 0, 1));
        $this->assertCount(1, $this->rows($id));
    }

    /**
     * A4: the presale is registered all the same; the e-mail is recorded as skipped, and the document
     * that follows the payment says so on screen.
     */
    public function testACustomerWithoutEmailIsServedAndTheScreenSaysNoEmailWasSent(): void
    {
        $this->db->table('people')->where('person_id', $this->customer_id)->update(['email' => '']);

        $id = $this->register('30000');
        $this->assertSame(Presale_notification::STATUS_SKIPPED_NO_EMAIL, $this->rows($id)[0]['status']);

        $response = $this->postReq('presales/addPayment/' . $id, ['amount' => to_currency_no_money('10000'), 'payment_type_code' => 'cash']);
        $json     = $this->json($response);

        $this->assertTrue($json['success']);
        $this->assertStringContainsString('sin_correo=1', $json['receipt_url']);

        $payment_id = (int) $this->rows($id)[1]['ref_id'];
        $page       = $this->getReq('presales/paymentReceipt/' . $payment_id . '?sin_correo=1');

        $page->assertSee('presale_mail_notice');
        $page->assertSee(esc(lang('Presales.notify_no_email')));
    }

    public function testWithAnAddressTheDocumentCarriesNoNotice(): void
    {
        $id   = $this->register('30000');
        $json = $this->json($this->postReq('presales/addPayment/' . $id, ['amount' => to_currency_no_money('10000'), 'payment_type_code' => 'cash']));

        $this->assertTrue($json['success']);
        $this->assertStringNotContainsString('sin_correo', $json['receipt_url']);
    }

    // ---------------------------------------------------------------------------------------------
    // The outbox (K6, §4.5)
    // ---------------------------------------------------------------------------------------------

    public function testTwoRunsNeverClaimTheSameEmail(): void
    {
        $id     = $this->register('30000');
        $outbox = model(Presale_notification::class);
        $mine   = (int) $this->rows($id)[0]['notification_id'];
        $first  = array_map('intval', array_column($outbox->claim(500), 'notification_id'));
        $second = array_map('intval', array_column($outbox->claim(500), 'notification_id'));

        $this->assertContains($mine, $first);
        $this->assertNotContains($mine, $second);
        $this->assertSame(Presale_notification::STATUS_SENDING, $this->rows($id)[0]['status']);
    }

    public function testAFailedAttemptWaitsLongerEachTimeAndTheFifthIsFinal(): void
    {
        $id     = $this->register('30000');
        $outbox = model(Presale_notification::class);
        $nid    = (int) $this->rows($id)[0]['notification_id'];

        $this->assertSame(Presale_notification::STATUS_PENDING, $outbox->mark_failed_attempt($nid, 0, "535 Authentication failed\r\nbye"));

        $row = $this->rows($id)[0];
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertSame('535 Authentication failed bye', $row['last_error']);
        $this->assertGreaterThan(time() + 30, strtotime($row['next_attempt_at']), 'One minute later, not now.');

        $this->assertSame(Presale_notification::STATUS_FAILED, $outbox->mark_failed_attempt($nid, 4, 'still down'));
        $this->assertSame(5, (int) $this->rows($id)[0]['attempts']);
    }

    public function testARowLeftSendingByARunThatDiedIsTakenAgain(): void
    {
        $id  = $this->register('30000');
        $nid = (int) $this->rows($id)[0]['notification_id'];

        $this->db->table('presale_notifications')->where('notification_id', $nid)->update([
            'status'          => Presale_notification::STATUS_SENDING,
            'next_attempt_at' => date('Y-m-d H:i:s', strtotime('-11 minutes')),
        ]);

        $this->assertContains($nid, array_map('intval', array_column(model(Presale_notification::class)->claim(500), 'notification_id')));
    }

    // ---------------------------------------------------------------------------------------------
    // Resend (§4.7)
    // ---------------------------------------------------------------------------------------------

    public function testResendGoesToTheAddressTheCustomerHasNow(): void
    {
        $this->db->table('people')->where('person_id', $this->customer_id)->update(['email' => '']);

        $id  = $this->register('30000');
        $nid = (int) $this->rows($id)[0]['notification_id'];

        $refused = $this->json($this->postReq('presales/' . $id . '/resendNotification/' . $nid, []));
        $this->assertFalse($refused['success']);
        $this->assertSame(esc(lang('Presales.notify_no_email')), $refused['message']);

        $this->db->table('people')->where('person_id', $this->customer_id)->update(['email' => 'nuevo.correo@example.com']);

        $this->assertTrue($this->json($this->postReq('presales/' . $id . '/resendNotification/' . $nid, []))['success']);

        $row = $this->rows($id)[0];
        $this->assertSame(Presale_notification::STATUS_PENDING, $row['status']);
        $this->assertSame('nuevo.correo@example.com', $row['to_email']);
        $this->assertSame(0, (int) $row['attempts']);
    }

    public function testResendCannotReachAnotherPresalesEmail(): void
    {
        $id    = $this->register('30000');
        $other = $this->register('30000');
        $nid   = (int) $this->rows($other)[0]['notification_id'];

        $this->postReq('presales/' . $id . '/resendNotification/' . $nid, [])->assertStatus(404);
    }

    // ---------------------------------------------------------------------------------------------
    // Reminders (S1, K6)
    // ---------------------------------------------------------------------------------------------

    public function testOneReminderBeforeAndOneAfterEachInstalmentNeverTwice(): void
    {
        $id     = $this->register('30000');
        $outbox = model(Presale_notification::class);
        $second = (int) $this->db->table('presale_installments')->where('presale_id', $id)->where('due_date', $this->day(30))->get()->getRow()->installment_id;

        $outbox->enqueue_reminders($this->day(28), 2, true);
        $outbox->enqueue_reminders($this->day(28), 2, true);
        $outbox->enqueue_reminders($this->day(31), 2, true);
        $outbox->enqueue_reminders($this->day(31), 2, true);

        $reminders = array_values(array_filter($this->rows($id), static fn (array $row): bool => str_starts_with($row['kind'], 'reminder')));

        $this->assertSame(
            [Presale_notification::KIND_REMINDER_BEFORE . ':' . $second, Presale_notification::KIND_REMINDER_LATE . ':' . $second],
            array_map(static fn (array $row): string => $row['kind'] . ':' . $row['ref_id'], $reminders),
        );
        $this->assertNull($reminders[0]['employee_id']);
    }

    public function testAnInstalmentAlreadyPaidGetsNoReminder(): void
    {
        $id = $this->register('30000');
        model(Presale::class)->add_payment($id, 'cash', '70000', 1);

        model(Presale_notification::class)->enqueue_reminders($this->day(28), 2, true);

        $this->assertSame([], array_filter($this->rows($id), static fn (array $row): bool => str_starts_with($row['kind'], 'reminder')));
    }

    public function testAPartialPaymentLeavesOnlyWhatIsMissingOfAnInstalment(): void
    {
        $uncovered = Presale_notification::uncovered([
            ['installment_id' => 2, 'due_date' => '2026-12-01', 'amount' => '50000'],
            ['installment_id' => 1, 'due_date' => '2026-11-10', 'amount' => '30000'],
            ['installment_id' => 3, 'due_date' => '2026-12-15', 'amount' => '20000'],
        ], '45000');

        $this->assertSame([2, 3], array_map(static fn (array $row): int => $row['installment_id'], $uncovered));
        $this->assertSame('35000.00', $uncovered[0]['missing']);
        $this->assertSame('20000.00', $uncovered[1]['missing']);
    }

    // ---------------------------------------------------------------------------------------------
    // What the customer reads (§4.6)
    // ---------------------------------------------------------------------------------------------

    public function testTheEmailSpeaksForTheBusinessAndEscapesWhatPeopleTyped(): void
    {
        $this->db->table('people')->where('person_id', $this->customer_id)->update(['first_name' => 'José <script>x</script>']);

        $id   = $this->register('30000');
        $mail = (new Presale_notifier(null))->compose($this->rows($id)[0], date('Y-m-d'));

        $this->assertIsArray($mail);

        $number = model(Presale::class)->number($id);

        $this->assertSame(lang('Presales.mail_subject_created', ['Panadería Prueba', $number]), $mail['subject']);
        $this->assertStringContainsString('Panadería Prueba', $mail['html']);
        $this->assertStringContainsString('José &lt;script&gt;', $mail['html']);
        $this->assertStringNotContainsString('<script>x', $mail['html']);
        $this->assertStringContainsString($number, (string) $mail['pdf_html']);
        $this->assertStringNotContainsString('window.print', (string) $mail['pdf_html'], 'The PDF has no buttons and does not print itself.');
        $this->assertStringEndsWith('.pdf', $mail['pdf_name']);
    }

    public function testWithoutAMailServerTheEmailIsRecordedAsNotSent(): void
    {
        $id  = $this->register('30000');
        $row = model(Presale_notification::class)->claim(500);
        $row = array_values(array_filter($row, static fn (array $r): bool => (int) $r['presale_id'] === $id))[0];

        $this->assertSame(Presale_notification::STATUS_SKIPPED_NO_TRANSPORT, (new Presale_notifier(null))->deliver($row, date('Y-m-d')));
        $this->assertSame(Presale_notification::STATUS_SKIPPED_NO_TRANSPORT, $this->rows($id)[0]['status']);
    }

    /**
     * The mail server is down: the e-mail waits and is tried again later. The till never noticed.
     */
    public function testAMailServerThatDoesNotAnswerLeavesTheEmailForLater(): void
    {
        putenv('PLATFORM_SMTP_HOST=127.0.0.1');
        putenv('PLATFORM_SMTP_PORT=1');
        putenv('PLATFORM_MAIL_FROM=ospos@micronuba.net');

        $id  = $this->register('30000');
        $row = array_values(array_filter(model(Presale_notification::class)->claim(500), static fn (array $r): bool => (int) $r['presale_id'] === $id))[0];

        $this->assertSame(Presale_notification::STATUS_PENDING, (new Presale_notifier(Email_lib::platform()))->deliver($row, date('Y-m-d')));

        $after = $this->rows($id)[0];
        $this->assertSame(1, (int) $after['attempts']);
        $this->assertNotEmpty($after['last_error']);
    }

    public function testAReminderForAnInstalmentPaidMeanwhileIsNotSent(): void
    {
        $id = $this->register('30000');
        model(Presale_notification::class)->enqueue_reminders($this->day(28), 2, false);

        model(Presale::class)->add_payment($id, 'cash', '70000', 1);

        $reminder = array_values(array_filter($this->rows($id), static fn (array $row): bool => $row['kind'] === Presale_notification::KIND_REMINDER_BEFORE))[0];

        $this->assertSame(Presale_notifier::NOTHING_TO_SAY, (new Presale_notifier(null))->compose($reminder, $this->day(28)));
    }

    // ---------------------------------------------------------------------------------------------
    // Configuration (§4.2)
    // ---------------------------------------------------------------------------------------------

    public function testTheConfigurationTabSavesTheEmailSettings(): void
    {
        $this->setConfig(['presales_notify_email' => '0']);

        $json = $this->json($this->postReq('config/savePresales', [
            'presales_enable'               => 'presales_enable',
            'presales_prefix'               => 'PV-',
            'presales_terms'                => '',
            'presales_notify_form'          => '1',
            'presales_notify_email'         => 'presales_notify_email',
            'presales_reminder_days_before' => '3',
        ]));

        $this->assertTrue($json['success']);
        $this->assertSame('1', $this->setting('presales_notify_email'));
        $this->assertSame('3', $this->setting('presales_reminder_days_before'));
        $this->assertSame('0', $this->setting('presales_reminder_late'), 'An unticked box is an explicit no.');

        $refused = $this->json($this->postReq('config/savePresales', [
            'presales_enable'               => 'presales_enable',
            'presales_prefix'               => 'PV-',
            'presales_notify_form'          => '1',
            'presales_reminder_days_before' => '45',
        ]));

        $this->assertFalse($refused['success']);
        $this->assertSame('3', $this->setting('presales_reminder_days_before'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(int $presale_id): array
    {
        return $this->db->table('presale_notifications')->where('presale_id', $presale_id)->orderBy('notification_id', 'asc')->get()->getResultArray();
    }

    /**
     * A presale of one unit at 100,000: $initial now, the rest in 30 days.
     */
    private function register(string $initial): int
    {
        $id = model(Presale::class)->create([
            'campaign_id'      => $this->campaign_id,
            'customer_id'      => $this->customer_id,
            'delivery_date_id' => $this->date_id,
            'location_id'      => 1,
            'lines'            => [['item_id' => $this->item_id, 'quantity' => '1']],
            'installments'     => [['due_date' => $this->day(0), 'amount' => $initial], ['due_date' => $this->day(30), 'amount' => bcsub('100000', $initial, 0)]],
            'payment'          => ['payment_type_code' => 'cash', 'amount' => $initial],
        ], 1, date('Y-m-d'));

        $this->assertIsInt($id, is_string($id) ? $id : '');

        return $id;
    }

    private function setting(string $key): ?string
    {
        $row = $this->db->table('app_config')->where('key', $key)->get()->getRow();

        return $row === null ? null : (string) $row->value;
    }

    /**
     * @param array<string, string> $values
     */
    private function setConfig(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->db->table('app_config')->replace(['key' => $key, 'value' => $value]);
        }

        config(OSPOS::class)->update_settings();
    }

    private function day(int $offset): string
    {
        return date('Y-m-d', strtotime(sprintf('%+d days', $offset)));
    }

    private function getReq(string $path): TestResponse
    {
        $_SESSION = ['person_id' => 1, 'menu_group' => 'home'];
        $this->withSession($_SESSION);

        return $this->get($path);
    }

    private function postReq(string $path, array $params): TestResponse
    {
        $_SESSION = ['person_id' => 1, 'menu_group' => 'home'];
        $this->withSession($_SESSION);

        return $this->post($path, $params);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(TestResponse $response): array
    {
        return json_decode((string) $response->getJSON(), true);
    }

    private function makeCustomer(string $email): int
    {
        $this->db->table('people')->insert([
            'first_name'   => 'José',
            'last_name'    => 'Muñoz',
            'phone_number' => '3005550102',
            'email'        => $email,
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => 'PRNOTIFY-TEST',
        ]);

        $person_id = (int) $this->db->insertID();

        $this->db->table('customers')->insert(['person_id' => $person_id, 'taxable' => 1, 'deleted' => 0, 'employee_id' => 1]);

        return $person_id;
    }

    private function openShift(): int
    {
        $this->db->table('cash_up')->insert([
            'status'               => 'open',
            'open_date'            => '2099-01-01 08:00:00',
            'close_date'           => null,
            'open_amount_cash'     => '0.00',
            'transfer_amount_cash' => '0.00',
            'note'                 => 0,
            'closed_amount_cash'   => '0.00',
            'closed_amount_card'   => '0.00',
            'closed_amount_check'  => '0.00',
            'closed_amount_due'    => '0.00',
            'closed_amount_total'  => '0.00',
            'description'          => 'PresaleNotificationsTest fixture',
            'open_employee_id'     => 1,
            'close_employee_id'    => 1,
            'deleted'              => 0,
        ]);

        return (int) $this->db->insertID();
    }

    private function makeItem(): int
    {
        $this->db->table('items')->insert([
            'name'                  => 'PRNOTIFY-TEST pavo',
            'category'              => 'Test',
            'item_number'           => null,
            'description'           => '',
            'cost_price'            => '0.00',
            'unit_price'            => '100000.00',
            'unit_of_measure'       => Item::UNIT_OF_MEASURE_UNIT,
            'reorder_level'         => '0',
            'receiving_quantity'    => '1',
            'allow_alt_description' => 0,
            'is_serialized'         => 0,
        ]);

        return (int) $this->db->insertID();
    }
}
