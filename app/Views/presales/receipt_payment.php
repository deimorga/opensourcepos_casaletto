<?php
/**
 * The receipt of one instalment (docs/Funcional/venta-anticipada.md §4.4): what was paid this time,
 * the total paid up to and including this payment, and the balance it left. Figures are as of this
 * payment, so a reprint says what it said on the day. Carries the business's conditions like every
 * presale document (§4.14).
 *
 * @var array<string, mixed> $presale  the stored row plus 'number'
 * @var array<string, mixed> $payment
 * @var object               $customer
 * @var string               $campaign_name
 * @var string               $employee
 * @var string               $accumulated
 * @var string               $balance
 * @var bool                 $print
 * @var bool                 $open_drawer
 * @var array                $config
 */

use App\Libraries\Identity_document;

$customer_name = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
// "CC 1020345678"; an old customer without type, the number alone; none, no line (docs/Tecnico/documento-de-identidad.md IT6).
$customer_document = Identity_document::format($customer->document_type ?? null, $customer->document_number ?? null);
$terms             = trim((string) ($config['presales_terms'] ?? ''));
?>
<?= view('presales/receipt_head', ['title' => lang('Presales.receipt_payment'), 'config' => $config], ['saveData' => false]) ?>

    <table>
        <tr><th><?= esc(lang('Presales.number')) ?></th><td class="r"><strong><?= esc($presale['number']) ?></strong></td></tr>
        <tr><th><?= esc(lang('Presales.due_date')) ?></th><td class="r"><?= esc(to_datetime(strtotime((string) $payment['payment_time']))) ?></td></tr>
        <tr><th><?= esc(lang('Presales.customer')) ?></th><td class="r"><?= esc($customer_name) ?></td></tr>
        <?php if ($customer_document !== ''): ?>
            <tr><th><?= esc(lang('Common.document')) ?></th><td class="r"><?= esc($customer_document) ?></td></tr>
        <?php endif; ?>
        <tr><th><?= esc(lang('Presales.campaign')) ?></th><td class="r"><?= esc($campaign_name) ?></td></tr>
        <tr><th><?= esc(lang('Presales.delivery_date')) ?></th><td class="r"><?= esc(to_date(strtotime((string) $presale['delivery_date']))) ?></td></tr>
        <?php if ($employee !== ''): ?>
            <tr><th><?= esc(lang('Presales.employee')) ?></th><td class="r"><?= esc($employee) ?></td></tr>
        <?php endif; ?>
    </table>

    <table id="receipt_items">
        <tr class="sep"><th><?= esc(lang('Presales.this_payment')) ?></th><th class="r"><?= esc(to_currency((string) $payment['amount'])) ?></th></tr>
        <tr><td><?= esc(lang('Presales.payment_type')) ?></td><td class="r"><?= esc(payment_type_label((string) $payment['payment_type_code'])) ?></td></tr>
        <?php if (! empty($payment['reference_code'])): ?>
            <tr><td><?= esc(lang('Presales.reference')) ?></td><td class="r"><?= esc((string) $payment['reference_code']) ?></td></tr>
        <?php endif; ?>
        <tr class="sep"><td><?= esc(lang('Presales.total')) ?></td><td class="r"><?= esc(to_currency((string) $presale['total'])) ?></td></tr>
        <tr><td><?= esc(lang('Presales.accumulated')) ?></td><td class="r"><?= esc(to_currency($accumulated)) ?></td></tr>
        <tr><th><?= esc(lang('Presales.balance')) ?></th><th class="r"><?= esc(to_currency($balance)) ?></th></tr>
    </table>

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
