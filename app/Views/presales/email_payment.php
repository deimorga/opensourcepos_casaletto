<?php
/**
 * E-mail: an instalment was received (funcional §4.2). The figures are as of that payment, like its
 * receipt; the next instalment is as of today.
 *
 * @var array<string, mixed> $presale  the stored row plus 'number'
 * @var array<string, mixed> $payment
 * @var object               $customer
 * @var string               $campaign_name
 * @var string               $accumulated
 * @var string               $balance
 * @var array<string, mixed> $summary  Presale::get_summary() today, for the next instalment
 * @var array                $config
 * @var bool                 $has_logo
 */
$td = 'padding: 4px 0; border-bottom: 1px solid #f0f0f0;';
$r  = $td . ' text-align: right; white-space: nowrap;';
?>
<?= view('presales/email_head', ['config' => $config, 'has_logo' => $has_logo, 'heading' => lang('Presales.mail_heading_payment', [$presale['number']])], ['saveData' => false]) ?>

<p style="margin: 0 0 12px;"><?= esc(lang('Presales.mail_greeting', [trim((string) ($customer->first_name ?? ''))])) ?></p>
<p style="margin: 0 0 16px;"><?= esc(lang('Presales.mail_intro_payment', [$presale['number']])) ?></p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size: 14px; margin-bottom: 16px;">
    <tr><td style="<?= $td ?> font-weight: bold;"><?= esc(lang('Presales.mail_amount_received')) ?></td><td style="<?= $r ?> font-weight: bold;"><?= esc(to_currency((string) $payment['amount'])) ?></td></tr>
    <tr><td style="<?= $td ?>"><?= esc(lang('Presales.payment_type')) ?></td><td style="<?= $r ?>"><?= esc(payment_type_label((string) $payment['payment_type_code'])) ?></td></tr>
    <tr><td style="<?= $td ?>"><?= esc(lang('Presales.mail_date')) ?></td><td style="<?= $r ?>"><?= esc(to_datetime(strtotime((string) $payment['payment_time']))) ?></td></tr>
    <tr><td style="<?= $td ?>"><?= esc(lang('Presales.mail_paid_so_far')) ?></td><td style="<?= $r ?>"><?= esc(to_currency($accumulated)) ?></td></tr>
    <tr><td style="<?= $td ?> font-weight: bold;"><?= esc(lang('Presales.balance')) ?></td><td style="<?= $r ?> font-weight: bold;"><?= esc(to_currency($balance)) ?></td></tr>
    <?php if (! empty($summary['next_due_date'])): ?>
        <tr><td style="<?= $td ?>"><?= esc(lang('Presales.mail_next_installment')) ?></td><td style="<?= $r ?>"><?= esc(to_date(strtotime((string) $summary['next_due_date']))) ?> · <?= esc(to_currency((string) $summary['next_due_amount'])) ?></td></tr>
    <?php endif; ?>
    <tr><td style="<?= $td ?>"><?= esc(lang('Presales.delivery_date')) ?></td><td style="<?= $r ?>"><?= esc(to_date(strtotime((string) $presale['delivery_date']))) ?></td></tr>
</table>

<?php if (bccomp($balance, '0', 2) <= 0): ?>
    <p style="margin: 0 0 12px; font-weight: bold;"><?= esc(lang('Presales.mail_fully_paid', [to_date(strtotime((string) $presale['delivery_date']))])) ?></p>
<?php endif; ?>

<p style="margin: 0;"><?= esc(lang('Presales.mail_attached_payment')) ?></p>

<?= view('presales/email_foot', ['config' => $config], ['saveData' => false]) ?>
