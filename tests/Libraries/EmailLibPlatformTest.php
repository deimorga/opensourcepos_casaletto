<?php

declare(strict_types=1);

namespace Tests\Libraries;

use App\Libraries\Email_lib;
use CodeIgniter\Email\Email;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionProperty;

/**
 * The platform's sender (docs/Tecnico/preventas-cartera-y-avisos.md §4.1): one SMTP account for every
 * business, read from the environment, sending under the business's name with replies to the business.
 *
 * Nothing here reaches a real mail server: the "unreachable" cases point at a closed local port, which
 * refuses at once. That is also the point of them: a mail server that does not answer must come back
 * as false, never as an exception that would take a till request down with it.
 *
 * @internal
 */
final class EmailLibPlatformTest extends CIUnitTestCase
{
    private const VARS = [
        'PLATFORM_SMTP_HOST',
        'PLATFORM_SMTP_PORT',
        'PLATFORM_SMTP_CRYPTO',
        'PLATFORM_SMTP_USER',
        'PLATFORM_SMTP_PASS',
        'PLATFORM_MAIL_FROM',
    ];

    /**
     * @var array<string, false|string>
     */
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::VARS as $var) {
            $this->previous[$var] = getenv($var);
            putenv($var);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $var => $value) {
            putenv($value === false ? $var : "{$var}={$value}");
        }

        parent::tearDown();
    }

    public function testWithoutAHostThereIsNoPlatformSender(): void
    {
        putenv('PLATFORM_MAIL_FROM=ospos@micronuba.net');

        $this->assertNull(Email_lib::platform());
    }

    public function testWithoutAValidSenderThereIsNoPlatformSender(): void
    {
        putenv('PLATFORM_SMTP_HOST=smtp.resend.com');
        putenv('PLATFORM_MAIL_FROM=ospos');

        $this->assertNull(Email_lib::platform());
    }

    public function testTheSmtpAccountComesFromTheEnvironment(): void
    {
        putenv('PLATFORM_SMTP_HOST=smtp.resend.com');
        putenv('PLATFORM_SMTP_PORT=587');
        putenv('PLATFORM_SMTP_CRYPTO=tls');
        putenv('PLATFORM_SMTP_USER=resend');
        putenv('PLATFORM_SMTP_PASS=re_test');
        putenv('PLATFORM_MAIL_FROM=ospos@micronuba.net');

        $email = $this->email_of(Email_lib::platform());

        $this->assertSame('smtp', $email->protocol);
        $this->assertSame('smtp.resend.com', $email->SMTPHost);
        $this->assertSame(587, $email->SMTPPort);
        $this->assertSame('tls', $email->SMTPCrypto);
        $this->assertSame('resend', $email->SMTPUser);
        $this->assertSame('re_test', $email->SMTPPass);
    }

    public function testMailpitNeedsNoAccountAndThePortDefaultsSensibly(): void
    {
        putenv('PLATFORM_SMTP_HOST=mailpit');
        putenv('PLATFORM_MAIL_FROM=ospos@micronuba.net');

        $email = $this->email_of(Email_lib::platform());

        $this->assertSame('mailpit', $email->SMTPHost);
        $this->assertSame(587, $email->SMTPPort, 'No port in the environment: the submission port.');
        $this->assertSame('', $email->SMTPUser, 'No user: CodeIgniter skips SMTP AUTH, which Mailpit does not need.');
        $this->assertSame('', $email->SMTPCrypto);
    }

    public function testItSendsAsTheBusinessFromThePlatformAddressWithRepliesToTheBusiness(): void
    {
        $mailer = $this->unreachable_platform();

        $this->assertFalse($mailer->send_as('Casaletto', 'cliente@example.com', 'Preventa PV-000123 registrada', '<p>Hola</p>', 'pedidos@casaletto.co'));

        $headers = $this->email_of($mailer)->printDebugger(['headers']);

        $this->assertStringContainsString('&quot;Casaletto&quot; &lt;ospos@micronuba.net&gt;', $headers);
        $this->assertMatchesRegularExpression('/Reply-To: &quot;Casaletto&quot; &lt;pedidos@casaletto\.co&gt;/', $headers);
    }

    public function testAnInvalidReplyToIsLeftOutRatherThanSent(): void
    {
        $mailer = $this->unreachable_platform();

        $mailer->send_as('Casaletto', 'cliente@example.com', 'Asunto', '<p>Hola</p>', 'no es un correo');

        $headers = $this->email_of($mailer)->printDebugger(['headers']);

        // Without a Reply-To of ours, CodeIgniter falls back to the From: the platform address.
        $this->assertStringNotContainsString('no es un correo', $headers);
        $this->assertMatchesRegularExpression('/Reply-To:\s+&lt;ospos@micronuba\.net&gt;/', $headers);
    }

    public function testAMailServerThatDoesNotAnswerIsAFalseWithTheReasonNotAnException(): void
    {
        $mailer = $this->unreachable_platform();

        $this->assertFalse($mailer->send_as('Casaletto', 'cliente@example.com', 'Asunto', '<p>Hola</p>'));
        $this->assertNotSame('', $mailer->last_error());
    }

    public function testANameOrSubjectCannotCarryAnotherHeader(): void
    {
        $this->assertSame('Casaletto Bcc: otro@example.com', Email_lib::header_text("Casaletto\r\nBcc: otro@example.com"));
        $this->assertSame('José Muñoz', Email_lib::header_text('José Muñoz'), 'Accents stay as they are.');
    }

    private function unreachable_platform(): Email_lib
    {
        putenv('PLATFORM_SMTP_HOST=127.0.0.1');
        putenv('PLATFORM_SMTP_PORT=1');
        putenv('PLATFORM_MAIL_FROM=ospos@micronuba.net');

        $mailer = Email_lib::platform();
        $this->assertNotNull($mailer);

        return $mailer;
    }

    private function email_of(?Email_lib $mailer): Email
    {
        $this->assertNotNull($mailer);

        return (new ReflectionProperty(Email_lib::class, 'email'))->getValue($mailer);
    }
}
