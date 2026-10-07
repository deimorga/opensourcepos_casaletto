<?php
/**
 * The top of every presale document: a bare page, like the kitchen's sheet (order_tickets/round_print.php),
 * sized for the business's receipt paper (partial/receipt_paper.php) and 80 mm wide on screen when the
 * business has not said which paper it uses.
 *
 * Ids follow the sale receipt's (#receipt_wrapper, #receipt_items, #company_name) so the paper rules of
 * partial/receipt_paper.php apply to these documents too.
 *
 * @var string $title
 * @var array  $config
 */

use App\Libraries\Sale_lib;

$width = Sale_lib::receipt_printable_width_mm($config['receipt_paper'] ?? '') ?? 80;
?>
<!doctype html>
<html lang="<?= esc(current_language_code()) ?>">
<head>
    <meta charset="utf-8">
    <base href="<?= base_url() ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= esc($title) ?></title>
    <style>
        body { font-family: monospace, monospace; font-size: 13px; margin: 0 auto; padding: 4mm; max-width: <?= (int) $width ?>mm; color: #000; background: #fff; }
        #company_name { font-size: 16px; font-weight: bold; text-align: center; }
        .presale-doc-center { text-align: center; }
        .presale-doc-title { font-size: 15px; font-weight: bold; text-align: center; border: 1px solid #000; margin: 2mm 0; padding: 1mm; }
        table { width: 100%; border-collapse: collapse; }
        th, td { vertical-align: top; padding: 0.5mm 0; text-align: left; }
        .r { text-align: right; white-space: nowrap; }
        .sep td, .sep th { border-top: 1px dashed #000; }
        .presale-doc-section { margin-top: 3mm; font-weight: bold; }
        .presale-doc-terms { margin-top: 4mm; font-size: 11px; }
        .presale-doc-actions { margin: 4mm 0; text-align: center; }
        .presale-doc-actions a, .presale-doc-actions button { font: inherit; margin: 0 2mm; }
        @media print { body { padding: 0; } .print_hide { display: none !important; } }
    </style>
    <?= view('partial/receipt_paper', ['config' => $config], ['saveData' => false]) ?>
</head>
<body>
<div class="presale-doc-actions print_hide">
    <button type="button" onclick="window.print();"><?= esc(lang('Common.print')) ?></button>
    <a href="<?= site_url('presales') ?>"><?= esc(lang('Presales.back')) ?></a>
</div>
<div id="receipt_wrapper">
    <?php if (($config['company'] ?? '') !== ''): ?>
        <div id="company_name"><?= nl2br(esc($config['company'])) ?></div>
    <?php endif; ?>
    <?php if (($config['address'] ?? '') !== ''): ?>
        <div class="presale-doc-center"><?= nl2br(esc($config['address'])) ?></div>
    <?php endif; ?>
    <?php if (($config['phone'] ?? '') !== ''): ?>
        <div class="presale-doc-center"><?= esc($config['phone']) ?></div>
    <?php endif; ?>
    <div class="presale-doc-title"><?= esc($title) ?></div>
