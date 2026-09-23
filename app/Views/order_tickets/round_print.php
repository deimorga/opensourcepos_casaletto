<?php
/**
 * The kitchen's sheet for one round -- "RONDA n". A bare page on purpose: no layout, no menu, only
 * what the cook reads, sized for a 58-80 mm receipt printer.
 *
 * What is on the paper, and what is deliberately not (docs/Tecnico/comandas-y-cuenta-abierta.md §8.2):
 *
 * - Prices, and the round's total (D12).
 * - A kit as ONE dish, never its ingredients (D13). That holds by construction: the line stores the
 *   kit's representative item, never the components the register expands at billing.
 * - Only this round's dishes (D8), voided ones left out: a reprint must not bring back a cancelled dish.
 * - The kitchen note under each dish (D7), from its own column.
 * - NOT the line description. sales_items.description carries "Unidad: kilogramo" on most lines from
 *   the Siigo import; it is never read here, so it can never reach the kitchen.
 *
 * Printed the same way as the receipt: window.print() to the default printer, silent under the
 * till's kiosk mode (§3.6). The local agent is not involved. It prints itself only when $print is
 * true -- the till's "print" link -- so a waiter reviewing a round on a phone gets no print dialog.
 *
 * @var array<string, mixed>       $ticket
 * @var array<string, mixed>       $round
 * @var list<array<string, mixed>> $lines
 * @var bool                       $print
 * @var string                     $company
 * @var string                     $employee
 */
$total = '0';

foreach ($lines as $line) {
    $total = bcadd($total, bcmul((string) $line['quantity'], (string) $line['unit_price'], 2), 2);
}
?>
<!doctype html>
<html lang="<?= esc(current_language_code()) ?>">
<head>
    <meta charset="utf-8">
    <base href="<?= base_url() ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= esc(lang('Order_tickets.round_n', [(int) $round['number']]) . ' · ' . $ticket['name']) ?></title>
    <style>
        body { font-family: monospace, monospace; font-size: 14px; margin: 0 auto; padding: 4mm; max-width: 80mm; color: #000; background: #fff; }
        h1 { font-size: 18px; margin: 0 0 2mm; text-align: center; }
        .ot-sheet-round { font-size: 22px; font-weight: bold; text-align: center; border: 2px solid #000; margin: 2mm 0; padding: 1mm; }
        .ot-sheet-meta { font-size: 12px; text-align: center; margin-bottom: 3mm; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 1mm 0; }
        td.q { width: 12mm; font-weight: bold; }
        td.p { text-align: right; white-space: nowrap; }
        .note { font-weight: bold; padding-left: 12mm; }
        .total { border-top: 1px dashed #000; font-weight: bold; }
        @media print { body { padding: 0; } }
    </style>
</head>
<body>
    <?php if ($company !== ''): ?>
        <h1><?= esc($company) ?></h1>
    <?php endif; ?>
    <div class="ot-sheet-round"><?= esc(lang('Order_tickets.round_n', [(int) $round['number']])) ?></div>
    <div class="ot-sheet-meta">
        <strong><?= esc($ticket['name']) ?></strong><br>
        <?= esc(date('Y-m-d H:i', strtotime((string) $round['sent_at']))) ?>
        <?php if ($employee !== ''): ?>&middot; <?= esc($employee) ?><?php endif; ?>
    </div>

    <table>
        <?php foreach ($lines as $line): ?>
            <tr>
                <td class="q"><?= esc(\App\Models\Order_ticket_line::display_quantity((string) $line['quantity'])) ?></td>
                <td><?= esc($line['item_name']) ?></td>
                <td class="p"><?= esc(to_currency(bcmul((string) $line['quantity'], (string) $line['unit_price'], 2))) ?></td>
            </tr>
            <?php if ((string) $line['kitchen_note'] !== ''): ?>
                <tr><td class="note" colspan="3">&raquo; <?= esc($line['kitchen_note']) ?></td></tr>
            <?php endif; ?>
        <?php endforeach; ?>
        <tr class="total">
            <td colspan="2"><?= esc(lang('Order_tickets.total')) ?></td>
            <td class="p"><?= esc(to_currency($total)) ?></td>
        </tr>
    </table>

    <?php if ($print): ?>
        <script>window.addEventListener('load', function () { window.print(); });</script>
    <?php endif; ?>
</body>
</html>
