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
 * - Cancel: only for presales_manage, while open. Opens the cancellation form in place (reason,
 *   what is given back, its payment type); paid, given back and kept are worked out by the server
 *   (presales/cancelPreview) as the amount is typed. It posts to presales/cancel, which checks the
 *   permission again, and goes to the cancellation document. A presale whose delivery is open in the
 *   register is refused by the model with a message that says what to do.
 * - Once canceled, the cancellation document can be reprinted.
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
            <button type="button" class="btn btn-danger btn-sm" id="presale_cancel" aria-expanded="false" aria-controls="presale_cancel_block">
                <span class="glyphicon glyphicon-remove">&nbsp;</span><?= esc(lang('Presales.cancel')) ?>
            </button>
        <?php endif; ?>

        <?php if ($presale['status'] === Presale::STATUS_CANCELED): ?>
            <a class="btn btn-default btn-sm" id="presale_cancel_receipt" href="<?= site_url('presales/cancelReceipt/' . $id) ?>" target="_blank" rel="noopener">
                <span class="glyphicon glyphicon-print">&nbsp;</span><?= esc(lang('Presales.print_cancel_receipt')) ?>
            </a>
        <?php endif; ?>
    </div>

    <?php if ($is_open && $can_manage): ?>
        <div class="panel panel-danger" id="presale_cancel_block" role="region" aria-labelledby="presale_cancel_title" style="display: none;">
            <div class="panel-heading"><strong id="presale_cancel_title"><?= esc(lang('Presales.cancel')) ?> <?= esc($presale['number']) ?></strong></div>
            <div class="panel-body form-horizontal">
                <div class="form-group form-group-sm">
                    <label class="col-xs-4 control-label required" for="presale_cancel_reason"><?= esc(lang('Presales.cancel_reason')) ?></label>
                    <div class="col-xs-8">
                        <textarea id="presale_cancel_reason" class="form-control input-sm" rows="2" maxlength="1000" required aria-required="true"></textarea>
                    </div>
                </div>
                <div class="form-group form-group-sm">
                    <label class="col-xs-4 control-label" for="presale_cancel_amount"><?= esc(lang('Presales.refund_amount')) ?></label>
                    <div class="col-xs-8">
                        <input type="text" id="presale_cancel_amount" class="form-control input-sm" inputmode="decimal" value="0" aria-describedby="presale_cancel_help">
                        <p class="help-block small" id="presale_cancel_help"><?= esc(lang('Presales.refund_help')) ?></p>
                    </div>
                </div>
                <div class="form-group form-group-sm" id="presale_cancel_type_row" style="display: none;">
                    <label class="col-xs-4 control-label" for="presale_cancel_type"><?= esc(lang('Presales.refund_payment_type')) ?></label>
                    <div class="col-xs-8">
                        <?= form_dropdown('payment_type_code', esc($payment_types), 'cash', ['id' => 'presale_cancel_type', 'class' => 'form-control input-sm']) ?>
                    </div>
                </div>
                <div class="form-group form-group-sm" id="presale_cancel_reference_row" style="display: none;">
                    <label class="col-xs-4 control-label" for="presale_cancel_reference"><?= esc(lang('Presales.reference')) ?></label>
                    <div class="col-xs-8">
                        <input type="text" id="presale_cancel_reference" class="form-control input-sm" maxlength="60">
                    </div>
                </div>
                <?php if (! $has_open_shift): ?>
                    <div class="alert alert-warning" role="alert" id="presale_cancel_no_shift" style="display: none;"><?= esc(lang('Presales.no_open_cashup')) ?></div>
                <?php endif; ?>
                <table class="table table-condensed" style="margin-bottom: 10px;" aria-live="polite">
                    <tr><th><?= esc(lang('Presales.paid')) ?></th><td style="text-align: right;" id="presale_cancel_paid"><?= esc(to_currency($presale['paid'])) ?></td></tr>
                    <tr><th><?= esc(lang('Presales.refunded')) ?></th><td style="text-align: right;" id="presale_cancel_refund"><?= esc(to_currency('0')) ?></td></tr>
                    <tr><th><?= esc(lang('Presales.kept')) ?></th><td style="text-align: right;"><strong id="presale_cancel_kept"><?= esc(to_currency($presale['paid'])) ?></strong></td></tr>
                </table>
                <p class="text-danger small" id="presale_cancel_error" role="alert"></p>
                <button type="button" class="btn btn-danger btn-sm" id="presale_cancel_confirm"><?= esc(lang('Presales.cancel_confirm')) ?></button>
                <button type="button" class="btn btn-default btn-sm" id="presale_cancel_back"><?= esc(lang('Presales.cancel_back')) ?></button>
            </div>
        </div>
    <?php endif; ?>

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

    // Cancelling. The figures are the server's: amounts are typed in the business's number format.
    var $cancelBlock = $('#presale_cancel_block');

    if ($cancelBlock.length) {
        var previewTimer = null;
        var previewSeq = 0;

        var showRefundFields = function(hasRefund) {
            $('#presale_cancel_type_row, #presale_cancel_reference_row').toggle(hasRefund);
            $('#presale_cancel_no_shift').toggle(hasRefund);
        };

        var preview = function() {
            var seq = ++previewSeq;

            $.post('<?= site_url('presales/cancelPreview/' . $id) ?>', {
                refund_amount: $('#presale_cancel_amount').val()
            }, function(response) {
                if (seq !== previewSeq) {
                    return;
                }

                if (!response.success) {
                    // Escaped by the server.
                    $('#presale_cancel_error').html(response.message);
                    return;
                }

                $('#presale_cancel_error').empty();
                $('#presale_cancel_paid').html(response.paid);
                $('#presale_cancel_refund').html(response.refund);
                $('#presale_cancel_kept').html(response.kept);
                showRefundFields(response.has_refund);
            }, 'json');
        };

        $('#presale_cancel').on('click', function() {
            var opening = !$cancelBlock.is(':visible');

            $cancelBlock.toggle(opening);
            $(this).attr('aria-expanded', opening ? 'true' : 'false');

            if (opening) {
                $('#presale_cancel_reason').trigger('focus');
            }
        });

        $('#presale_cancel_back').on('click', function() {
            $cancelBlock.hide();
            $('#presale_cancel').attr('aria-expanded', 'false').trigger('focus');
        });

        $('#presale_cancel_amount').on('input', function() {
            clearTimeout(previewTimer);
            previewTimer = setTimeout(preview, 300);
        });

        $('#presale_cancel_confirm').on('click', function() {
            var $button = $(this).prop('disabled', true);

            $.post('<?= site_url('presales/cancel/' . $id) ?>', {
                reason: $('#presale_cancel_reason').val(),
                refund_amount: $('#presale_cancel_amount').val(),
                payment_type_code: $('#presale_cancel_type').val(),
                reference_code: $('#presale_cancel_reference').val()
            }, function(response) {
                if (response.success) {
                    window.location.href = response.receipt_url;
                    return;
                }

                $button.prop('disabled', false);
                $.notify(response.message, { type: 'danger' });
            }, 'json').fail(function(xhr) {
                $button.prop('disabled', false);

                if (xhr.responseJSON && xhr.responseJSON.message) {
                    $.notify(xhr.responseJSON.message, { type: 'danger' });
                }
            });
        });
    }
})();
</script>
