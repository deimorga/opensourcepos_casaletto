<?php
/**
 * One presale, opened as a dialog from the list (the same tabbed dialog as cashups/form.php).
 *
 * Tabs: the agreed products; the plan and the derived state; the money that came in (each movement
 * with its shift); and the history. Above them, the summary and the actions that apply now:
 *
 * - Take an instalment: only while the presale is open with a balance. It posts to
 *   presales/addPayment and, once saved, goes to the instalment's receipt.
 * - Deliver: only when it is paid (open, balance zero). A plain POST, with its CSRF token, to the
 *   register's sales/deliverPresale/{id}, which opens it in a tab of its own (D17). The register owns
 *   every check of that step; this button only exists when the state says it can work.
 * - Cancel: only for presales_manage, while open. Shown disabled until the cancellation screen
 *   exists, so the place where it will live is visible and nobody is offered a dead link.
 *
 * Every value printed here is escaped; the payment form's answers come back escaped by the server.
 *
 * @var array<string, mixed>       $presale        Presale::get_summary()
 * @var object                     $customer
 * @var string                     $campaign_name
 * @var list<array<string, mixed>> $lines
 * @var list<array<string, mixed>> $installments   with 'coverage': covered | overdue | pending
 * @var list<array<string, mixed>> $payments
 * @var list<array<string, mixed>> $events
 * @var array<int, string>         $people
 * @var array<string, string>      $payment_types
 * @var bool                       $has_open_shift
 * @var bool                       $can_manage
 */

use App\Models\Presale;
use App\Models\Presale_payment;

$id            = (int) $presale['presale_id'];
$is_open       = $presale['status'] === Presale::STATUS_OPEN;
$has_debt      = bccomp((string) $presale['balance'], '0', 2) > 0;
$is_paid       = $presale['state'] === Presale::STATE_PAID;
$customer_name = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
?>

<div id="presale_detail">
    <div class="row">
        <div class="col-xs-6">
            <dl class="dl-horizontal" style="margin-bottom: 10px;">
                <dt><?= esc(lang('Presales.number')) ?></dt>
                <dd><strong><?= esc($presale['number']) ?></strong> <?= presale_state_label($presale['state']) ?></dd>
                <dt><?= esc(lang('Presales.customer')) ?></dt>
                <dd><?= esc($customer_name) ?><?php if (! empty($customer->phone_number)): ?> · <?= esc($customer->phone_number) ?><?php endif; ?></dd>
                <dt><?= esc(lang('Presales.campaign')) ?></dt>
                <dd><?= esc($campaign_name) ?></dd>
                <dt><?= esc(lang('Presales.delivery_date')) ?></dt>
                <dd><?= esc(to_date(strtotime((string) $presale['delivery_date']))) ?></dd>
                <dt><?= esc(lang('Presales.created_at')) ?></dt>
                <dd><?= esc(to_datetime(strtotime((string) $presale['created_at']))) ?> · <?= esc($people[(int) $presale['employee_id']] ?? '') ?></dd>
                <?php if ($presale['status'] === Presale::STATUS_DELIVERED): ?>
                    <dt><?= esc(lang('Presales.delivered_at')) ?></dt>
                    <dd><?= esc(to_datetime(strtotime((string) $presale['delivered_at']))) ?><?php if (! empty($presale['sale_id'])): ?> · <?= esc(lang('Presales.sale')) ?> <?= (int) $presale['sale_id'] ?><?php endif; ?></dd>
                <?php endif; ?>
                <?php if ($presale['status'] === Presale::STATUS_CANCELED): ?>
                    <dt><?= esc(lang('Presales.canceled_at')) ?></dt>
                    <dd><?= esc(to_datetime(strtotime((string) $presale['canceled_at']))) ?></dd>
                    <dt><?= esc(lang('Presales.cancel_reason')) ?></dt>
                    <dd><?= nl2br(esc((string) $presale['cancel_reason'])) ?></dd>
                <?php endif; ?>
            </dl>
        </div>
        <div class="col-xs-6">
            <table class="table table-condensed" style="margin-bottom: 10px;">
                <tr><th><?= esc(lang('Presales.total')) ?></th><td style="text-align: right;"><?= esc(to_currency((string) $presale['total'])) ?></td></tr>
                <tr><th><?= esc(lang('Presales.paid')) ?></th><td style="text-align: right;"><?= esc(to_currency($presale['paid'])) ?></td></tr>
                <?php if ($is_open): ?>
                    <tr><th><?= esc(lang('Presales.balance')) ?></th><td style="text-align: right;"><strong><?= esc(to_currency($presale['balance'])) ?></strong></td></tr>
                    <?php if ($presale['next_due_date'] !== null): ?>
                        <tr><th><?= esc(lang('Presales.next_due')) ?></th><td style="text-align: right;"><?= esc(to_date(strtotime((string) $presale['next_due_date']))) ?> · <?= esc(to_currency($presale['next_due_amount'])) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($presale['days_late'] > 0): ?>
                        <tr class="danger"><th><?= esc(lang('Presales.days_late')) ?></th><td style="text-align: right;"><?= (int) $presale['days_late'] ?></td></tr>
                    <?php endif; ?>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="btn-toolbar" role="toolbar" style="margin-bottom: 10px;">
        <a class="btn btn-default btn-sm" href="<?= site_url('presales/receipt/' . $id) ?>" target="_blank" rel="noopener">
            <span class="glyphicon glyphicon-print">&nbsp;</span><?= esc(lang('Presales.print_contract')) ?>
        </a>

        <?php if ($is_open && $is_paid): ?>
            <?= form_open('sales/deliverPresale/' . $id, ['id' => 'presale_deliver_form', 'style' => 'display: inline;']) ?>
                <button type="submit" class="btn btn-success btn-sm" id="presale_deliver">
                    <span class="glyphicon glyphicon-gift">&nbsp;</span><?= esc(lang('Presales.deliver')) ?>
                </button>
            <?= form_close() ?>
        <?php endif; ?>

        <?php if ($is_open && $can_manage): ?>
            <span style="display: inline-block;" tabindex="0" title="<?= esc(lang('Presales.coming_soon'), 'attr') ?>">
                <button type="button" class="btn btn-danger btn-sm" id="presale_cancel" disabled aria-disabled="true">
                    <span class="glyphicon glyphicon-remove">&nbsp;</span><?= esc(lang('Presales.cancel')) ?>
                </button>
            </span>
        <?php endif; ?>
    </div>

    <?php if ($is_open && $has_debt): ?>
        <p class="text-muted small"><?= esc(lang('Presales.deliver_needs_balance', [to_currency($presale['balance'])])) ?></p>

        <?php if (! $has_open_shift): ?>
            <div class="alert alert-warning" role="alert"><?= esc(lang('Presales.no_open_cashup_warning')) ?></div>
        <?php else: ?>
            <div class="form-inline well well-sm" id="presale_payment_block">
                <strong><?= esc(lang('Presales.take_payment')) ?></strong>
                <label class="sr-only" for="presale_payment_amount"><?= esc(lang('Presales.amount')) ?></label>
                <input type="text" id="presale_payment_amount" class="form-control input-sm" inputmode="decimal" placeholder="<?= esc(lang('Presales.amount'), 'attr') ?>">
                <label class="sr-only" for="presale_payment_type"><?= esc(lang('Presales.payment_type')) ?></label>
                <?= form_dropdown('payment_type_code', esc($payment_types), 'cash', ['id' => 'presale_payment_type', 'class' => 'form-control input-sm']) ?>
                <label class="sr-only" for="presale_payment_reference"><?= esc(lang('Presales.reference')) ?></label>
                <input type="text" id="presale_payment_reference" class="form-control input-sm" maxlength="60" placeholder="<?= esc(lang('Presales.reference'), 'attr') ?>">
                <button type="button" class="btn btn-primary btn-sm" id="presale_take_payment"><?= esc(lang('Presales.take_payment')) ?></button>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <ul class="nav nav-tabs nav-justified" data-tabs="tabs" id="presale_tabs" role="tablist">
        <li class="active" role="presentation"><a data-toggle="tab" href="#presale_tab_lines" role="tab" aria-controls="presale_tab_lines" aria-selected="true"><?= esc(lang('Presales.lines')) ?></a></li>
        <li role="presentation"><a data-toggle="tab" href="#presale_tab_plan" role="tab" aria-controls="presale_tab_plan" aria-selected="false"><?= esc(lang('Presales.plan_state')) ?></a></li>
        <li role="presentation"><a data-toggle="tab" href="#presale_tab_payments" role="tab" aria-controls="presale_tab_payments" aria-selected="false"><?= esc(lang('Presales.payments')) ?> <span class="badge"><?= count($payments) ?></span></a></li>
        <li role="presentation"><a data-toggle="tab" href="#presale_tab_history" role="tab" aria-controls="presale_tab_history" aria-selected="false"><?= esc(lang('Presales.history')) ?></a></li>
    </ul>

    <div class="tab-content" style="padding-top: 10px;">
        <div class="tab-pane fade in active" id="presale_tab_lines" role="tabpanel">
            <table class="table table-condensed">
                <thead>
                    <tr>
                        <th><?= esc(lang('Presales.product')) ?></th>
                        <th style="text-align: right;"><?= esc(lang('Presales.quantity')) ?></th>
                        <th style="text-align: right;"><?= esc(lang('Presales.price')) ?></th>
                        <th style="text-align: right;"><?= esc(lang('Presales.line_total')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lines as $line): ?>
                        <tr>
                            <td>
                                <?= esc((string) $line['name']) ?>
                                <?php if ((int) $line['item_type'] === ITEM_KIT): ?><span class="label label-default"><?= esc(lang('Presales.kit')) ?></span><?php endif; ?>
                            </td>
                            <td style="text-align: right;"><?= esc(to_quantity_decimals((string) $line['quantity'])) ?></td>
                            <td style="text-align: right;"><?= esc(to_currency((string) $line['unit_price'])) ?></td>
                            <td style="text-align: right;"><?= esc(to_currency(bcmul((string) $line['quantity'], (string) $line['unit_price'], 2))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="tab-pane fade" id="presale_tab_plan" role="tabpanel">
            <table class="table table-condensed">
                <thead>
                    <tr>
                        <th><?= esc(lang('Presales.due_date')) ?></th>
                        <th style="text-align: right;"><?= esc(lang('Presales.amount')) ?></th>
                        <th><?= esc(lang('Presales.state')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($installments as $position => $installment): ?>
                        <tr<?= $is_open && $installment['coverage'] === 'overdue' ? ' class="danger"' : '' ?>>
                            <td>
                                <?= esc(to_date(strtotime((string) $installment['due_date']))) ?>
                                <?php if ($position === 0): ?><small class="text-muted"><?= esc(lang('Presales.initial_installment')) ?></small><?php endif; ?>
                            </td>
                            <td style="text-align: right;"><?= esc(to_currency((string) $installment['amount'])) ?></td>
                            <td><?= esc(lang('Presales.installment_' . $installment['coverage'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr><th><?= esc(lang('Presales.due_to_date')) ?></th><td style="text-align: right;"><?= esc(to_currency($presale['due_to_date'])) ?></td><td></td></tr>
                    <tr><th><?= esc(lang('Presales.paid')) ?></th><td style="text-align: right;"><?= esc(to_currency($presale['paid'])) ?></td><td><?= presale_state_label($presale['state']) ?></td></tr>
                </tfoot>
            </table>
        </div>

        <div class="tab-pane fade" id="presale_tab_payments" role="tabpanel">
            <?php if ($payments === []): ?>
                <p><em><?= esc(lang('Presales.no_payments')) ?></em></p>
            <?php else: ?>
                <table class="table table-condensed">
                    <thead>
                        <tr>
                            <th><?= esc(lang('Presales.due_date')) ?></th>
                            <th><?= esc(lang('Presales.payment_type')) ?></th>
                            <th style="text-align: right;"><?= esc(lang('Presales.amount')) ?></th>
                            <th><?= esc(lang('Presales.shift')) ?></th>
                            <th><?= esc(lang('Presales.employee')) ?></th>
                            <th><?= esc(lang('Presales.reference')) ?></th>
                            <th><span class="sr-only"><?= esc(lang('Presales.print_receipt')) ?></span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <?php $is_refund = $payment['kind'] === Presale_payment::KIND_REFUND; ?>
                            <tr>
                                <td><?= esc(to_datetime(strtotime((string) $payment['payment_time']))) ?></td>
                                <td><?= esc(lang('Presales.kind_' . $payment['kind'])) ?> · <?= esc(payment_type_label((string) $payment['payment_type_code'])) ?></td>
                                <td style="text-align: right;"><?= esc(to_currency(($is_refund ? '-' : '') . $payment['amount'])) ?></td>
                                <td><?= (int) $payment['cashup_id'] ?></td>
                                <td><?= esc($people[(int) $payment['employee_id']] ?? '') ?></td>
                                <td><?= esc((string) ($payment['reference_code'] ?? '')) ?></td>
                                <td>
                                    <?php if (! $is_refund): ?>
                                        <a href="<?= site_url('presales/paymentReceipt/' . (int) $payment['payment_id']) ?>" target="_blank" rel="noopener" title="<?= esc(lang('Presales.print_receipt'), 'attr') ?>">
                                            <span class="glyphicon glyphicon-print" aria-hidden="true"></span><span class="sr-only"><?= esc(lang('Presales.print_receipt')) ?></span>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="tab-pane fade" id="presale_tab_history" role="tabpanel">
            <table class="table table-condensed">
                <tbody>
                    <?php foreach ($events as $event): ?>
                        <tr>
                            <td><?= esc(to_datetime(strtotime((string) $event['event_time']))) ?></td>
                            <td><?= esc(lang('Presales.event_' . $event['event_type'])) ?></td>
                            <td><?= esc($people[(int) $event['employee_id']] ?? '') ?></td>
                            <td><?= nl2br(esc((string) ($event['reason'] ?? ''))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script type="text/javascript">
(function() {
    'use strict';

    $('#presale_tabs a[data-toggle="tab"]').on('shown.bs.tab', function() {
        $('#presale_tabs a[role="tab"]').attr('aria-selected', 'false');
        $('#presale_tabs li.active a[role="tab"]').attr('aria-selected', 'true');
    });

    $('#presale_take_payment').on('click', function() {
        var $button = $(this).prop('disabled', true);

        $.post('<?= site_url('presales/addPayment/' . $id) ?>', {
            amount: $('#presale_payment_amount').val(),
            payment_type_code: $('#presale_payment_type').val(),
            reference_code: $('#presale_payment_reference').val()
        }, function(response) {
            if (response.success) {
                window.location.href = response.receipt_url;
                return;
            }

            $button.prop('disabled', false);
            $.notify(response.message, { type: 'danger' });
        }, 'json').fail(function() {
            $button.prop('disabled', false);
        });
    });
})();
</script>
