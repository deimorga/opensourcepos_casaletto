<?php
/**
 * The delivery document of a presale (docs/Funcional/preventas-cartera-y-avisos.md §4.2): the products
 * the register handed over and, when a product sold by weight came out heavier or lighter than agreed,
 * the difference charged or given back that day. It is the PDF of the customer's delivery e-mail.
 *
 * Not the register's sale receipt: that one is built from the register's session cart, which a
 * command sending e-mails a minute later does not have (preventas-cartera-y-avisos.md §4.6).
 *
 * @var array<string, mixed>       $presale  the stored row plus 'number'
 * @var object                     $customer
 * @var string                     $campaign_name
 * @var string                     $employee
 * @var list<array<string, mixed>> $lines    the delivery sale's printed lines
 * @var string                     $paid
 * @var string                     $sale_total
 * @var string                     $difference positive: charged; negative: given back
 * @var bool                       $print
 * @var bool                       $open_drawer
 * @var array                      $config
 * @var string                     $notice
 * @var bool                       $pdf
 */

use App\Libraries\Identity_document;

$customer_name     = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
$customer_document = Identity_document::format($customer->document_type ?? null, $customer->document_number ?? null);
$terms             = trim((string) ($config['presales_terms'] ?? ''));
$sign              = bccomp($difference, '0', 2);
?>
<?= view('presales/receipt_head', ['title' => lang('Presales.receipt_delivered'), 'config' => $config, 'notice' => $notice ?? '', 'pdf' => $pdf ?? false], ['saveData' => false]) ?>

    <table>
        <tr><th><?= esc(lang('Presales.number')) ?></th><td class="r"><strong><?= esc($presale['number']) ?></strong></td></tr>
        <tr><th><?= esc(lang('Presales.delivered_at')) ?></th><td class="r"><?= esc(to_datetime(strtotime((string) $presale['delivered_at']))) ?></td></tr>
        <tr><th><?= esc(lang('Presales.customer')) ?></th><td class="r"><?= esc($customer_name) ?></td></tr>
        <?php if ($customer_document !== ''): ?>
            <tr><th><?= esc(lang('Common.document')) ?></th><td class="r"><?= esc($customer_document) ?></td></tr>
        <?php endif; ?>
        <tr><th><?= esc(lang('Presales.campaign')) ?></th><td class="r"><?= esc($campaign_name) ?></td></tr>
        <?php if ($employee !== ''): ?>
            <tr><th><?= esc(lang('Presales.delivered_by')) ?></th><td class="r"><?= esc($employee) ?></td></tr>
        <?php endif; ?>
    </table>

    <div class="presale-doc-section"><?= esc(lang('Presales.delivered_lines')) ?></div>
    <table id="receipt_items">
        <?php foreach ($lines as $line): ?>
            <tr>
                <td><?= esc(presale_quantity((string) $line['quantity_purchased'], $line['unit_of_measure'] ?? null)) ?> × <?= esc((string) ($line['name'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <table>
        <tr class="sep"><td><?= esc(lang('Presales.total')) ?></td><td class="r"><?= esc(to_currency((string) $presale['total'])) ?></td></tr>
        <tr><td><?= esc(lang('Presales.paid')) ?></td><td class="r"><?= esc(to_currency($paid)) ?></td></tr>
        <?php if ($sign > 0): ?>
            <tr class="sep"><th><?= esc(lang('Presales.weight_charged')) ?></th><th class="r"><?= esc(to_currency($difference)) ?></th></tr>
        <?php elseif ($sign < 0): ?>
            <tr class="sep"><th><?= esc(lang('Presales.weight_refunded')) ?></th><th class="r"><?= esc(to_currency(ltrim($difference, '-'))) ?></th></tr>
        <?php endif; ?>
    </table>

    <?php if ($terms !== ''): ?>
        <div class="presale-doc-terms" id="presale_terms"><?= nl2br(esc($terms)) ?></div>
    <?php endif; ?>
</div>

<?php if ($print): ?>
    <script>window.addEventListener('load', function () { window.print(); });</script>
<?php endif; ?>
</body>
</html>
