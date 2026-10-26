<?php

namespace app\Libraries;

use CodeIgniter\Email\Email;
use CodeIgniter\Encryption\Encryption;
use CodeIgniter\Encryption\EncrypterInterface;
use CodeIgniter\Encryption\Exceptions\EncryptionException;
use Config\OSPOS;
use Config\Services;


/**
 * Email library
 *
 * Library with utilities to configure and send emails
 */

class Email_lib
{
    private Email $email;
    private array $config;
    private ?string $platform_from = null;
    private string $failure = '';

    /**
     * @param array{host: string, port: int, crypto: string, user: string, pass: string, from: string}|null $platform
     *        The platform's own SMTP account (see platform()). Null: the business's settings, as always.
     */
    public function __construct(?array $platform = null)
    {
        $this->email = new Email();

        if ($platform !== null) {
            $this->config        = [];
            $this->platform_from = $platform['from'];

            $this->email->initialize([
                'mailType'    => 'html',
                'userAgent'   => 'OSPOS',
                'validate'    => true,
                'protocol'    => 'smtp',
                'SMTPHost'    => $platform['host'],
                'SMTPUser'    => $platform['user'],
                'SMTPPass'    => $platform['pass'],
                'SMTPPort'    => $platform['port'],
                'SMTPTimeout' => 10,
                'SMTPCrypto'  => $platform['crypto'],
            ]);

            return;
        }

        $this->config = config(OSPOS::class)->settings;

        $encrypter = Services::encrypter();

        $smtp_pass = $this->config['smtp_pass'];
        if (!empty($smtp_pass) && check_encryption()) {
            try {
                $smtp_pass = $encrypter->decrypt($smtp_pass);
            } catch (\EncryptionException $e) {
                // Decryption failed, use the original value
                log_message('error', 'SMTP password decryption failed: ' . $e->getMessage());
                $smtp_pass = '';
            }

        }

        $email_config = [
            'mailType'    => 'html',
            'userAgent'   => 'OSPOS',
            'validate'    => true,
            'protocol'    => $this->config['protocol'],
            'mailPath'    => $this->config['mailpath'],
            'SMTPHost'    => $this->config['smtp_host'],
            'SMTPUser'    => $this->config['smtp_user'],
            'SMTPPass'    => $smtp_pass,
            'SMTPPort'    => (int)$this->config['smtp_port'],
            'SMTPTimeout' => (int)$this->config['smtp_timeout'],
            'SMTPCrypto'  => $this->config['smtp_crypto']
        ];
        $this->email->initialize($email_config);
    }

    /**
     * The platform's sender: one SMTP account for every business, read from the environment
     * (docs/Tecnico/preventas-cartera-y-avisos.md §4.1). Production sends through Resend; staging
     * through Mailpit, which keeps every message and delivers none. Null when the environment names no
     * host or no sender: then there is nothing to send with, and the caller says so instead of failing.
     */
    public static function platform(): ?self
    {
        $host = trim((string) getenv('PLATFORM_SMTP_HOST'));
        $from = trim((string) getenv('PLATFORM_MAIL_FROM'));

        if ($host === '' || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $port = (int) getenv('PLATFORM_SMTP_PORT');

        return new self([
            'host'   => $host,
            'port'   => $port > 0 ? $port : 587,
            'crypto' => trim((string) getenv('PLATFORM_SMTP_CRYPTO')),
            'user'   => trim((string) getenv('PLATFORM_SMTP_USER')),
            'pass'   => (string) getenv('PLATFORM_SMTP_PASS'),
            'from'   => $from,
        ]);
    }

    /**
     * Sends from the platform's address under a business's name, with replies going to the business
     * (K2: the From must be the domain that signs, or DMARC sends it to spam).
     *
     * @param list<string> $attachments Paths of files to attach.
     * @param string       $logo_path   A local image embedded in the body by CID (K5: Gmail shows no
     *                                  data: images). The body refers to it as src="cid:logo".
     */
    public function send_as(string $sender_name, string $to, string $subject, string $message, string $reply_to = '', array $attachments = [], string $logo_path = ''): bool
    {
        if ($this->platform_from === null) {
            throw new \LogicException('send_as() needs the platform sender: use Email_lib::platform().');
        }

        $email = $this->email;
        $email->clear(true);

        $email->setFrom($this->platform_from, self::header_text($sender_name));
        $email->setTo($to);

        if (filter_var($reply_to, FILTER_VALIDATE_EMAIL) !== false) {
            $email->setReplyTo($reply_to, self::header_text($sender_name));
        }

        $email->setSubject(self::header_text($subject));

        foreach ($attachments as $attachment) {
            $email->attach($attachment);
        }

        if ($logo_path !== '' && is_file($logo_path)) {
            $email->attach($logo_path, 'inline');
            $cid = $email->setAttachmentCID($logo_path);

            if (is_string($cid)) {
                $message = str_replace('cid:logo', 'cid:' . $cid, $message);
            }
        }

        $email->setMessage($message);

        // CodeIgniter opens the SMTP socket with a bare fsockopen(): a refused or timed-out connection
        // raises a PHP warning, which the framework's error handler turns into an ErrorException in
        // production. While sending, warnings are noted and absorbed instead, so CodeIgniter sees the
        // failed socket and returns false the way it was written to. A mail server that does not
        // answer is a false here, never an exception.
        $this->failure = '';

        set_error_handler(function (int $severity, string $message): bool {
            $this->failure = $message;

            return true;
        }, E_WARNING | E_NOTICE);

        try {
            $result = $email->send(false);
        } catch (\Throwable $e) {
            $this->failure = $e->getMessage();
            $result        = false;
        } finally {
            restore_error_handler();
        }

        if (!$result) {
            log_message('error', 'Platform mail to ' . $to . ' failed: ' . $this->last_error());
        }

        return $result;
    }

    /**
     * What the mail server answered on the last failure, without the message itself. The SMTP
     * conversation CodeIgniter keeps holds the server's replies, never the password.
     */
    public function last_error(): string
    {
        $answer = trim(html_entity_decode(strip_tags($this->email->printDebugger([])), ENT_QUOTES));

        return trim($this->failure . ($this->failure !== '' && $answer !== '' ? "\n" : '') . $answer);
    }

    /**
     * A name or subject fit for a mail header: one line, no control characters.
     */
    public static function header_text(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));
    }

    /**
     * Email sending function
     * Example of use: $response = sendEmail('john@doe.com', 'Hello', 'This is a message', $filename);
     */
    public function sendEmail(string $to, string $subject, string $message, ?string $attachment = null): bool
    {
        $email = $this->email;

        $email->setFrom($this->config['email'], $this->config['company']);
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMessage($message);

        if (!empty($attachment)) {
            $email->attach($attachment);
            $email->setAttachmentCID($attachment);
        }

        $result = $email->send();

        if (!$result) {
            log_message('error', $email->printDebugger());
        }

        return $result;
    }

    /**
     * Gets the mime type of the company logo file.
     *
     * @return string Mime type or empty string if logo doesn't exist
     */
    public function getLogoMimeType(): string
    {
        $logo_path = FCPATH . 'uploads/' . $this->config['company_logo'];

        if (!empty($this->config['company_logo']) && file_exists($logo_path)) {
            $mimeType = mime_content_type($logo_path);
            return $mimeType !== false ? $mimeType : '';
        }

        return '';
    }

    /**
     * Builds an img tag for the company logo to use in email templates.
     *
     * @return string HTML img tag with base64-encoded logo, or empty string if no logo
     */
    public function buildLogoImgTag(): string
    {
        $mimeType = $this->getLogoMimeType();

        if ($mimeType === '') {
            return '';
        }

        $logo_path = FCPATH . 'uploads/' . $this->config['company_logo'];
        $logo_data = base64_encode(file_get_contents($logo_path));

        return '<img id="image" src="data:' . $mimeType . ';base64,' . $logo_data . '" alt="company_logo">';
    }
}
