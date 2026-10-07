<?php
/**
 * The presale document, the customer's "contract" (docs/Funcional/venta-anticipada.md §4.3): number,
 * customer, campaign, the agreed products and prices, the total, the delivery date, the plan, what has
 * been paid, the balance, and the business's conditions (presales_terms, §4.14) printed as text.
 *
 * Prints itself only when $print is true -- right after registering. Opened from the detail it waits
 * for the Print button. The drawer opens only on the page that follows a cash initial payment.
 *
 * @var array<string, mixed>       $presale   Presale::get_summary()
 * @var object                     $customer
 * @var string                     $campaign_name
 * @var list<array<string, mixed>> $lines     only the lines the register would print
 * @var list<array<string, mixed>> $installments
 * @var bool                       $print
 * @var bool                       $open_drawer
 * @var array                      $config
 */
$customer_name = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
$terms         = trim((string) ($config['presales_terms'] ?? ''));
?>
<?= view('presales/receipt_head', ['title' => lang('Presales.receipt_presale'), 'config' => $config]) ?>

    <table>
        <tr><th><?= esc(lang('Presales.number')) ?></th><td class="r"><strong><?= esc($presale['number']) ?></strong></td></tr>
        <tr><th><?= esc(lang('Presales.created_at')) ?></th><td class="r"><?= esc(to_datetime(strtotime((string) $presale['created_at']))) ?></td></tr>
        <tr><th><?= esc(lang('Presales.customer')) ?></th><td class="r"><?= esc($customer_name) ?></td></tr>
        <?php if (! empty($customer->phone_number)): ?>
            <tr><th><?= esc(lang('Presales.phone')) ?></th><td class="r"><?= esc($customer->phone_number) ?></td></tr>
        <?php endif; ?>
        <tr><th><?= esc(lang('Presales.campaign')) ?></th><td class="r"><?= esc($campaign_name) ?></td></tr>
        <tr><th><?= esc(lang('Presales.delivery_date')) ?></th><td class="r"><strong><?= esc(to_date(strtotime((string) $presale['delivery_date']))) ?></strong></td></tr>
    </table>

    <div class="presale-doc-section"><?= esc(lang('Presales.lines')) ?></div>
    <table id="receipt_items">
        <?php foreach ($lines as $line): ?>
            <tr>
                <td><?= esc(to_quantity_decimals((string) $line['quantity'])) ?> × <?= esc((string) $line['name']) ?><br><small><?= esc(to_currency((string) $line['unit_price'])) ?></small></td>
                <td class="r"><?= esc(to_currency(bcmul((string) $line['quantity'], (string) $line['unit_price'], 2))) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr class="sep"><th><?= esc(lang('Presales.total')) ?></th><th class="r"><?= esc(to_currency((string) $presale['total'])) ?></th></tr>
    </table>

    <div class="presale-doc-section"><?= esc(lang('Presales.installments')) ?></div>
    <table>
        <?php foreach ($installments as $position => $installment): ?>
            <tr>
                <td><?= esc(to_date(strtotime((string) $installment['due_date']))) ?><?= $position === 0 ? ' · ' . esc(lang('Presales.initial_installment')) : '' ?></td>
                <td class="r"><?= esc(to_currency((string) $installment['amount'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr class="sep"><th><?= esc(lang('Presales.paid')) ?></th><td class="r"><?= esc(to_currency($presale['paid'])) ?></td></tr>
        <tr><th><?= esc(lang('Presales.balance')) ?></th><th class="r"><?= esc(to_currency($presale['balance'])) ?></th></tr>
    </table>

    <?php if ($terms !== ''): ?>
        <div class="presale-doc-terms" id="presale_terms"><?= nl2br(esc($terms)) ?></div>
    <?php endif; ?>
</div>

<?= view('partial/open_cash_drawer', ['open_cash_drawer' => $open_drawer]) ?>

<?php if ($print): ?>
    <script>window.addEventListener('load', function () { window.print(); });</script>
<?php endif; ?>
</body>
</html>
