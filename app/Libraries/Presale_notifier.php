<?php

namespace App\Libraries;

use App\Models\Customer;
use App\Models\Presale;
use App\Models\Presale_notification;
use App\Models\Presale_payment;
use Config\OSPOS;
use Throwable;

/**
 * Turns one row of the presale outbox into the e-mail the customer reads, and sends it from the
 * platform's address under the business's name (docs/Tecnico/preventas-cartera-y-avisos.md §4.6).
 *
 * Runs inside `php spark presales:notify`, one business per process, with that business's settings
 * loaded: name, logo, Reply-To, currency and date format are all the business's.
 *
 * The PDF is the same document the screen prints (Presale_documents, K4), generated at send time and
 * deleted right after. Reminders carry none (S2).
 */
class Presale_notifier
{
    /**
     * A reminder whose instalment got paid, or whose presale stopped being open, between being queued
     * and being sent. Nothing to say any more.
     */
    public const NOTHING_TO_SAY = 'nothing_to_say';

    private Presale_documents $documents;

    public function __construct(private readonly ?Email_lib $mailer)
    {
        helper(['locale', 'presales', 'payment_type', 'dompdf', 'url']);

        $this->documents = new Presale_documents();
    }

    /**
     * Sends one queued e-mail and records the outcome on its row. Never throws: a failure is a state
     * of the row, retried later (Presale_notification::mark_failed_attempt()).
     *
     * @param array<string, mixed> $row a row claimed by Presale_notification::claim()
     *
     * @return string the status the row was left in
     */
    public function deliver(array $row, string $today): string
    {
        $outbox = model(Presale_notification::class);
        $id     = (int) $row['notification_id'];

        if ($this->mailer === null) {
            $outbox->mark_skipped($id, Presale_notification::STATUS_SKIPPED_NO_TRANSPORT, 'PLATFORM_SMTP_HOST / PLATFORM_MAIL_FROM');

            return Presale_notification::STATUS_SKIPPED_NO_TRANSPORT;
        }

        $to = trim((string) ($row['to_email'] ?? ''));

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $outbox->mark_skipped($id, Presale_notification::STATUS_SKIPPED_NO_EMAIL);

            return Presale_notification::STATUS_SKIPPED_NO_EMAIL;
        }

        $pdf_dir = null;

        try {
            $mail = $this->compose($row, $today);

            if ($mail === self::NOTHING_TO_SAY) {
                $outbox->mark_skipped($id, Presale_notification::STATUS_SKIPPED_PAID);

                return Presale_notification::STATUS_SKIPPED_PAID;
            }

            if ($mail === null) {
                return $outbox->mark_failed_attempt($id, (int) $row['attempts'], 'document not found for ' . $row['kind'] . ' #' . $row['ref_id']);
            }

            $attachments = [];

            if ($mail['pdf_html'] !== null) {
                // A folder of its own, so the attachment carries a readable name ("Preventa PV-000123.pdf")
                // and two runs can never overwrite each other's file.
                $pdf_dir = WRITEPATH . 'tmp/presale_mail_' . bin2hex(random_bytes(8));
                mkdir($pdf_dir, 0700, true);

                $pdf_path = $pdf_dir . '/' . $mail['pdf_name'];
                file_put_contents($pdf_path, create_pdf($mail['pdf_html']));
                $attachments[] = $pdf_path;
            }

            $settings = config(OSPOS::class)->settings;

            $sent = $this->mailer->send_as(
                (string) ($settings['company'] ?? ''),
                $to,
                $mail['subject'],
                $mail['html'],
                (string) ($settings['email'] ?? ''),
                $attachments,
                $this->logo_path(),
            );
        } catch (Throwable $e) {
            return $outbox->mark_failed_attempt($id, (int) $row['attempts'], $e->getMessage());
        } finally {
            if ($pdf_dir !== null) {
                array_map('unlink', glob($pdf_dir . '/*') ?: []);
                @rmdir($pdf_dir);
            }
        }

        if ($sent) {
            $outbox->mark_sent($id, (int) $row['attempts']);

            return Presale_notification::STATUS_SENT;
        }

        return $outbox->mark_failed_attempt($id, (int) $row['attempts'], $this->mailer->last_error());
    }

    /**
     * The subject, the HTML body and the PDF of one queued e-mail. Null when the document it reports
     * is gone; NOTHING_TO_SAY for a reminder that no longer applies.
     *
     * Public so the content can be tested without a mail server.
     *
     * @param array<string, mixed> $row
     *
     * @return array{subject: string, html: string, pdf_html: string|null, pdf_name: string}|string|null
     */
    public function compose(array $row, string $today): array|string|null
    {
        $presale_id = (int) $row['presale_id'];
        $kind       = (string) $row['kind'];
        $ref_id     = (int) $row['ref_id'];
        $settings   = config(OSPOS::class)->settings;
        $company    = (string) ($settings['company'] ?? '');
        $presale    = model(Presale::class);

        switch ($kind) {
            case Presale_notification::KIND_CREATED:
                $data     = $this->documents->presale($presale_id, $today);
                $document = 'receipt_presale';
                break;

            case Presale_notification::KIND_PAYMENT:
                $data     = $this->documents->payment($ref_id);
                $document = 'receipt_payment';

                if ($data !== null) {
                    // The next instalment as of today, for the line "next instalment" of the body.
                    $data['summary'] = $presale->get_summary($presale_id, $today);
                }
                break;

            case Presale_notification::KIND_CANCELED:
                $data     = $this->documents->cancellation($presale_id);
                $document = 'receipt_cancel';
                break;

            case Presale_notification::KIND_DELIVERED:
                $data     = $this->documents->delivery($presale_id);
                $document = 'receipt_delivered';
                break;

            case Presale_notification::KIND_REMINDER_BEFORE:
            case Presale_notification::KIND_REMINDER_LATE:
                return $this->compose_reminder($presale_id, $kind, $ref_id, $today, $company);

            default:
                return null;
        }

        if ($data === null) {
            return null;
        }

        $number = (string) $data['presale']['number'];
        $common = ['config' => $settings, 'has_logo' => $this->logo_path() !== ''];

        return [
            'subject'  => lang('Presales.mail_subject_' . $kind, [$company, $number]),
            'html'     => view('presales/email_' . $kind, $data + $common, ['saveData' => false]),
            'pdf_html' => view('presales/' . $document, $data + ['config' => $settings, 'print' => false, 'open_drawer' => false, 'notice' => '', 'pdf' => true], ['saveData' => false]),
            'pdf_name' => lang('Presales.mail_pdf_name_' . $kind, [$number]) . '.pdf',
        ];
    }

    /**
     * A reminder: the instalment, what is still missing of it and the balance. Without a PDF (S2).
     *
     * @return array{subject: string, html: string, pdf_html: null, pdf_name: string}|string|null
     */
    private function compose_reminder(int $presale_id, string $kind, int $installment_id, string $today, string $company): array|string|null
    {
        $presale = model(Presale::class);
        $summary = $presale->get_summary($presale_id, $today);

        if ($summary === null) {
            return null;
        }

        if ($summary['status'] !== Presale::STATUS_OPEN) {
            return self::NOTHING_TO_SAY;
        }

        $installment = null;

        foreach (Presale_notification::uncovered($presale->get_installments($presale_id), model(Presale_payment::class)->get_paid($presale_id)) as $row) {
            if ((int) $row['installment_id'] === $installment_id) {
                $installment = $row;
            }
        }

        if ($installment === null) {
            return self::NOTHING_TO_SAY;
        }

        $settings = config(OSPOS::class)->settings;
        $due      = to_date(strtotime((string) $installment['due_date']));

        return [
            'subject' => lang('Presales.mail_subject_' . $kind, [$company, $summary['number'], $due]),
            'html'    => view('presales/email_reminder', [
                'presale'     => $summary,
                'customer'    => model(Customer::class)->get_info((int) $summary['customer_id']),
                'installment' => $installment,
                'late'        => $kind === Presale_notification::KIND_REMINDER_LATE,
                'config'      => $settings,
                'has_logo'    => $this->logo_path() !== '',
            ], ['saveData' => false]),
            'pdf_html' => null,
            'pdf_name' => '',
        ];
    }

    /**
     * The business's logo on disk, or '' when it has none: the same file the receipts show.
     */
    private function logo_path(): string
    {
        $logo = (string) (config(OSPOS::class)->settings['company_logo'] ?? '');

        if ($logo === '' || str_contains($logo, '..')) {
            return '';
        }

        $path = FCPATH . 'uploads/' . $logo;

        return is_file($path) ? $path : '';
    }
}
