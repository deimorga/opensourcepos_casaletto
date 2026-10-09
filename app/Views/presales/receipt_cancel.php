<?php
/**
 * The cancellation document (docs/Funcional/venta-anticipada.md §4.10): what the customer had paid,
 * what was given back and how, what the business keeps, and the reason. Read from the stored
 * movements, so a reprint says what it said on the day. Carries the business's conditions like every
 * presale document (§4.14).
 *
 * Prints itself only when $print is true -- right after cancelling. The drawer opens only on the page
 * that follows a cash refund.
 *
 * @var array<string, mixed>       $presale  the stored row plus 'number'
 * @var object                     $customer
 * @var string                     $campaign_name
 * @var string                     $employee who cancelled it
 * @var string                     $paid     instalments, before any refund
 * @var string                     $refunded
 * @var string                     $kept
 * @var list<array<string, mixed>> $refunds  the refund movements
 * @var bool                       $print
 * @var bool                       $open_drawer
 * @var array                      $config
 */

use App\Libraries\Identity_document;

$customer_name = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
// "CC 1020345678"; an old customer without type, the number alone; none, no line (docs/Tecnico/documento-de-identidad.md IT6).
$customer_document = Identity_document::format($customer->document_type ?? null, $customer->document_number ?? null);
$terms             = trim((string) ($config['presales_terms'] ?? ''));
?>
<?= view('presales/receipt_head', ['title' => lang('Presales.receipt_cancel'), 'config' => $config], ['saveData' => false]) ?>

    <table>
        <tr><th><?= esc(lang('Presales.number')) ?></th><td class="r"><strong><?= esc($presale['number']) ?></strong></td></tr>
        <tr><th><?= esc(lang('Presales.canceled_at')) ?></th><td class="r"><?= esc(to_datetime(strtotime((string) $presale['canceled_at']))) ?></td></tr>
        <tr><th><?= esc(lang('Presales.customer')) ?></th><td class="r"><?= esc($customer_name) ?></td></tr>
        <?php if ($customer_document !== ''): ?>
            <tr><th><?= esc(lang('Common.document')) ?></th><td class="r"><?= esc($customer_document) ?></td></tr>
        <?php endif; ?>
        <tr><th><?= esc(lang('Presales.campaign')) ?></th><td class="r"><?= esc($campaign_name) ?></td></tr>
        <?php if ($employee !== ''): ?>
            <tr><th><?= esc(lang('Presales.canceled_by')) ?></th><td class="r"><?= esc($employee) ?></td></tr>
        <?php endif; ?>
    </table>

    <table id="receipt_items">
        <tr class="sep"><td><?= esc(lang('Presales.total')) ?></td><td class="r"><?= esc(to_currency((string) $presale['total'])) ?></td></tr>
        <tr><td><?= esc(lang('Presales.paid')) ?></td><td class="r"><?= esc(to_currency($paid)) ?></td></tr>
        <tr class="sep"><th><?= esc(lang('Presales.refunded')) ?></th><th class="r" id="presale_cancel_refunded"><?= esc(to_currency($refunded)) ?></th></tr>
        <?php foreach ($refunds as $refund): ?>
            <tr><td><?= esc(lang('Presales.refund_payment_type')) ?></td><td class="r"><?= esc(payment_type_label((string) $refund['payment_type_code'])) ?></td></tr>
            <?php if (! empty($refund['reference_code'])): ?>
                <tr><td><?= esc(lang('Presales.reference')) ?></td><td class="r"><?= esc((string) $refund['reference_code']) ?></td></tr>
            <?php endif; ?>
        <?php endforeach; ?>
        <tr><th><?= esc(lang('Presales.kept')) ?></th><th class="r" id="presale_cancel_kept"><?= esc(to_currency($kept)) ?></th></tr>
    </table>

    <div class="presale-doc-section"><?= esc(lang('Presales.cancel_reason')) ?></div>
    <div id="presale_cancel_reason"><?= nl2br(esc((string) $presale['cancel_reason'])) ?></div>

    <?php if ($terms !== ''): ?>
        <div class="presale-doc-terms" id="presale_terms"><?= nl2br(esc($terms)) ?></div>
    <?php endif; ?>
</div>

<?= view('partial/open_cash_drawer', ['open_cash_drawer' => $open_drawer], ['saveData' => false]) ?>

<?php if ($print): ?>
    <script>window.addEventListener('load', function () { window.print(); });</script>
<?php endif; ?>
</body>
</html>
