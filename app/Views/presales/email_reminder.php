<?php
/**
 * E-mail: an instalment is about to fall due, or fell due yesterday and is unpaid (funcional §4.2).
 * Without an attachment (S2).
 *
 * @var array<string, mixed> $presale     Presale::get_summary()
 * @var object               $customer
 * @var array<string, mixed> $installment with `missing`, what the payments have not covered of it
 * @var bool                 $late
 * @var array                $config
 * @var bool                 $has_logo
 */
$td  = 'padding: 4px 0; border-bottom: 1px solid #f0f0f0;';
$r   = $td . ' text-align: right; white-space: nowrap;';
$due = to_date(strtotime((string) $installment['due_date']));
?>
<?= view('presales/email_head', ['config' => $config, 'has_logo' => $has_logo, 'heading' => lang($late ? 'Presales.mail_heading_reminder_late' : 'Presales.mail_heading_reminder_before', [$presale['number']])], ['saveData' => false]) ?>

<p style="margin: 0 0 12px;"><?= esc(lang('Presales.mail_greeting', [trim((string) ($customer->first_name ?? ''))])) ?></p>
<p style="margin: 0 0 16px;"><?= esc(lang($late ? 'Presales.mail_intro_reminder_late' : 'Presales.mail_intro_reminder_before', [$presale['number'], $due, to_currency((string) $installment['missing'])])) ?></p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size: 14px; margin-bottom: 16px;">
    <tr><td style="<?= $td ?>"><?= esc(lang('Presales.mail_installment_due')) ?></td><td style="<?= $r ?>"><?= esc($due) ?></td></tr>
    <tr><td style="<?= $td ?> font-weight: bold;"><?= esc(lang('Presales.mail_installment_missing')) ?></td><td style="<?= $r ?> font-weight: bold;"><?= esc(to_currency((string) $installment['missing'])) ?></td></tr>
    <tr><td style="<?= $td ?>"><?= esc(lang('Presales.paid')) ?></td><td style="<?= $r ?>"><?= esc(to_currency((string) $presale['paid'])) ?></td></tr>
    <tr><td style="<?= $td ?>"><?= esc(lang('Presales.balance')) ?></td><td style="<?= $r ?>"><?= esc(to_currency((string) $presale['balance'])) ?></td></tr>
    <tr><td style="<?= $td ?>"><?= esc(lang('Presales.delivery_date')) ?></td><td style="<?= $r ?>"><?= esc(to_date(strtotime((string) $presale['delivery_date']))) ?></td></tr>
</table>

<p style="margin: 0;"><?= esc(lang('Presales.mail_reminder_how')) ?></p>

<?= view('presales/email_foot', ['config' => $config], ['saveData' => false]) ?>
