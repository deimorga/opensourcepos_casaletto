<?php
/**
 * Registering a presale (docs/Funcional/venta-anticipada.md §4.3): campaign, customer, products,
 * delivery date, the instalment plan and the first instalment, taken now.
 *
 * A page and not a dialog, because the "new customer" button opens the Customers dialog, the same one
 * the register uses (sales/register.php), and a dialog inside a dialog loses its focus and its keys.
 *
 * The screen does no money arithmetic. What the lines cost, what the plan adds up to and the campaign's
 * minimum come from presales/preview, which reads the amounts with parse_decimals() -- the business's
 * number format is the server's to read. Every rule is checked again on saving, by the model.
 *
 * Names reach the page as JSON and are put on screen with .text(), never as HTML.
 *
 * @var list<array<string, mixed>> $campaigns
 * @var array<string, string>      $payment_types
 * @var bool                       $can_add_customer
 * @var bool                       $has_open_shift
 * @var string                     $today
 * @var list<int>                  $discounts      Presale::discount_options(): 0, 5 ... 40
 * @var array                      $config
 * @var array<string, mixed>|null  $modify         Presales::getEdit(): the presale being changed (§4.17)
 *
 * CHANGING A PRESALE
 *
 * With $modify the same form changes an open presale: the campaign is fixed, everything else comes
 * filled in, the seller discount is shown but changed only from the detail (an administrator's
 * action), and the first instalment gives way to the reason -- required -- and, when the new total
 * falls below what was paid, the refund. Products the presale already had show their agreed price.
 */
$json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
$modify ??= null;
?>

<?= view('partial/header', [], ['saveData' => false]) ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-default btn-sm pull-right" href="<?= site_url('presales') ?>">
        <span class="glyphicon glyphicon-list">&nbsp;</span><?= esc(lang('Presales.back')) ?>
    </a>
    <h3 style="margin-top: 0;"><?= esc($modify === null ? lang('Presales.new') : lang('Presales.modify_title', [$modify['number']])) ?></h3>
</div>

<?php if ($campaigns === []): ?>
    <div class="alert alert-info" role="status"><?= esc(lang('Presales.no_campaign_selling')) ?></div>
<?php else: ?>

<?php if (! $has_open_shift && $modify === null): ?>
    <div class="alert alert-warning" role="alert"><?= esc(lang('Presales.no_open_cashup_warning')) ?></div>
<?php endif; ?>

<?php if ($modify !== null): ?>
    <div class="alert alert-info" role="status"><?= esc(lang('Presales.modify_help')) ?></div>
<?php endif; ?>

<?= form_open($modify === null ? 'presales/save' : 'presales/' . (int) $modify['presale_id'] . '/update', ['id' => 'presale_form', 'class' => 'form-horizontal', 'autocomplete' => 'off']) ?>
    <input type="hidden" name="customer_id" id="presale_customer_id" value="">
    <?php if ($modify !== null): ?>
        <input type="hidden" name="presale_id" value="<?= (int) $modify['presale_id'] ?>">
    <?php endif; ?>

    <fieldset>
        <div class="form-group form-group-sm">
            <?= form_label(lang('Presales.campaign'), 'presale_campaign', ['class' => 'required control-label col-xs-3']) ?>
            <div class="col-xs-6">
                <select name="campaign_id" id="presale_campaign" class="form-control input-sm" required>
                    <?php if (count($campaigns) > 1): ?>
                        <option value=""><?= esc(lang('Presales.select_campaign')) ?></option>
                    <?php endif; ?>
                    <?php foreach ($campaigns as $campaign): ?>
                        <option value="<?= (int) $campaign['campaign_id'] ?>"><?= esc($campaign['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Presales.customer'), 'presale_customer', ['class' => 'required control-label col-xs-3']) ?>
            <div class="col-xs-6">
                <input type="text" id="presale_customer" class="form-control input-sm" placeholder="<?= esc(lang('Presales.customer_search'), 'attr') ?>">
                <p class="form-control-static" id="presale_customer_chosen" hidden>
                    <strong id="presale_customer_label"></strong>
                    <button type="button" class="btn btn-link btn-xs" id="presale_customer_change"><?= esc(lang('Presales.customer_change')) ?></button>
                </p>
            </div>
            <?php if ($can_add_customer): ?>
                <div class="col-xs-3">
                    <button type="button" class="btn btn-info btn-sm modal-dlg" data-btn-submit="<?= esc(lang('Common.submit'), 'attr') ?>" data-href="<?= site_url('customers/view') ?>" title="<?= esc(lang('Sales.new_customer'), 'attr') ?>">
                        <span class="glyphicon glyphicon-user">&nbsp;</span><?= esc(lang('Sales.new_customer')) ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Presales.delivery_date'), 'presale_delivery_date', ['class' => 'required control-label col-xs-3']) ?>
            <div class="col-xs-4">
                <select name="delivery_date_id" id="presale_delivery_date" class="form-control input-sm" required></select>
            </div>
        </div>
    </fieldset>

    <h4><?= esc(lang('Presales.lines')) ?></h4>
    <table class="table table-condensed" id="presale_lines">
        <thead>
            <tr>
                <th style="width: 50%;"><?= esc(lang('Presales.product')) ?></th>
                <th style="width: 15%;"><?= esc(lang('Presales.price')) ?></th>
                <th style="width: 15%;"><?= esc(lang('Presales.quantity')) ?></th>
                <th style="width: 15%; text-align: right;"><?= esc(lang('Presales.line_total')) ?></th>
                <th style="width: 5%;"><span class="sr-only"><?= esc(lang('Presales.remove')) ?></span></th>
            </tr>
        </thead>
        <tbody></tbody>
        <?php /* Subtotal, the cashier's discount and the total, in the same column as the lines, top to
           bottom, so the figure the cashier reads last is what the customer pays (owner's request of
           2026-10-27). The discount (venta-anticipada.md §4.16) is on top of the campaign price, chosen
           from a closed list, and marked when the brand pays it back. Every figure comes from
           presales/preview. */ ?>
        <tfoot>
            <tr>
                <td colspan="2">
                    <button type="button" class="btn btn-default btn-sm" id="presale_add_line">
                        <span class="glyphicon glyphicon-plus">&nbsp;</span><?= esc(lang('Presales.add_product')) ?>
                    </button>
                </td>
                <th style="text-align: right;"><?= esc(lang('Presales.gross_total')) ?></th>
                <td style="text-align: right;" id="presale_gross" aria-live="polite"></td>
                <td></td>
            </tr>
            <tr id="presale_discount_block">
                <td colspan="2" class="form-inline">
                    <label for="presale_discount"><?= esc(lang('Presales.discount')) ?></label>
                    <?php if ($modify === null): ?>
                        <select name="discount_percent" id="presale_discount" class="form-control input-sm">
                            <?php foreach ($discounts as $percent): ?>
                                <option value="<?= (int) $percent ?>"><?= (int) $percent ?>%</option>
                            <?php endforeach; ?>
                        </select>
                        <label class="checkbox-inline">
                            <input type="checkbox" name="discount_reimbursable" id="presale_discount_reimbursable" value="1" disabled>
                            <?= esc(lang('Presales.discount_reimbursable')) ?>
                        </label>
                    <?php else: ?>
                        <strong><?= (int) $modify['discount_percent'] ?>%</strong>
                        <span class="help-inline text-muted"><?= esc(lang('Presales.modify_discount_note')) ?></span>
                    <?php endif; ?>
                </td>
                <th style="text-align: right;"><?= esc(lang('Presales.discount')) ?></th>
                <td style="text-align: right;" class="text-danger" id="presale_discount_amount" aria-live="polite"></td>
                <td></td>
            </tr>
            <tr>
                <td colspan="2"></td>
                <th style="text-align: right;"><?= esc(lang('Presales.total')) ?></th>
                <td style="text-align: right;"><strong id="presale_total" aria-live="polite"></strong></td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <h4><?= esc(lang('Presales.installments')) ?> <small id="presale_minimum"></small></h4>
    <table class="table table-condensed" id="presale_plan">
        <thead>
            <tr>
                <th style="width: 30%;"><?= esc(lang('Presales.due_date')) ?></th>
                <th style="width: 30%;"><?= esc(lang('Presales.amount')) ?></th>
                <th style="width: 35%;"></th>
                <th style="width: 5%;"><span class="sr-only"><?= esc(lang('Presales.remove')) ?></span></th>
            </tr>
        </thead>
        <tbody></tbody>
        <tfoot>
            <tr>
                <td>
                    <button type="button" class="btn btn-default btn-sm" id="presale_add_installment">
                        <span class="glyphicon glyphicon-plus">&nbsp;</span><?= esc(lang('Presales.add_installment')) ?>
                    </button>
                </td>
                <td colspan="3"><span id="presale_plan_message" aria-live="polite"></span></td>
            </tr>
        </tfoot>
    </table>

    <?php if ($modify === null): ?>
    <h4><?= esc(lang('Presales.initial_payment')) ?></h4>
    <fieldset>
        <div class="form-group form-group-sm">
            <?= form_label(lang('Presales.payment_type'), 'presale_payment_type', ['class' => 'required control-label col-xs-3']) ?>
            <div class="col-xs-4">
                <?= form_dropdown('payment_type_code', esc($payment_types), 'cash', ['id' => 'presale_payment_type', 'class' => 'form-control input-sm']) ?>
            </div>
        </div>
        <div class="form-group form-group-sm">
            <?= form_label(lang('Presales.amount'), 'presale_payment_amount', ['class' => 'required control-label col-xs-3']) ?>
            <div class="col-xs-4">
                <input type="text" name="payment_amount" id="presale_payment_amount" class="form-control input-sm" inputmode="decimal" required>
            </div>
        </div>
        <div class="form-group form-group-sm">
            <?= form_label(lang('Presales.reference'), 'presale_reference', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-4">
                <input type="text" name="reference_code" id="presale_reference" class="form-control input-sm" maxlength="60">
            </div>
        </div>
    <?php endif; ?>
    <?php if ($modify !== null): ?>
    <h4><?= esc(lang('Presales.modify_money')) ?></h4>
    <fieldset>
        <div class="form-group form-group-sm">
            <span class="control-label col-xs-3"><?= esc(lang('Presales.paid')) ?></span>
            <div class="col-xs-6"><p class="form-control-static" id="presale_paid" aria-live="polite"></p></div>
        </div>
        <div class="form-group form-group-sm">
            <span class="control-label col-xs-3"><?= esc(lang('Presales.balance')) ?></span>
            <div class="col-xs-6"><p class="form-control-static" id="presale_balance" aria-live="polite"></p></div>
        </div>
        <div id="presale_refund_block" hidden>
            <div class="form-group form-group-sm">
                <span class="control-label col-xs-3"><?= esc(lang('Presales.modify_refund')) ?></span>
                <div class="col-xs-6"><p class="form-control-static text-danger"><strong id="presale_refund" aria-live="polite"></strong></p></div>
            </div>
            <div class="form-group form-group-sm">
                <?= form_label(lang('Presales.payment_type'), 'presale_refund_type', ['class' => 'required control-label col-xs-3']) ?>
                <div class="col-xs-4">
                    <?= form_dropdown('refund_payment_type_code', esc($payment_types), 'cash', ['id' => 'presale_refund_type', 'class' => 'form-control input-sm']) ?>
                </div>
            </div>
            <div class="form-group form-group-sm">
                <?= form_label(lang('Presales.reference'), 'presale_refund_reference', ['class' => 'control-label col-xs-3']) ?>
                <div class="col-xs-4">
                    <input type="text" name="refund_reference_code" id="presale_refund_reference" class="form-control input-sm" maxlength="60">
                </div>
            </div>
            <?php if (! $has_open_shift): ?>
                <div class="alert alert-warning" role="alert"><?= esc(lang('Presales.no_open_cashup_warning')) ?></div>
            <?php endif; ?>
        </div>
        <div class="form-group form-group-sm">
            <?= form_label(lang('Presales.modify_reason'), 'presale_reason', ['class' => 'required control-label col-xs-3']) ?>
            <div class="col-xs-6">
                <textarea name="reason" id="presale_reason" class="form-control input-sm" rows="2" required></textarea>
            </div>
        </div>
    <?php endif; ?>
        <div class="form-group form-group-sm">
            <?= form_label(lang('Presales.comment'), 'presale_comment', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-6">
                <textarea name="comment" id="presale_comment" class="form-control input-sm" rows="2"><?= esc((string) ($modify['comment'] ?? '')) ?></textarea>
            </div>
        </div>
        <div class="form-group form-group-sm">
            <div class="col-xs-offset-3 col-xs-6">
                <button type="submit" class="btn btn-primary btn-sm" id="presale_submit">
                    <span class="glyphicon glyphicon-ok">&nbsp;</span><?= esc(lang($modify === null ? 'Presales.register' : 'Presales.modify_save')) ?>
                </button>
            </div>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
$(document).ready(function() {
    'use strict';

    var campaigns = <?= json_encode($campaigns, $json_flags) ?>;
    var today = <?= json_encode($today, $json_flags) ?>;
    var modify = <?= json_encode($modify, $json_flags) ?>;
    var text = <?= json_encode([
        'remove'              => lang('Presales.remove'),
        'kit'                 => lang('Presales.kit'),
        'initial_installment' => lang('Presales.initial_installment'),
        'customer_created'    => lang('Presales.customer_created'),
        'product'             => lang('Presales.product'),
        'quantity'            => lang('Presales.quantity'),
        'due_date'            => lang('Presales.due_date'),
        'amount'              => lang('Presales.amount'),
        'agreed'              => lang('Presales.agreed_price'),
    ], $json_flags) ?>;

    <?= view('partial/datepicker_locale', ['format' => dateformat_bootstrap($config['dateformat'])], ['saveData' => false]) ?>

    var current = function() {
        var id = parseInt($('#presale_campaign').val(), 10);
        return campaigns.find(function(campaign) { return campaign.campaign_id === id; }) || null;
    };

    var line_index = 0;
    var installment_index = 0;

    var remove_button = function() {
        return $('<button type="button" class="btn btn-link btn-xs presale-remove">')
            .attr('aria-label', text.remove)
            .append('<span class="glyphicon glyphicon-trash" aria-hidden="true"></span>');
    };

    var add_line = function(item_id, quantity) {
        var campaign = current();
        if (!campaign) { return; }

        var index = line_index++;
        var $select = $('<select class="form-control input-sm presale-item">')
            .attr('name', 'lines[' + index + '][item_id]')
            .attr('aria-label', text.product);

        campaign.items.forEach(function(item) {
            var label = item.name + (item.number ? ' (' + item.number + ')' : '') + (item.is_kit ? ' · ' + text.kit : '') + (item.agreed ? ' · ' + text.agreed : '');
            $select.append($('<option>').val(item.item_id).text(label));
        });

        if (item_id) { $select.val(item_id); }

        var $quantity = $('<input type="text" class="form-control input-sm presale-quantity">')
            .val(quantity || '1')
            .attr('name', 'lines[' + index + '][quantity]')
            .attr('aria-label', text.quantity);

        var $row = $('<tr>')
            .append($('<td>').append($select))
            .append($('<td class="presale-price">'))
            .append($('<td>').append($quantity))
            .append($('<td class="presale-amount" style="text-align: right;">'))
            .append($('<td>').append(remove_button()));

        $('#presale_lines tbody').append($row);
        show_price($row);
        preview();
    };

    // The price per unit (a kit's components included) and whether the quantity takes decimals.
    var show_price = function($row) {
        var campaign = current();
        var id = parseInt($row.find('.presale-item').val(), 10);
        var item = campaign && campaign.items.find(function(candidate) { return candidate.item_id === id; });

        $row.find('.presale-price').text(item ? item.price : '');
        $row.find('.presale-quantity').attr('inputmode', item && item.is_weight ? 'decimal' : 'numeric');
    };

    var add_installment = function(date, amount) {
        var index = installment_index++;

        var $date = $('<input type="text" class="form-control input-sm presale-due-date">')
            .attr('name', 'installments[' + index + '][due_date]')
            .attr('aria-label', text.due_date)
            .val(date || '');

        var $amount = $('<input type="text" class="form-control input-sm presale-installment-amount" inputmode="decimal">')
            .attr('name', 'installments[' + index + '][amount]')
            .attr('aria-label', text.amount)
            .val(amount || '');

        var $row = $('<tr>')
            .append($('<td>').append($date))
            .append($('<td>').append($amount))
            .append($('<td class="presale-installment-note">'))
            .append($('<td>').append(remove_button()));

        $('#presale_plan tbody').append($row);
        // Date only, configured here and not through pickerconfig(): the shared partial builds its format
        // with the time of day and pickerconfig() ignores the options it is given ($.extend with a stray
        // `1`), so the instalment field got "08/10/2026 20:24:03" and the server, which reads a date, refused
        // the value the picker itself wrote (staging, 2026-10-08). Same settings as campaign_form.php.
        $date.datetimepicker({
            format: <?= json_encode(dateformat_bootstrap($config['dateformat'])) ?>,
            minView: 2,
            autoclose: true,
            todayBtn: true,
            todayHighlight: true,
            bootcssVer: 3,
            language: <?= json_encode(current_language_code()) ?>
        });
        label_installments();
    };

    // The first row of the plan is the initial instalment, whatever was removed before it.
    var label_installments = function() {
        $('#presale_plan tbody tr').each(function(position) {
            $(this).find('.presale-installment-note').text(position === 0 ? text.initial_installment : '');
        });
    };

    var load_campaign = function() {
        var campaign = current();

        $('#presale_lines tbody').empty();
        var $dates = $('#presale_delivery_date').empty();

        if (!campaign) { preview(); return; }

        campaign.dates.forEach(function(date) {
            $dates.append($('<option>').val(date.date_id).text(date.label));
        });

        if (modify) {
            $dates.val(modify.delivery_date_id);
            modify.lines.forEach(function(line) { add_line(line.item_id, line.quantity); });
            return;
        }

        add_line();
    };

    var preview_timer = null;

    var preview = function() {
        clearTimeout(preview_timer);
        preview_timer = setTimeout(function() {
            $.post('<?= site_url('presales/preview') ?>', $('#presale_form').serialize(), function(response) {
                if (!response.success) {
                    $('#presale_total, #presale_gross, #presale_discount_amount').text('');
                    $('#presale_plan_message').html(response.message || '').removeClass('text-success').addClass('text-danger');
                    return;
                }

                $('#presale_total').text(response.total);
                $('#presale_minimum').html(response.minimum || '');
                $('#presale_gross').text(response.gross || '');
                $('#presale_discount_amount').text(response.discount_cell || '');

                if (modify) {
                    $('#presale_paid').text(response.paid || '');
                    $('#presale_balance').text(response.balance || '');
                    $('#presale_refund').text(response.refund || '');
                    $('#presale_refund_block').prop('hidden', !response.has_refund);
                }

                $('#presale_lines tbody tr').each(function(position) {
                    $(this).find('.presale-amount').text(response.lines[position] || '');
                });

                var plan = response.plan;
                $('#presale_plan_message')
                    .html(plan ? plan.message : '')
                    .toggleClass('text-success', !!(plan && plan.matches))
                    .toggleClass('text-danger', !!(plan && !plan.matches));
            }, 'json');
        }, 300);
    };

    $('#presale_campaign').on('change', load_campaign);
    $('#presale_add_line').on('click', function() { add_line(); });
    $('#presale_add_installment').on('click', function() { add_installment('', ''); });

    $('#presale_lines').on('change', '.presale-item', function() {
        show_price($(this).closest('tr'));
        preview();
    }).on('keyup change', '.presale-quantity', preview);

    $('#presale_plan').on('keyup change', 'input', preview);

    // Without a discount there is nothing for the brand to pay back.
    $('#presale_discount').on('change', function() {
        var has_discount = parseInt($(this).val(), 10) > 0;

        $('#presale_discount_reimbursable').prop('disabled', !has_discount);

        if (!has_discount) {
            $('#presale_discount_reimbursable').prop('checked', false);
        }

        preview();
    });

    $('#presale_lines, #presale_plan').on('click', '.presale-remove', function() {
        $(this).closest('tr').remove();
        label_installments();
        preview();
    });

    // The initial instalment is usually what is paid now: offered, never forced.
    $('#presale_plan').on('change', 'tbody tr:first-child .presale-installment-amount', function() {
        if ($.trim($('#presale_payment_amount').val()) === '') {
            $('#presale_payment_amount').val($(this).val());
        }
    });

    // Customer: chosen from the search, or created in place with the Customers dialog.
    var choose_customer = function(id, label) {
        $('#presale_customer_id').val(id);
        $('#presale_customer_label').text(label);
        $('#presale_customer').val('').prop('hidden', true);
        $('#presale_customer_chosen').prop('hidden', false);
        // The total depends on the customer: taxable or not, the register charges differently.
        preview();
    };

    $('#presale_customer').autocomplete({
        source: '<?= site_url('presales/suggestCustomer') ?>',
        minLength: 2,
        delay: 200,
        select: function(event, ui) {
            choose_customer(ui.item.value, ui.item.label);
            return false;
        },
        focus: function() { return false; }
    });

    $('#presale_customer_change').on('click', function() {
        $('#presale_customer_id').val('');
        $('#presale_customer_chosen').prop('hidden', true);
        $('#presale_customer').prop('hidden', false).focus();
        preview();
    });

    // The Customers dialog reports back through table_support.handle_submit("customers", response),
    // as it does in the register. Its message repeats the typed name unescaped, so it is not shown:
    // the label is read back from this module, and put on screen as text.
    table_support.handle_submit = function(resource, response) {
        if (!response.success) {
            $.notify(<?= json_encode(esc(lang('Customers.error_adding_updating')), $json_flags) ?>, { type: 'danger' });
            return;
        }

        $.get('<?= site_url('presales/customer') ?>/' + parseInt(response.id, 10), function(customer) {
            if (customer.success) {
                choose_customer(customer.id, customer.label);
                $.notify(<?= json_encode(esc(lang('Presales.customer_created')), $json_flags) ?>, { type: 'success' });
            }
        }, 'json');
    };

    dialog_support.init('button.modal-dlg');

    $('#presale_form').on('submit', function(event) {
        event.preventDefault();

        var $submit = $('#presale_submit').prop('disabled', true);

        $.post($(this).attr('action'), $(this).serialize(), function(response) {
            if (response.success) {
                window.location.href = response.receipt_url;
                return;
            }

            $submit.prop('disabled', false);
            $.notify(response.message, { type: 'danger' });
        }, 'json').fail(function() {
            $submit.prop('disabled', false);
        });
    });

    if (modify) {
        choose_customer(modify.customer_id, modify.customer_label);
        load_campaign();
        modify.installments.forEach(function(installment) { add_installment(installment.due_date, installment.amount); });
        preview();
    } else {
        load_campaign();
        add_installment(today, '');
    }
});
</script>

<?php endif; ?>

<?= view('partial/footer', [], ['saveData' => false]) ?>
