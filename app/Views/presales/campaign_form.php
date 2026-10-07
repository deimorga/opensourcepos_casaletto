<?php
/**
 * The create / edit form of a campaign, shown in the modal dialog of the list.
 *
 * Dates are typed in the business's format and read back in words under the field by
 * partial/datepicker_locale (D27); the server reads them with parse_typed_datetime().
 *
 * @var int        $campaign_id NEW_ENTRY (-1) for a new campaign
 * @var array|null $campaign    the presale_campaigns row, null when new
 * @var array      $config
 */
$is_new = $campaign === null;

$starts = $is_new ? '' : presale_campaign_date((string) $campaign['sale_starts']);
$ends   = $is_new ? '' : presale_campaign_date((string) $campaign['sale_ends']);
?>

<div id="required_fields_message"><?= esc(lang('Common.fields_required_message')) ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('presales/campaigns/save/' . (int) $campaign_id, ['id' => 'campaign_form', 'class' => 'form-horizontal']) ?>
    <fieldset>
        <div class="form-group form-group-sm">
            <?= form_label(lang('Presale_campaigns.name'), 'campaign_name', ['class' => 'required control-label col-xs-4']) ?>
            <div class="col-xs-7">
                <?= form_input([
                    'name'      => 'name',
                    'id'        => 'campaign_name',
                    'class'     => 'form-control input-sm',
                    'maxlength' => '100',
                    'value'     => $is_new ? '' : (string) $campaign['name'],
                ]) ?>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Presale_campaigns.sale_starts'), 'sale_starts', ['class' => 'required control-label col-xs-4']) ?>
            <div class="col-xs-5">
                <div class="input-group">
                    <span class="input-group-addon input-sm"><span class="glyphicon glyphicon-calendar"></span></span>
                    <?= form_input([
                        'name'         => 'sale_starts',
                        'id'           => 'sale_starts',
                        'class'        => 'form-control input-sm campaign-date',
                        'autocomplete' => 'off',
                        'value'        => $starts,
                    ]) ?>
                </div>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Presale_campaigns.sale_ends'), 'sale_ends', ['class' => 'required control-label col-xs-4']) ?>
            <div class="col-xs-5">
                <div class="input-group">
                    <span class="input-group-addon input-sm"><span class="glyphicon glyphicon-calendar"></span></span>
                    <?= form_input([
                        'name'         => 'sale_ends',
                        'id'           => 'sale_ends',
                        'class'        => 'form-control input-sm campaign-date',
                        'autocomplete' => 'off',
                        'value'        => $ends,
                    ]) ?>
                </div>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Presale_campaigns.discount_percent'), 'discount_percent', ['class' => 'control-label col-xs-4']) ?>
            <div class="col-xs-4">
                <div class="input-group input-group-sm">
                    <?= form_input([
                        'name'         => 'discount_percent',
                        'id'           => 'discount_percent',
                        'class'        => 'form-control input-sm',
                        'inputmode'    => 'decimal',
                        'autocomplete' => 'off',
                        'value'        => to_decimals($is_new ? '0' : (string) $campaign['discount_percent']),
                    ]) ?>
                    <span class="input-group-addon">%</span>
                </div>
                <span class="help-block"><?= esc(lang('Presale_campaigns.discount_percent_help')) ?></span>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Presale_campaigns.min_initial_percent'), 'min_initial_percent', ['class' => 'control-label col-xs-4']) ?>
            <div class="col-xs-4">
                <div class="input-group input-group-sm">
                    <?= form_input([
                        'name'         => 'min_initial_percent',
                        'id'           => 'min_initial_percent',
                        'class'        => 'form-control input-sm',
                        'inputmode'    => 'decimal',
                        'autocomplete' => 'off',
                        'value'        => to_decimals($is_new ? '0' : (string) $campaign['min_initial_percent']),
                    ]) ?>
                    <span class="input-group-addon">%</span>
                </div>
                <span class="help-block"><?= esc(lang('Presale_campaigns.min_initial_help')) ?></span>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Presale_campaigns.active'), 'campaign_active', ['class' => 'control-label col-xs-4']) ?>
            <div class="col-xs-7">
                <input type="checkbox" name="active" id="campaign_active" value="1"<?= $is_new || (int) $campaign['active'] === 1 ? ' checked' : '' ?>>
            </div>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/datepicker_locale') ?>

        // Date only: the shared partial's picker carries the time of day, and a campaign works in whole
        // days. The field gets the .datetime class AFTER the partial ran, so the partial's own picker
        // is not attached to it but its words-under-the-date readback (a delegate on that class) is.
        $('.campaign-date').datetimepicker({
            format: <?= json_encode(dateformat_bootstrap($config['dateformat'])) ?>,
            minView: 2,
            autoclose: true,
            todayBtn: true,
            todayHighlight: true,
            bootcssVer: 3,
            language: <?= json_encode(current_language_code()) ?>
        }).addClass('datetime').trigger('change');

        $('#campaign_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        if (response.success) {
                            dialog_support.hide();
                            table_support.handle_submit('presales/campaigns', response);
                        } else {
                            // Stay on the form: closing it on a refusal loses what was typed.
                            $.notify(response.message, {type: 'danger'});
                            $('#submit').prop('disabled', false).css('opacity', 1);
                        }
                    },
                    dataType: 'json'
                });
            },
            rules: {
                name: 'required',
                sale_starts: 'required',
                sale_ends: 'required'
            },
            messages: {
                name: <?= json_encode(lang('Presales.campaign_name_required')) ?>,
                sale_starts: <?= json_encode(lang('Presales.campaign_dates_invalid')) ?>,
                sale_ends: <?= json_encode(lang('Presales.campaign_dates_invalid')) ?>
            }
        }, form_support.error));
    });
</script>
