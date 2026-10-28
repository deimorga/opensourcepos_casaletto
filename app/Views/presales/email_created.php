<?php
/**
 * E-mail: the presale was registered (funcional §4.2). The detail in the body, the presale document
 * attached (A5).
 *
 * @var array<string, mixed>       $presale  Presale::get_summary()
 * @var object                     $customer
 * @var string                     $campaign_name
 * @var list<array<string, mixed>> $lines
 * @var list<array<string, mixed>> $installments
 * @var array                      $config
 * @var bool                       $has_logo
 */
$td = 'padding: 4px 0; border-bottom: 1px solid #f0f0f0;';
$r  = $td . ' text-align: right; white-space: nowrap;';
?>
<?= view('presales/email_head', ['config' => $config, 'has_logo' => $has_logo, 'heading' => lang('Presales.mail_heading_created', [$presale['number']])], ['saveData' => false]) ?>

<p style="margin: 0 0 12px;"><?= esc(lang('Presales.mail_greeting', [trim((string) ($customer->first_name ?? ''))])) ?></p>
<p style="margin: 0 0 16px;"><?= esc(lang('Presales.mail_intro_created', [$presale['number'], $campaign_name])) ?></p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size: 14px; margin-bottom: 16px;">
    <?php foreach ($lines as $line): ?>
        <tr>
            <td style="<?= $td ?>"><?= esc(presale_quantity((string) $line['quantity'], $line['unit_of_measure'] ?? null)) ?> × <?= esc((string) $line['name']) ?></td>
            <td style="<?= $r ?>"><?= esc(to_currency(bcmul((string) $line['quantity'], (string) $line['unit_price'], 2))) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ((float) ($presale['discount_percent'] ?? 0) > 0): ?>
        <tr><td style="<?= $td ?>"><?= esc(lang('Presales.gross_total')) ?></td><td style="<?= $r ?>"><?= esc(to_currency((string) $presale['gross_total'])) ?></td></tr>
        <tr><td style="<?= $td ?>"><?= esc(lang('Presales.discount')) ?> <?= esc(rtrim(rtrim((string) $presale['discount_percent'], '0'), '.')) ?>%</td><td style="<?= $r ?>">-<?= esc(to_currency(bcsub((string) $presale['gross_total'], (string) $presale['total'], 2))) ?></td></tr>
    <?php endif; ?>
    <tr><td style="<?= $td ?> font-weight: bold;"><?= esc(lang('Presales.total')) ?></td><td style="<?= $r ?> font-weight: bold;"><?= esc(to_currency((string) $presale['total'])) ?></td></tr>
    <tr><td style="<?= $td ?>"><?= esc(lang('Presales.paid')) ?></td><td style="<?= $r ?>"><?= esc(to_currency((string) $presale['paid'])) ?></td></tr>
    <tr><td style="<?= $td ?> font-weight: bold;"><?= esc(lang('Presales.balance')) ?></td><td style="<?= $r ?> font-weight: bold;"><?= esc(to_currency((string) $presale['balance'])) ?></td></tr>
    <tr><td style="<?= $td ?>"><?= esc(lang('Presales.delivery_date')) ?></td><td style="<?= $r ?>"><strong><?= esc(to_date(strtotime((string) $presale['delivery_date']))) ?></strong></td></tr>
</table>

<p style="margin: 0 0 6px; font-weight: bold;"><?= esc(lang('Presales.installments')) ?></p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size: 14px; margin-bottom: 16px;">
    <?php foreach ($installments as $position => $installment): ?>
        <tr>
            <td style="<?= $td ?>"><?= esc(to_date(strtotime((string) $installment['due_date']))) ?><?= $position === 0 ? ' · ' . esc(lang('Presales.initial_installment')) : '' ?></td>
            <td style="<?= $r ?>"><?= esc(to_currency((string) $installment['amount'])) ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<p style="margin: 0;"><?= esc(lang('Presales.mail_attached_created')) ?></p>

<?= view('presales/email_foot', ['config' => $config], ['saveData' => false]) ?>
