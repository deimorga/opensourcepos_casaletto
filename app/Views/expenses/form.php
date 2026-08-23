<?php
/**
 * @var object $expenses_info
 * @var array $payment_options
 * @var array $expense_categories
 * @var array $employees
 * @var bool $can_assign_employee
 * @var bool $can_choose_cash_source
 * @var string $controller_name
 * @var array $config
 */

// The payment dropdown submits translated labels, so the browser is handed the label => code map
// and compares on the code. Comparing against the translated word is precisely the mechanism that
// left the expenses payment filters matching nothing for months.
$payment_type_codes = [];
foreach (array_keys($payment_options) as $payment_option) {
    $payment_type_codes[$payment_option] = payment_type_code_from_label($payment_option);
}
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open("expenses/save/$expenses_info->expense_id", ['id' => 'expenses_edit_form', 'class' => 'form-horizontal']) ?>
    <fieldset id="item_basic_info">

        <div class="form-group form-group-sm">
            <?= form_label(lang('Expenses.info'), 'expenses_info', ['class' => 'control-label col-xs-3']) ?>
            <?= form_label(!empty($expenses_info->expense_id) ? lang('Expenses.expense_id') . " $expenses_info->expense_id" : '', 'expenses_info_id', ['class' => 'control-label col-xs-8', 'style' => 'text-align: left']) ?>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Expenses.date'), 'date', ['class' => 'required control-label col-xs-3']) ?>
            <div class="col-xs-6">
                <div class="input-group">
                    <span class="input-group-addon input-sm"><span class="glyphicon glyphicon-calendar"></span></span>
                    <?= form_input([
                        'name'     => 'date',
                        'class'    => 'form-control input-sm datetime',
                        'value'    => to_datetime(strtotime($expenses_info->date)),
                        'readonly' => 'readonly'
                    ]) ?>
                </div>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Expenses.supplier_name'), 'supplier_name', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-6">
                <?= form_input([
                    'name'  => 'supplier_name',
                    'id'    => 'supplier_name',
                    'class' => 'form-control input-sm',
                    'value' => lang('Expenses.start_typing_supplier_name')
                ]);
                echo form_input([
                    'type' => 'hidden',
                    'name' => 'supplier_id',
                    'id'   => 'supplier_id'
                ]) ?>
            </div>
            <div class="col-xs-2">
                <a id="remove_supplier_button" class="btn btn-danger btn-sm" title="Remove Supplier">
                    <span class="glyphicon glyphicon-remove"></span>
                </a>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Expenses.supplier_tax_code'), 'supplier_tax_code', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-6">
                <?= form_input([
                    'name'  => 'supplier_tax_code',
                    'id'    => 'supplier_tax_code',
                    'class' => 'form-control input-sm',
                    'value' => $expenses_info->supplier_tax_code
                ]) ?>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Expenses.amount'), 'amount', ['class' => 'required control-label col-xs-3']) ?>
            <div class="col-xs-6">
                <div class="input-group input-group-sm">
                    <?php if (!is_right_side_currency_symbol()): ?>
                        <span class="input-group-addon input-sm"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                    <?= form_input([
                        'name'  => 'amount',
                        'id'    => 'amount',
                        'class' => 'form-control input-sm',
                        'value' => to_currency_no_money($expenses_info->amount)
                    ]) ?>
                    <?php if (is_right_side_currency_symbol()): ?>
                        <span class="input-group-addon input-sm"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Expenses.tax_amount'), 'tax_amount', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-6">
                <div class="input-group input-group-sm">
                    <?php if (!is_right_side_currency_symbol()): ?>
                        <span class="input-group-addon input-sm"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                    <?= form_input([
                        'name'  => 'tax_amount',
                        'id'    => 'tax_amount',
                        'class' => 'form-control input-sm',
                        'value' => to_currency_no_money($expenses_info->tax_amount)
                    ]) ?>
                    <?php if (is_right_side_currency_symbol()): ?>
                        <span class="input-group-addon input-sm"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Expenses.payment'), 'payment_type', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-6">
                <?= form_dropdown('payment_type', $payment_options, $expenses_info->payment_type, ['class' => 'form-control', 'id' => 'payment_type']) ?>
            </div>
        </div>

        <?php // Which cash paid for it, asked only when the payment is cash. A bank transfer comes out of no cash pocket, so the question does not apply and the field is hidden rather than answered. ?>
        <div class="form-group form-group-sm">
            <?= form_label(lang('Expenses.cash_source'), 'cash_source', ['class' => ($can_choose_cash_source ? 'required ' : '') . 'control-label col-xs-3']) ?>
            <div class="col-xs-6">
                <?php if ($can_choose_cash_source): ?>
                    <?php // Deliberately no preselected option. An administrator reaches both the till and the collected cash, so the source cannot be deduced for them, and a default would invite leaving it wrong. ?>
                    <?= form_dropdown('cash_source', array_merge(['' => ''], cash_source_options()), $expenses_info->cash_source, ['class' => 'form-control', 'id' => 'cash_source']) ?>
                <?php else: ?>
                    <?php // A cashier only ever reaches the till. Shown greyed out instead of hidden so they can see the distinction exists and why their expense always comes out of the drawer. The server ignores this value and decides on its own. ?>
                    <?= form_hidden('cash_source', 'register') ?>
                    <?= form_input([
                        'name'     => 'cash_source_label',
                        'id'       => 'cash_source',
                        'class'    => 'form-control input-sm',
                        'value'    => cash_source_label('register'),
                        'readonly' => 'readonly'
                    ]) ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Expenses_categories.name'), 'category', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-6">
                <?= form_dropdown('expense_category_id', $expense_categories, $expenses_info->expense_category_id, ['class' => 'form-control', 'id' => 'category']) ?>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Expenses.employee'), 'employee', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-6">
                <?php if ($can_assign_employee): ?>
                    <?php // form_dropdown() escapes option values but not option labels, so the employee names must be escaped here. ?>
                    <?= form_dropdown('employee_id', esc($employees), $expenses_info->employee_id, 'id="employee_id" class="form-control"') ?>
                <?php else: ?>
                    <?= form_hidden('employee_id', $expenses_info->employee_id) ?>
                    <?= form_input(['name' => 'employee_name', 'value' => esc($employees[$expenses_info->employee_id] ?? ''), 'class' => 'form-control', 'readonly' => 'readonly']) ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Expenses.description'), 'description', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-6">
                <?= form_textarea([
                    'name'  => 'description',
                    'id'    => 'description',
                    'class' => 'form-control input-sm',
                    'value' => $expenses_info->description
                ]) ?>
            </div>
        </div>

        <?php if (!empty($expenses_info->expense_id)) { ?>
            <div class="form-group form-group-sm">
                <?= form_label(lang('Expenses.is_deleted') . ':', 'deleted', ['class' => 'control-label col-xs-3']) ?>
                <div class="col-xs-5">
                    <?= form_checkbox([
                        'name'    => 'deleted',
                        'id'      => 'deleted',
                        'value'   => 1,
                        'checked' => $expenses_info->deleted == 1
                    ]) ?>
                </div>
            </div>
        <?php } ?>

    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    // Validation and submit handling
    $(document).ready(function() {
        <?= view('partial/datepicker_locale') ?>

        $('#supplier_name').click(function() {
            $(this).attr('value', '');
        });

        $('#supplier_name').autocomplete({
            source: '<?= "suppliers/suggest" ?>',
            minChars: 0,
            delay: 10,
            select: function(event, ui) {
                $('#supplier_id').val(ui.item.value);
                $(this).val(ui.item.label);
                $(this).attr('readonly', 'readonly');
                $('#remove_supplier_button').css('display', 'inline-block');
                return false;
            }
        });

        $('#supplier_name').blur(function() {
            $(this).attr('value', "<?= lang('Expenses.start_typing_supplier_name') ?>");
        });

        $('#remove_supplier_button').css('display', 'none');

        $('#remove_supplier_button').click(function() {
            $('#supplier_id').val('');
            $('#supplier_name').removeAttr('readonly');
            $('#supplier_name').val('');
            $(this).css('display', 'none');
        });

        <?php if ($expenses_info->expense_id != -1) { ?>
            $('#supplier_id').val('<?= $expenses_info->supplier_id ?>');
            $('#supplier_name').val('<?= esc($expenses_info->supplier_name, 'js') ?>').attr('readonly', 'readonly');
            $('#remove_supplier_button').css('display', 'inline-block');
        <?php } ?>

        // Keyed by the label the dropdown submits, valued with the stable code. Never compare the
        // selected text against a translated word -- see the note above this file's markup.
        var payment_type_codes = <?= json_encode($payment_type_codes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        var is_cash_payment = function() {
            return payment_type_codes[$('#payment_type').val()] === 'cash';
        };

        var show_hide_cash_source = function() {
            $('#cash_source').parents('.form-group').toggleClass('hidden', !is_cash_payment());
        };

        $('#payment_type').change(show_hide_cash_source);
        show_hide_cash_source();

        $('#expenses_edit_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        table_support.handle_submit("<?= esc($controller_name) ?>", response);
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            ignore: '',

            rules: {
                supplier_name: 'required',
                category: 'required',
                expense_category_id: 'required',
                date: {
                    required: true
                },
                amount: {
                    required: true,
                    remote: "<?= "$controller_name/checkNumeric" ?>"
                },
                tax_amount: {
                    remote: "<?= "$controller_name/checkNumeric" ?>"
                }<?php if ($can_choose_cash_source): ?>,
                // Not a nicety. manage_tables.js calls dialog_support.hide() before it looks at
                // response.success, so a server rejection closes the modal anyway and the user
                // loses everything they typed. Catching it here is what keeps that rejection from
                // happening in normal use; the server check stays as the guard for anyone who
                // bypasses the form.
                cash_source: {
                    required: function() {
                        return is_cash_payment();
                    }
                }<?php endif; ?>

            },

            messages: {
                category: "<?= lang('Expenses.category_required') ?>",
                expense_category_id: "<?= lang('Expenses_categories.category_name_required') ?>",
                date: {
                    required: "<?= lang('Expenses.date_required') ?>"

                },
                amount: {
                    required: "<?= lang('Expenses.amount_required') ?>",
                    remote: "<?= lang('Expenses.amount_number') ?>"
                },
                tax_amount: {
                    remote: "<?= lang('Expenses.tax_amount_number') ?>"
                }<?php if ($can_choose_cash_source): ?>,
                cash_source: {
                    required: "<?= esc(lang('Expenses.cash_source_required'), 'js') ?>"
                }<?php endif; ?>

            }
        }, form_support.error));
    });
</script>
