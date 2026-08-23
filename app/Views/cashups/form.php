<?php
/**
 * @var object $cash_ups_info
 * @var array $employees
 * @var array $collectors
 * @var array $collections
 * @var array|null $reconciliation
 * @var bool $is_open
 * @var string $controller_name
 * @var array $config
 */

$is_new = empty($cash_ups_info->cashup_id) || $cash_ups_info->cashup_id == NEW_ENTRY;
$is_closed = !$is_new && $cash_ups_info->status === 'closed';

// Opening fields are only editable while creating a new turno -- once it
// exists, opening the turno is done and these become context-only.
$open_field_attrs = $is_new ? [] : ['disabled' => 'disabled'];
// Closing fields are editable only during the open -> closed transition.
$close_field_attrs = $is_closed ? ['disabled' => 'disabled'] : [];
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?php if (!$is_new): ?>
<?php
// Three jobs live on this screen and they happen at different moments: handing cash over happens
// several times during the day, closing happens once at the end, and checking the drawer happens
// while closing. Stacked in one column the frequent job ended up underneath the occasional one and
// the cashier had to scroll past the whole close form to write down a delivery.
//
// Same tab markup as employees/form.php, which already solves this for that dialog.
?>
<ul class="nav nav-tabs nav-justified" data-tabs="tabs" id="cashup_tabs" role="tablist">
    <li class="active" role="presentation">
        <a data-toggle="tab" href="#cashup_shift" role="tab" aria-controls="cashup_shift" aria-selected="true"><?= lang('Cashups.tab_shift') ?></a>
    </li>
    <li role="presentation">
        <a data-toggle="tab" href="#cashup_collections" role="tab" aria-controls="cashup_collections" aria-selected="false">
            <?= lang('Cashups.collections') ?>
            <?php if ($collections !== []): ?><span class="badge"><?= count($collections) ?></span><?php endif; ?>
        </a>
    </li>
    <?php if ($reconciliation !== null): ?>
    <li role="presentation">
        <a data-toggle="tab" href="#cashup_reconciliation" role="tab" aria-controls="cashup_reconciliation" aria-selected="false"><?= lang('Cashups.reconciliation') ?></a>
    </li>
    <?php endif; ?>
</ul>

<div class="tab-content" style="padding-top: 15px;">
    <div class="tab-pane fade in active" id="cashup_shift" role="tabpanel">
<?php endif; ?>
<?= form_open('cashups/save/' . $cash_ups_info->cashup_id, ['id' => 'cashups_edit_form', 'class' => 'form-horizontal'])    // TODO: String Interpolation ?>
    <fieldset id="item_basic_info">

        <div class="form-group form-group-sm">
            <?= form_label(lang('Cashups.info'), 'cash_ups_info', ['class' => 'control-label col-xs-3']) ?>
            <?= form_label(!empty($cash_ups_info->cashup_id) ? lang('Cashups.id') . ' ' . $cash_ups_info->cashup_id : '', 'cashup_id', ['class' => 'control-label col-xs-8', 'style' => 'text-align: left']) ?>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Cashups.open_date'), 'open_date', ['class' => 'required control-label col-xs-3']) ?>
            <div class="col-xs-6">
                <div class="input-group">
                    <span class="input-group-addon input-sm">
                        <span class="glyphicon glyphicon-calendar"></span>
                    </span>
                    <?= form_input(array_merge([
                        'name'  => 'open_date',
                        'id'    => 'open_date',
                        'class' => 'form-control input-sm datepicker',
                        'value' => to_datetime(strtotime($cash_ups_info->open_date))
                    ], $open_field_attrs)) ?>
                </div>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Cashups.open_employee'), 'open_employee', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-6">
                <?php // form_dropdown() escapes option values but not option labels, so the employee names must be escaped here. ?>
                <?= form_dropdown('open_employee_id', esc($employees), $cash_ups_info->open_employee_id, array_merge(['id' => 'open_employee_id', 'class' => 'form-control'], $open_field_attrs)) ?>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Cashups.open_amount_cash'), 'open_amount_cash', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-4">
                <div class="input-group input-group-sm">
                    <?php if (!is_right_side_currency_symbol()): ?>
                        <span class="input-group-addon input-sm"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                    <?= form_input(array_merge([
                        'name'  => 'open_amount_cash',
                        'id'    => 'open_amount_cash',
                        'class' => 'form-control input-sm',
                        'value' => to_currency_no_money($cash_ups_info->open_amount_cash)
                    ], $open_field_attrs)) ?>
                    <?php if (is_right_side_currency_symbol()): ?>
                        <span class="input-group-addon input-sm"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>


        <?php if (!$is_new): ?>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Cashups.close_date'), 'close_date', ['class' => 'required control-label col-xs-3']) ?>
            <div class="col-xs-6">
                <div class="input-group">
                    <span class="input-group-addon input-sm">
                        <span class="glyphicon glyphicon-calendar"></span>
                    </span>
                    <?= form_input(array_merge([
                        'name'  => 'close_date',
                        'id'    => 'close_date',
                        'class' => 'form-control input-sm datepicker',
                        'value' => to_datetime(strtotime($cash_ups_info->close_date))
                    ], $close_field_attrs)) ?>
                </div>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Cashups.close_employee'), 'close_employee', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-6">
                <?= form_dropdown('close_employee_id', esc($employees), $cash_ups_info->close_employee_id, array_merge(['id' => 'close_employee_id', 'class' => 'form-control'], $close_field_attrs)) ?>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Cashups.closed_amount_cash'), 'closed_amount_cash', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-4">
                <div class="input-group input-group-sm">
                    <?php if (!is_right_side_currency_symbol()): ?>
                        <span class="input-group-addon input-sm"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                    <?= form_input(array_merge([
                        'name'  => 'closed_amount_cash',
                        'id'    => 'closed_amount_cash',
                        'class' => 'form-control input-sm',
                        'value' => to_currency_no_money($cash_ups_info->closed_amount_cash)
                    ], $close_field_attrs)) ?>
                    <?php if (is_right_side_currency_symbol()): ?>
                        <span class="input-group-addon input-sm"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Cashups.note'), 'note', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-6">
                <?= form_checkbox(array_merge([
                    'name'    => 'note',
                    'id'      => 'note',
                    'value'   => 0,
                    'checked' => $cash_ups_info->note == 1
                ], $close_field_attrs)) ?>
            </div>
        </div>

        <?= form_input([
            'type'  => 'hidden',
            'name'  => 'closed_amount_due',
            'id'    => 'closed_amount_due',
            'value' => to_currency_no_money($cash_ups_info->closed_amount_due)
        ]) ?>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Cashups.closed_amount_card'), 'closed_amount_card', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-4">
                <div class="input-group input-group-sm">
                    <?php if (!is_right_side_currency_symbol()): ?>
                        <span class="input-group-addon input-sm"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                    <?= form_input(array_merge([
                        'name'  => 'closed_amount_card',
                        'id'    => 'closed_amount_card',
                        'class' => 'form-control input-sm',
                        'value' => to_currency_no_money($cash_ups_info->closed_amount_card)
                    ], $close_field_attrs)) ?>
                    <?php if (is_right_side_currency_symbol()): ?>
                        <span class="input-group-addon input-sm"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Cashups.closed_amount_check'), 'closed_amount_check', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-4">
                <div class="input-group input-group-sm">
                    <?php if (!is_right_side_currency_symbol()): ?>
                        <span class="input-group-addon input-sm"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                    <?= form_input(array_merge([
                        'name'  => 'closed_amount_check',
                        'id'    => 'closed_amount_check',
                        'class' => 'form-control input-sm',
                        'value' => to_currency_no_money($cash_ups_info->closed_amount_check)
                    ], $close_field_attrs)) ?>
                    <?php if (is_right_side_currency_symbol()): ?>
                        <span class="input-group-addon input-sm"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Cashups.closed_amount_total'), 'closed_amount_total', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-4">
                <div class="input-group input-group-sm">
                    <?php if (!is_right_side_currency_symbol()): ?>
                        <span class="input-group-addon input-sm"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                    <?= form_input([
                        'name'     => 'closed_amount_total',
                        'id'       => 'closed_amount_total',
                        'readonly' => 'true',
                        'class'    => 'form-control input-sm',
                        'value'    => to_currency_no_money($cash_ups_info->closed_amount_total)
                    ]) ?>
                    <?php if (is_right_side_currency_symbol()): ?>
                        <span class="input-group-addon input-sm"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($reconciliation !== null): ?>
        <div class="form-group form-group-sm">
            <?= form_label(lang('Cashups.reconciliation'), 'reconciliation', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-6">
                <table class="table table-condensed" style="margin-bottom: 0;">
                    <tbody>
                        <tr>
                            <td><?= lang('Cashups.reconciliation_expected') ?></td>
                            <td style="text-align: right;"><b id="reconciliation_expected"
                                data-expected="<?= esc((string)$reconciliation['expected'], 'attr') ?>"><?= to_currency($reconciliation['expected']) ?></b></td>
                        </tr>
                        <tr>
                            <td><?= lang('Cashups.reconciliation_counted') ?></td>
                            <td style="text-align: right;" id="reconciliation_counted"><?= to_currency($reconciliation['counted']) ?></td>
                        </tr>
                        <tr>
                            <td><b><?= lang('Cashups.reconciliation_discrepancy') ?></b></td>
                            <td style="text-align: right;"><b id="reconciliation_discrepancy"><?= to_currency($reconciliation['discrepancy']) ?></b></td>
                        </tr>
                        <tr>
                            <td colspan="2" style="text-align: right; border-top: 0; padding-top: 0;">
                                <a href="#cashup_reconciliation" data-toggle="tab" class="small"><?= lang('Cashups.reconciliation_detail') ?> &rarr;</a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Cashups.description'), 'description', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-6">
                <?= form_textarea(array_merge([
                    'name'  => 'description',
                    'id'    => 'description',
                    'class' => 'form-control input-sm',
                    'value' => $cash_ups_info->description
                ], $close_field_attrs)) ?>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Cashups.is_deleted') . ':', 'deleted', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-5">
                <?= form_checkbox([
                    'name'    => 'deleted',
                    'id'      => 'deleted',
                    'value'   => 1,
                    'checked' => $cash_ups_info->deleted == 1
                ]) ?>
            </div>
        </div>

        <?php endif; ?>
    </fieldset>
<?= form_close() ?>
<?php if (!$is_new): ?>
    </div><!-- /#cashup_shift -->

<?php
// This pane sits outside the cash-up form on purpose: a form cannot be nested in another, and a
// delivery is saved the moment it happens rather than waiting for the shift to be closed.
//
// The form comes before the list because recording is why someone opens this tab; reading what was
// already recorded is the secondary job.
?>
    <div class="tab-pane fade" id="cashup_collections" role="tabpanel">
<div class="form-horizontal" id="cash_collections_block">
    <fieldset>

        <?php if ($is_open): ?>
        <div class="form-group form-group-sm">
            <?= form_label(lang('Cashups.collection_amount'), 'collection_amount', ['class' => 'required control-label col-xs-3']) ?>
            <div class="col-xs-4">
                <?= form_input(['name' => 'collection_amount', 'id' => 'collection_amount', 'class' => 'form-control input-sm']) ?>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Cashups.collection_collected_at'), 'collection_collected_at', ['class' => 'required control-label col-xs-3']) ?>
            <div class="col-xs-6">
                <div class="input-group">
                    <?= form_input([
                        'name'  => 'collection_collected_at',
                        'id'    => 'collection_collected_at',
                        'class' => 'form-control input-sm',
                        'value' => date($config['dateformat'] . ' ' . $config['timeformat'])
                    ]) ?>
                    <span class="input-group-addon input-sm"><span class="glyphicon glyphicon-calendar"></span></span>
                </div>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Cashups.collection_collected_by'), 'collection_collected_by', ['class' => 'required control-label col-xs-3']) ?>
            <div class="col-xs-6">
                <?php // form_dropdown() escapes option values but not option labels, so the names must be escaped here. ?>
                <?= form_dropdown('collection_collected_by', esc(['' => ''] + $collectors), '', ['id' => 'collection_collected_by', 'class' => 'form-control']) ?>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Cashups.collection_note'), 'collection_note', ['class' => 'control-label col-xs-3']) ?>
            <div class="col-xs-6">
                <?= form_input(['name' => 'collection_note', 'id' => 'collection_note', 'class' => 'form-control input-sm']) ?>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <div class="col-xs-offset-3 col-xs-6">
                <button type="button" class="btn btn-default btn-sm" id="add_collection">
                    <?= lang('Cashups.collection_add') ?>
                </button>
            </div>
        </div>
        <?php endif; ?>

        <h4 style="margin-top: 0;"><?= lang('Cashups.collection_history') ?></h4>
        <table class="table table-condensed" id="cash_collections_table">
            <thead>
                <tr>
                    <th><?= lang('Cashups.collection_collected_at') ?></th>
                    <th><?= lang('Cashups.collection_collected_by') ?></th>
                    <th style="text-align: right;"><?= lang('Cashups.collection_amount') ?></th>
                    <th><?= lang('Cashups.collection_note') ?></th>
                    <?php if ($is_open): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($collections === []): ?>
                    <tr><td colspan="5"><em><?= lang('Cashups.collection_none') ?></em></td></tr>
                <?php else: ?>
                    <?php foreach ($collections as $collection): ?>
                        <tr>
                            <td><?= esc($collection['collected_at']) ?></td>
                            <td><?= esc($collection['collected_by_first_name'] . ' ' . $collection['collected_by_last_name']) ?></td>
                            <td style="text-align: right;"><?= to_currency($collection['amount']) ?></td>
                            <td><?= esc($collection['note']) ?></td>
                            <?php if ($is_open): ?>
                            <td>
                                <a href="javascript:void(0)" class="delete_collection" title="<?= lang('Cashups.collection_delete') ?>"
                                   data-collection-id="<?= esc((string)$collection['collection_id'], 'attr') ?>">
                                    <span class="glyphicon glyphicon-trash"></span>
                                    <span class="sr-only"><?= lang('Cashups.collection_delete') ?></span>
                                </a>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

    </fieldset>
</div>
    </div><!-- /#cashup_collections -->

    <?php if ($reconciliation !== null): ?>
    <div class="tab-pane fade" id="cashup_reconciliation" role="tabpanel">
        <table class="table table-condensed" id="reconciliation_block">
            <tbody>

                        <?php if (!$reconciliation['sealed_sales']): ?>
                            <tr>
                                <td colspan="2"><em><?= lang('Cashups.reconciliation_unsealed') ?></em></td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td><?= lang('Cashups.reconciliation_income') ?></td>
                                <td style="text-align: right;"><?= to_currency($reconciliation['income_total']) ?></td>
                            </tr>
                            <?php foreach ($reconciliation['income'] as $row): ?>
                                <tr>
                                    <td style="padding-left: 2em;"><?= esc($row['payment_type']) ?></td>
                                    <td style="text-align: right;"><?= to_currency($row['trans_amount']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <tr>
                            <td><?= lang('Cashups.reconciliation_open') ?></td>
                            <td style="text-align: right;"><?= to_currency($reconciliation['open_amount_cash']) ?></td>
                        </tr>
                        <tr>
                            <td><?= lang('Cashups.reconciliation_expenses') ?></td>
                            <td style="text-align: right;">&minus;<span id="detail_reconciliation_register_expenses"><?= to_currency($reconciliation['register_expenses']) ?></span></td>
                        </tr>
                        <tr>
                            <td><?= lang('Cashups.reconciliation_collections') ?></td>
                            <td style="text-align: right;">&minus;<span id="detail_reconciliation_collections"><?= to_currency($reconciliation['collections']) ?></span></td>
                        </tr>
                        <tr style="border-top: 2px solid #ddd;">
                            <td><b><?= lang('Cashups.reconciliation_expected') ?></b></td>
                            <td style="text-align: right;"><b id="detail_reconciliation_expected"
                               ><?= to_currency($reconciliation['expected']) ?></b></td>
                        </tr>
                        <tr>
                            <td><?= lang('Cashups.reconciliation_counted') ?></td>
                            <td style="text-align: right;" id="detail_reconciliation_counted"><?= to_currency($reconciliation['counted']) ?></td>
                        </tr>
                        <tr>
                            <td><b><?= lang('Cashups.reconciliation_discrepancy') ?></b></td>
                            <td style="text-align: right;"><b id="detail_reconciliation_discrepancy"><?= to_currency($reconciliation['discrepancy']) ?></b></td>
                        </tr>
                                </tbody>
        </table>
    </div><!-- /#cashup_reconciliation -->
    <?php endif; ?>
</div><!-- /.tab-content -->
<?php endif; ?>

<script type="text/javascript">
    // Validation and submit handling
    $(document).ready(function() {
        <?= view('partial/datepicker_locale') ?>

        $('#open_date').datetimepicker({
            format: "<?= dateformat_bootstrap($config['dateformat']) . ' ' . dateformat_bootstrap($config['timeformat']) ?>",
            startDate: "<?= date($config['dateformat'] . ' ' . esc($config['timeformat'], 'js'), mktime(0, 0, 0, 1, 1, 2010)) ?>",
            <?php
            $t = $config['timeformat'];
            $m = $t[strlen($t) - 1];
            if (str_contains($config['timeformat'], 'a') || str_contains($config['timeformat'], 'A')) {
            ?>
                showMeridian: true,
            <?php } else { ?>
                showMeridian: false,
            <?php } ?>
            minuteStep: 1,
            autoclose: true,
            todayBtn: true,
            todayHighlight: true,
            bootcssVer: 3,
            language: '<?= current_language_code() ?>'
        });

        <?php if (!$is_new): ?>
        $('#close_date').datetimepicker({
            format: "<?= dateformat_bootstrap($config['dateformat']) . ' ' . dateformat_bootstrap($config['timeformat']) ?>",
            startDate: "<?= date($config['dateformat'] . ' ' . esc($config['timeformat'], 'js'), mktime(0, 0, 0, 1, 1, 2010)) ?>",
            <?php
            $t = $config['timeformat'];
            $m = $t[strlen($t) - 1];
            if (str_contains($config['timeformat'], 'a') || str_contains($config['timeformat'], 'A')) {
            ?>
                showMeridian: true,
            <?php } else { ?>
                showMeridian: false,
            <?php } ?>
            minuteStep: 1,
            autoclose: true,
            todayBtn: true,
            todayHighlight: true,
            bootcssVer: 3,
            language: '<?= current_language_code() ?>'
        });
        <?php endif; ?>

        <?php if (!$is_new && !$is_closed): ?>
        var cashup_total_request = null;
        var cashup_total_timer = null;

        $('#open_amount_cash, #closed_amount_cash, #closed_amount_card, #closed_amount_check').keyup(function() {
            // Debounce, and abort any still-in-flight request before starting a new
            // one -- otherwise an earlier keystroke's response can arrive after a
            // later one's and silently overwrite the (readonly) total with a stale
            // value.
            clearTimeout(cashup_total_timer);

            cashup_total_timer = setTimeout(function() {
                if (cashup_total_request != null) {
                    cashup_total_request.abort();
                }

                cashup_total_request = $.post("<?= esc("$controller_name/ajax_cashup_total") ?>", {
                        'cashup_id': <?= json_encode((int)$cash_ups_info->cashup_id) ?>,
                        'open_amount_cash': $('#open_amount_cash').val(),
                        'transfer_amount_cash': 0,
                        'closed_amount_due': $('#closed_amount_due').val(),
                        'closed_amount_cash': $('#closed_amount_cash').val(),
                        'closed_amount_card': $('#closed_amount_card').val(),
                        'closed_amount_check': $('#closed_amount_check').val()
                    },
                    function(response) {
                        $('#closed_amount_total').val(response.total);

                        if (typeof response.discrepancy !== 'undefined') {
                            // The summary on the Shift tab and the breakdown on its own tab show
                            // the same two numbers, so they are written together. Two places
                            // showing different figures for the same drawer is worse than either.
                            $('#reconciliation_counted, #detail_reconciliation_counted').text(response.counted);
                            $('#reconciliation_discrepancy, #detail_reconciliation_discrepancy')
                                .text(response.discrepancy)
                                .css('color', response.balanced ? '' : '#a94442');
                        }
                    },
                    'json'
                );
            }, 300);
        });
        <?php endif; ?>

        <?php if (!$is_new): ?>
        $('#collection_collected_at').datetimepicker({
            format: "<?= dateformat_bootstrap($config['dateformat']) . ' ' . dateformat_bootstrap($config['timeformat']) ?>",
            startDate: "<?= date($config['dateformat'] . ' ' . esc($config['timeformat'], 'js'), mktime(0, 0, 0, 1, 1, 2010)) ?>",
            <?php if (str_contains($config['timeformat'], 'a') || str_contains($config['timeformat'], 'A')): ?>
                showMeridian: true,
            <?php else: ?>
                showMeridian: false,
            <?php endif; ?>
            minuteStep: 1,
            autoclose: true,
            todayBtn: true,
            todayHighlight: true,
            bootcssVer: 3,
            language: '<?= current_language_code() ?>'
        });

        // Cells are filled with .text(), never .html(): the note is free text a person typed, and
        // building a row out of it as markup is how a note becomes a script tag.
        var render_collections = function(collections) {
            var $body = $('#cash_collections_table tbody').empty();

            if (!collections.length) {
                $body.append($('<tr></tr>').append(
                    $('<td colspan="5"></td>').append($('<em></em>').text(<?= json_encode(lang('Cashups.collection_none')) ?>))
                ));
                return;
            }

            $.each(collections, function(index, collection) {
                var $row = $('<tr></tr>');
                $row.append($('<td></td>').text(collection.collected_at));
                $row.append($('<td></td>').text(collection.collected_by));
                $row.append($('<td style="text-align: right;"></td>').text(collection.amount));
                $row.append($('<td></td>').text(collection.note));
                <?php if ($is_open): ?>
                $row.append($('<td></td>').append(
                    $('<a href="javascript:void(0)" class="delete_collection"></a>')
                        .attr('data-collection-id', collection.collection_id)
                        .append($('<span class="glyphicon glyphicon-trash"></span>'))
                ));
                <?php endif; ?>
                $body.append($row);
            });
        };

        var refresh_collections = function() {
            $.get('<?= esc("$controller_name/collections/" . $cash_ups_info->cashup_id) ?>', function(response) {
                if (!response.success) {
                    return;
                }

                render_collections(response.collections);

                if (response.reconciliation !== null) {
                    $('#reconciliation_expected, #detail_reconciliation_expected')
                        .text(response.reconciliation.expected);
                    $('#reconciliation_expected').data('expected', response.reconciliation.expected_raw);
                    $('#detail_reconciliation_register_expenses').text(response.reconciliation.register_expenses);
                    $('#detail_reconciliation_collections').text(response.reconciliation.collections);
                    update_tab_badge(response.collections.length);
                    update_discrepancy();
                }
            }, 'json');
        };
        <?php endif; ?>

        // The counted and difference rows are filled in by the same debounced request that keeps
        // the total up to date -- see the keyup handler above, which posts the cash-up id so the
        // server can work the difference out. Amounts reach the server formatted for the operator's
        // locale and parse_decimals() is the only thing that reads them correctly, so none of this
        // arithmetic is done here.
        var update_discrepancy = function() {
            $('#closed_amount_cash').trigger('keyup');
        };

        <?php if (!$is_new && $is_open): ?>
        $('#add_collection').on('click', function() {
            var amount = $.trim($('#collection_amount').val());
            var collected_by = $('#collection_collected_by').val();

            // Checked here as well as on the server. The server is the one that decides, but the
            // dialog closes on any response at all, so letting a refusal get that far would take
            // the typed values with it.
            if (amount === '' || !collected_by) {
                $.notify(<?= json_encode(lang('Cashups.collection_error')) ?>, {className: 'error'});
                return;
            }

            $.post('<?= esc("$controller_name/saveCollection") ?>', {
                'amount': amount,
                'collected_at': $('#collection_collected_at').val(),
                'collected_by': collected_by,
                'note': $('#collection_note').val()
            }, function(response) {
                $.notify(response.message, {className: response.success ? 'success' : 'error'});

                if (response.success) {
                    $('#collection_amount').val('');
                    $('#collection_note').val('');
                    $('#collection_collected_by').val('');
                    refresh_collections();
                }
            }, 'json');
        });

        $('#cash_collections_table').on('click', '.delete_collection', function() {
            if (!confirm(<?= json_encode(lang('Cashups.collection_confirm_delete')) ?>)) {
                return;
            }

            $.post('<?= esc("$controller_name/deleteCollection") ?>/' + $(this).data('collection-id'), {}, function(response) {
                $.notify(response.message, {className: response.success ? 'success' : 'error'});

                if (response.success) {
                    refresh_collections();
                }
            }, 'json');
        });
        <?php endif; ?>

        <?php if (!$is_new): ?>
        // The dialog's footer button always submits the shift form, so leaving it visible on the
        // other tabs invites a cashier who came in to record a delivery to close the shift by
        // accident. It belongs to the Shift tab and only shows there.
        var sync_footer_button = function() {
            var on_shift_tab = $('#cashup_tabs li.active a').attr('href') === '#cashup_shift';
            $('.bootstrap-dialog-footer #submit, .bootstrap-dialog-footer button[id="submit"]').toggle(on_shift_tab);
        };

        var update_tab_badge = function(count) {
            var $link = $('#cashup_tabs a[href="#cashup_collections"]');
            var $badge = $link.find('.badge');
            if (!count) { $badge.remove(); return; }
            if (!$badge.length) { $link.append(' <span class="badge"></span>'); $badge = $link.find('.badge'); }
            $badge.text(count);
        };

        $('#cashup_tabs a[data-toggle="tab"], a[href="#cashup_reconciliation"][data-toggle="tab"]').on('shown.bs.tab', function() {
            sync_footer_button();
            $('#cashup_tabs a[role="tab"]').attr('aria-selected', 'false');
            $('#cashup_tabs li.active a[role="tab"]').attr('aria-selected', 'true');
        });

        // The footer is built by BootstrapDialog after this script runs, so the first sync waits a
        // tick rather than racing it.
        setTimeout(sync_footer_button, 0);

        // Opened straight from the grid's hand-over-cash action: land on the tab that action means.
        <?php if (($active_tab ?? '') === 'collections'): ?>
        $('#cashup_tabs a[href="#cashup_collections"]').tab('show');
        <?php endif; ?>
        <?php else: ?>
        var update_tab_badge = function() {};
        <?php endif; ?>

        var submit_form = function() {
            $(this).ajaxSubmit({
                success: function(response) {
                    dialog_support.hide();
                    table_support.handle_submit('<?= esc('cashups') ?>', response);
                },
                dataType: 'json'
            });
        };

        $('#cashups_edit_form').validate($.extend({
            submitHandler: function(form) {
                submit_form.call(form);
            },
            rules: {

            },
            messages: {
                open_date: {
                    required: '<?= lang('Cashups.date_required') ?>'

                },
                close_date: {
                    required: '<?= lang('Cashups.date_required') ?>'

                },
                amount: {
                    required: '<?= lang('Cashups.amount_required') ?>',
                    number: '<?= lang('Cashups.amount_number') ?>'
                }
            }
        }, form_support.error));
    });
</script>
