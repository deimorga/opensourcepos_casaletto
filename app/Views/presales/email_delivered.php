<?php
/**
 * E-mail: the presale was delivered (funcional §4.2): what was handed over and the difference a
 * weight left, if any.
 *
 * @var array<string, mixed>       $presale  the stored row plus 'number'
 * @var object                     $customer
 * @var list<array<string, mixed>> $lines
 * @var string                     $difference positive: charged; negative: given back
 * @var array                      $config
 * @var bool                       $has_logo
 */
$td   = 'padding: 4px 0; border-bottom: 1px solid #f0f0f0;';
$r    = $td . ' text-align: right; white-space: nowrap;';
$sign = bccomp($difference, '0', 2);
?>
<?= view('presales/email_head', ['config' => $config, 'has_logo' => $has_logo, 'heading' => lang('Presales.mail_heading_delivered', [$presale['number']])], ['saveData' => false]) ?>

<p style="margin: 0 0 12px;"><?= esc(lang('Presales.mail_greeting', [trim((string) ($customer->first_name ?? ''))])) ?></p>
<p style="margin: 0 0 16px;"><?= esc(lang('Presales.mail_intro_delivered', [$presale['number'], to_datetime(strtotime((string) $presale['delivered_at']))])) ?></p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size: 14px; margin-bottom: 16px;">
    <?php foreach ($lines as $line): ?>
        <tr><td style="<?= $td ?>"><?= esc(presale_quantity((string) $line['quantity_purchased'], $line['unit_of_measure'] ?? null)) ?> × <?= esc((string) ($line['name'] ?? '')) ?></td></tr>
    <?php endforeach; ?>
</table>

<?php if ($sign > 0): ?>
    <p style="margin: 0 0 12px;"><?= esc(lang('Presales.mail_weight_charged', [to_currency($difference)])) ?></p>
<?php elseif ($sign < 0): ?>
    <p style="margin: 0 0 12px;"><?= esc(lang('Presales.mail_weight_refunded', [to_currency(ltrim($difference, '-'))])) ?></p>
<?php endif; ?>

<p style="margin: 0;"><?= esc(lang('Presales.mail_attached_delivered')) ?></p>

<?= view('presales/email_foot', ['config' => $config], ['saveData' => false]) ?>
