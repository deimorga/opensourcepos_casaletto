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
 * @var array                      $config
 */
$json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-default btn-sm pull-right" href="<?= site_url('presales') ?>">
        <span class="glyphicon glyphicon-list">&nbsp;</span><?= esc(lang('Presales.back')) ?>
    </a>
    <h3 style="margin-top: 0;"><?= esc(lang('Presales.new')) ?></h3>
</div>

<?php if ($campaigns === []): ?>
    <div class="alert alert-info" role="status"><?= esc(lang('Presales.no_campaign_selling')) ?></div>
<?php else: ?>

<?php if (! $has_open_shift): ?>
    <div class="alert alert-warning" role="alert"><?= esc(lang('Presales.no_open_cashup_warning')) ?></div>
<?php endif; ?>

<?= form_open('presales/save', ['id' => 'presale_form', 'class' => 'form-horizontal', 'autocomplete' => 'off']) ?>
    <input type="hidden" name="customer_id" id="presale_customer_id" value="">

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
        <tfoot>
            <tr>
                <td colspan="3">
                    <button type="button" class="btn btn-default btn-sm" id="presale_add_line">
                        <span class="glyphicon glyphicon-plus">&nbsp;</span><?= esc(lang('Presales.add_product')) ?>
                    </button>
                </td>
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
        <div class="form-group form-group-sm">
            <?= form_label(lang('Presales.comment'), 'presale_comment', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-6">
                <textarea name="comment" id="presale_comment" class="form-control input-sm" rows="2"></textarea>
            </div>
        </div>
        <div class="form-group form-group-sm">
            <div class="col-xs-offset-3 col-xs-6">
                <button type="submit" class="btn btn-primary btn-sm" id="presale_submit">
                    <span class="glyphicon glyphicon-ok">&nbsp;</span><?= esc(lang('Presales.register')) ?>
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
    var text = <?= json_encode([
        'remove'              => lang('Presales.remove'),
        'kit'                 => lang('Presales.kit'),
        'initial_installment' => lang('Presales.initial_installment'),
        'customer_created'    => lang('Presales.customer_created'),
        'product'             => lang('Presales.product'),
        'quantity'            => lang('Presales.quantity'),
        'due_date'            => lang('Presales.due_date'),
        'amount'              => lang('Presales.amount'),
    ], $json_flags) ?>;

    <?= view('partial/datepicker_locale', ['format' => dateformat_bootstrap($config['dateformat'])]) ?>

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

    var add_line = function() {
        var campaign = current();
        if (!campaign) { return; }

        var index = line_index++;
        var $select = $('<select class="form-control input-sm presale-item">')
            .attr('name', 'lines[' + index + '][item_id]')
            .attr('aria-label', text.product);

        campaign.items.forEach(function(item) {
            var label = item.name + (item.number ? ' (' + item.number + ')' : '') + (item.is_kit ? ' · ' + text.kit : '');
            $select.append($('<option>').val(item.item_id).text(label));
        });

        var $quantity = $('<input type="text" class="form-control input-sm presale-quantity" value="1">')
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
        $date.datetimepicker(pickerconfig({ minView: 2 }));
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

        add_line();
    };

    var preview_timer = null;

    var preview = function() {
        clearTimeout(preview_timer);
        preview_timer = setTimeout(function() {
            $.post('<?= site_url('presales/preview') ?>', $('#presale_form').serialize(), function(response) {
                if (!response.success) {
                    $('#presale_total').text('');
                    $('#presale_plan_message').html(response.message || '').removeClass('text-success').addClass('text-danger');
                    return;
                }

                $('#presale_total').text(response.total);
                $('#presale_minimum').html(response.minimum || '');

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
    $('#presale_add_line').on('click', add_line);
    $('#presale_add_installment').on('click', function() { add_installment('', ''); });

    $('#presale_lines').on('change', '.presale-item', function() {
        show_price($(this).closest('tr'));
        preview();
    }).on('keyup change', '.presale-quantity', preview);

    $('#presale_plan').on('keyup change', 'input', preview);

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

    load_campaign();
    add_installment(today, '');
});
</script>

<?php endif; ?>

<?= view('partial/footer') ?>
