<?php
/**
 * E-mail: the presale was cancelled (funcional §4.2): the reason, what was paid, what was given back
 * and how.
 *
 * @var array<string, mixed>       $presale  the stored row plus 'number'
 * @var object                     $customer
 * @var string                     $paid
 * @var string                     $refunded
 * @var string                     $kept
 * @var list<array<string, mixed>> $refunds
 * @var array                      $config
 * @var bool                       $has_logo
 */
$td = 'padding: 4px 0; border-bottom: 1px solid #f0f0f0;';
$r  = $td . ' text-align: right; white-space: nowrap;';
?>
<?= view('presales/email_head', ['config' => $config, 'has_logo' => $has_logo, 'heading' => lang('Presales.mail_heading_canceled', [$presale['number']])], ['saveData' => false]) ?>

<p style="margin: 0 0 12px;"><?= esc(lang('Presales.mail_greeting', [trim((string) ($customer->first_name ?? ''))])) ?></p>
<p style="margin: 0 0 16px;"><?= esc(lang('Presales.mail_intro_canceled', [$presale['number']])) ?></p>

<p style="margin: 0 0 6px; font-weight: bold;"><?= esc(lang('Presales.cancel_reason')) ?></p>
<p style="margin: 0 0 16px;"><?= nl2br(esc((string) $presale['cancel_reason'])) ?></p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size: 14px; margin-bottom: 16px;">
    <tr><td style="<?= $td ?>"><?= esc(lang('Presales.paid')) ?></td><td style="<?= $r ?>"><?= esc(to_currency($paid)) ?></td></tr>
    <tr><td style="<?= $td ?> font-weight: bold;"><?= esc(lang('Presales.refunded')) ?></td><td style="<?= $r ?> font-weight: bold;"><?= esc(to_currency($refunded)) ?></td></tr>
    <?php foreach ($refunds as $refund): ?>
        <tr><td style="<?= $td ?>"><?= esc(lang('Presales.refund_payment_type')) ?></td><td style="<?= $r ?>"><?= esc(payment_type_label((string) $refund['payment_type_code'])) ?></td></tr>
    <?php endforeach; ?>
    <tr><td style="<?= $td ?>"><?= esc(lang('Presales.kept')) ?></td><td style="<?= $r ?>"><?= esc(to_currency($kept)) ?></td></tr>
</table>

<p style="margin: 0;"><?= esc(lang('Presales.mail_attached_canceled')) ?></p>

<?= view('presales/email_foot', ['config' => $config], ['saveData' => false]) ?>
