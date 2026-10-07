<?php
/**
 * Presales configuration: the switch, the number prefix and the conditions printed on every presale
 * document.
 *
 * Every presales_* setting is read with ?? default on purpose. The configuration screens are shared by
 * every tenant in the platform, including ones running against a settings cache that predates
 * 20261007010000_AddPresalesConfigKeys.
 *
 * The suggested conditions come from the language files (Presales.terms_template) and are only copied
 * into the field when somebody presses the button: what is printed is always what the business saved.
 *
 * See docs/Funcional/venta-anticipada.md §4.12 and §4.14.
 *
 * @var array $config
 * @var int   $presales_open
 */
$presales_enabled = ($config['presales_enable'] ?? '0') === '1';
?>

<?= form_open('config/savePresales/', ['id' => 'presales_config_form', 'class' => 'form-horizontal']) ?>
    <div id="config_wrapper">
        <fieldset id="config_info">

            <ul id="presales_error_message_box" class="error_message_box"></ul>

            <div class="form-group form-group-sm">
                <?= form_label(lang('Config.presales_enable'), 'presales_enable', ['class' => 'control-label col-xs-2']) ?>
                <div class="col-xs-1">
                    <?= form_checkbox([
                        'name'    => 'presales_enable',
                        'value'   => 'presales_enable',
                        'id'      => 'presales_enable',
                        'checked' => $presales_enabled,
                    ]) ?>
                </div>
                <div class="col-xs-6">
                    <span class="help-block"><?= esc(lang('Config.presales_enable_help')) ?></span>
                </div>
            </div>

            <div class="form-group form-group-sm">
                <?= form_label(lang('Config.presales_prefix'), 'presales_prefix', ['class' => 'control-label col-xs-2']) ?>
                <div class="col-xs-2">
                    <?= form_input([
                        'name'      => 'presales_prefix',
                        'id'        => 'presales_prefix',
                        'class'     => 'form-control input-sm',
                        'maxlength' => 10,
                        'value'     => $config['presales_prefix'] ?? 'PV-',
                    ]) ?>
                </div>
                <div class="col-xs-6">
                    <span class="help-block"><?= esc(lang('Config.presales_prefix_help')) ?></span>
                </div>
            </div>

            <div class="form-group form-group-sm">
                <?= form_label(lang('Config.presales_weight_refund_limit'), 'presales_weight_refund_limit', ['class' => 'control-label col-xs-2']) ?>
                <div class="col-xs-2">
                    <?= form_input([
                        'name'      => 'presales_weight_refund_limit',
                        'id'        => 'presales_weight_refund_limit',
                        'class'     => 'form-control input-sm',
                        'maxlength' => 6,
                        'value'     => to_tax_decimals($config['presales_weight_refund_limit'] ?? '15'),
                    ]) ?>
                </div>
                <div class="col-xs-6">
                    <span class="help-block"><?= esc(lang('Config.presales_weight_refund_limit_help')) ?></span>
                </div>
            </div>

            <div class="form-group form-group-sm">
                <?= form_label(lang('Config.presales_terms'), 'presales_terms', ['class' => 'control-label col-xs-2']) ?>
                <div class="col-xs-8">
                    <?= form_textarea([
                        'name'      => 'presales_terms',
                        'id'        => 'presales_terms',
                        'class'     => 'form-control input-sm',
                        'rows'      => 10,
                        'maxlength' => 4000,
                        'value'     => $config['presales_terms'] ?? '',
                    ]) ?>
                    <span class="help-block"><?= esc(lang('Config.presales_terms_help')) ?></span>
                    <button type="button" id="presales_use_suggested" class="btn btn-default btn-sm"><?= esc(lang('Config.presales_use_suggested')) ?></button>
                </div>
            </div>

            <?= form_submit([
                'name'  => 'submit_presales',
                'id'    => 'submit_presales',
                'value' => lang('Common.submit'),
                'class' => 'btn btn-primary btn-sm pull-right',
            ]) ?>

        </fieldset>
    </div>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {

        // json_encode and not a quoted string: the template has line breaks and quotes, and this is
        // the one way they reach JavaScript intact.
        var presales_suggested_terms = <?= json_encode(lang('Presales.terms_template'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var presales_open = <?= (int) ($presales_open ?? 0) ?>;
        var presales_was_enabled = <?= $presales_enabled ? 'true' : 'false' ?>;

        $('#presales_use_suggested').click(function() {
            $('#presales_terms').val(presales_suggested_terms);
        });

        $('#presales_config_form').validate($.extend(form_support.handler, {
            submitHandler: function(form) {
                // Switching off with presales still open leaves nobody able to take an instalment or
                // deliver. Allowed -- the administrator decides -- but never by accident.
                if (presales_was_enabled && presales_open > 0 && !$('#presales_enable').is(':checked')) {
                    var question = <?= json_encode(lang('Config.presales_confirm_disable'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

                    if (!window.confirm(question.replace('{0}', presales_open))) {
                        return false;
                    }
                }

                $(form).ajaxSubmit({
                    success: function(response) {
                        $.notify({
                            message: response.message
                        }, {
                            type: response.success ? 'success' : 'danger'
                        });

                        if (response.success) {
                            presales_was_enabled = $('#presales_enable').is(':checked');
                        }
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: "#presales_error_message_box"
        }));
    });
</script>
