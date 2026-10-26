<?php
/**
 * The top of every presale e-mail: the business, not the platform (A2). Logo by CID (K5), name, tax
 * id, address and phone. Inline styles and tables only: that is what mail clients render.
 *
 * Opens the content cell that email_foot.php closes.
 *
 * @var array  $config   the business's settings
 * @var bool   $has_logo
 * @var string $heading
 */
$company = (string) ($config['company'] ?? '');
?>
<!doctype html>
<html lang="<?= esc(current_language_code()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($heading) ?></title>
</head>
<body style="margin: 0; padding: 0; background: #f4f4f5; font-family: Arial, Helvetica, sans-serif; color: #24292f;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background: #f4f4f5;">
    <tr>
        <td align="center" style="padding: 24px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; background: #ffffff; border: 1px solid #e4e4e7; border-radius: 6px;">
                <tr>
                    <td align="center" style="padding: 20px 24px 12px; border-bottom: 1px solid #e4e4e7;">
                        <?php if ($has_logo): ?>
                            <img src="cid:logo" alt="<?= esc($company, 'attr') ?>" style="max-width: 180px; max-height: 80px; display: block; margin: 0 auto 8px;">
                        <?php endif; ?>
                        <div style="font-size: 18px; font-weight: bold;"><?= esc($company) ?></div>
                        <?php if (($config['tax_id'] ?? '') !== ''): ?>
                            <div style="font-size: 12px; color: #57606a;"><?= esc(lang('Presales.mail_tax_id')) ?> <?= esc((string) $config['tax_id']) ?></div>
                        <?php endif; ?>
                        <?php if (($config['address'] ?? '') !== ''): ?>
                            <div style="font-size: 12px; color: #57606a;"><?= nl2br(esc((string) $config['address'])) ?></div>
                        <?php endif; ?>
                        <?php if (($config['phone'] ?? '') !== ''): ?>
                            <div style="font-size: 12px; color: #57606a;"><?= esc((string) $config['phone']) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 20px 24px; font-size: 14px; line-height: 1.5;">
                        <h1 style="font-size: 18px; margin: 0 0 12px;"><?= esc($heading) ?></h1>
