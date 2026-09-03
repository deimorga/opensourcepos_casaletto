<?php
/**
 * Scale configuration.
 *
 * Sister screen of barcode_config.php, and deliberately built the same way: what changes between
 * one scale and the next is a pattern in a text box, not a release.
 *
 * Every scale_* setting is read with ?? on purpose. The configuration screens are shared by every
 * tenant in the platform, including the ones that were configured long before these keys existed
 * and the ones running against a settings cache that predates the migration. A direct
 * $config['scale_format'] would take their configuration screen down with it.
 *
 * See docs/Tecnico/venta-por-peso-y-hardware-de-caja.md sections 4.3, 5.4 and 5.10.
 *
 * @var array $config
 */
?>

<div id="config_wrapper">
<?= form_open('config/saveScale/', ['id' => 'scale_config_form', 'class' => 'form-horizontal']) ?>
        <fieldset id="config_info">

            <div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
            <ul id="scale_error_message_box" class="error_message_box"></ul>

            <div class="form-group form-group-sm">
                <?= form_label(lang('Config.scale_transport'), 'scale_transport', ['class' => 'control-label col-xs-2']) ?>
                <div class="col-xs-3">
                    <?= form_dropdown(
                        'scale_transport',
                        [
                            'keys'  => lang('Config.scale_transport_keys'),
                            'agent' => lang('Config.scale_transport_agent')
                        ],
                        $config['scale_transport'] ?? 'keys',
                        'class="form-control input-sm" id="scale_transport"'
                    ) ?>
                </div>
                <div class="col-xs-6">
                    <span class="help-block"><?= lang('Config.scale_transport_help') ?></span>
                </div>
            </div>

            <div class="form-group form-group-sm">
                <?= form_label(lang('Config.scale_format'), 'scale_format', ['class' => 'control-label col-xs-2']) ?>
                <div class="col-xs-3">
                    <?= form_input([
                        'type'        => 'text',
                        'name'        => 'scale_format',
                        'id'          => 'scale_format',
                        'class'       => 'form-control input-sm',
                        'maxlength'   => '255',
                        'placeholder' => 'N{W:6}',
                        'value'       => $config['scale_format'] ?? ''
                    ]) ?>
                </div>
                <div class="col-xs-6">
                    <span class="help-block"><?= lang('Config.scale_format_help') ?></span>
                </div>
            </div>

            <div class="form-group form-group-sm">
                <?= form_label(lang('Config.scale_divisor'), 'scale_divisor', ['class' => 'control-label col-xs-2 required']) ?>
                <div class="col-xs-3">
                    <?= form_input([
                        'type'  => 'number',
                        'min'   => '1',
                        'step'  => '1',
                        'name'  => 'scale_divisor',
                        'id'    => 'scale_divisor',
                        'class' => 'form-control input-sm required',
                        'value' => $config['scale_divisor'] ?? 1
                    ]) ?>
                </div>
                <div class="col-xs-6">
                    <span class="help-block"><?= lang('Config.scale_divisor_help') ?></span>
                </div>
            </div>

            <?= form_submit([
                'name'  => 'submit_scale',
                'id'    => 'submit_scale',
                'value' => lang('Common.submit'),
                'class' => 'btn btn-primary btn-sm pull-right'
            ]) ?>

        </fieldset>
<?= form_close() ?>

    <fieldset id="scale_test_info" class="text-left form-horizontal">
        <legend><?= lang('Config.scale_test') ?></legend>

        <div class="form-group form-group-sm">
            <?= form_label(lang('Config.scale_raw'), 'scale_raw', ['class' => 'control-label col-xs-2']) ?>
            <div class="col-xs-4">
                <?= form_textarea([
                    'name'  => 'scale_raw',
                    'id'    => 'scale_raw',
                    'class' => 'form-control input-sm',
                    'rows'  => '3',
                    'value' => ''
                ]) ?>
            </div>
            <div class="col-xs-5">
                <span class="help-block"><?= lang('Config.scale_raw_help') ?></span>
            </div>
        </div>

        <div class="form-group form-group-sm">
            <label class="control-label col-xs-2"><?= lang('Config.scale_preview_weight') ?></label>
            <div class="col-xs-4">
                <p class="form-control-static">
                    <strong id="scale_preview_weight">&mdash;</strong>
                    <span id="scale_preview_message" class="text-muted"></span>
                </p>
            </div>
        </div>

        <div class="form-group form-group-sm" id="scale_suggestion_row" style="display: none;">
            <label class="control-label col-xs-2"><?= lang('Config.scale_suggestion') ?></label>
            <div class="col-xs-4">
                <p class="form-control-static">
                    <code id="scale_suggested_format"></code>
                    <span class="text-muted">&nbsp;/&nbsp;</span>
                    <code id="scale_suggested_divisor"></code>
                </p>
            </div>
            <div class="col-xs-2">
                <button type="button" id="scale_apply_suggestion" class="btn btn-default btn-sm">
                    <?= lang('Config.scale_apply_suggestion') ?>
                </button>
            </div>
            <div class="col-xs-4">
                <span class="help-block"><?= lang('Config.scale_suggestion_help') ?></span>
            </div>
        </div>

    </fieldset>
</div>

<script type="text/javascript">
    $(document).ready(function() {

        // The preview asks the server, never the browser. The interpreter that answers here is the
        // same Token_lib::parse_scale() the register will call on the same bytes, so what the
        // technician sees is what the till will do -- a JavaScript reimplementation would only be
        // able to promise that.
        var preview_timer = null;

        var render_suggestion = function(response) {
            var format = response.suggested_format;

            if (!format || format === $('#scale_format').val()) {
                $('#scale_suggestion_row').hide();
                return;
            }

            // .text(), never .html(): this string is built out of bytes a scale emitted.
            $('#scale_suggested_format').text(format);
            $('#scale_suggested_divisor').text(response.suggested_divisor);
            $('#scale_suggestion_row').show();
        };

        var run_preview = function() {
            if ($('#scale_raw').val().trim() === '') {
                $('#scale_preview_weight').text('—');
                $('#scale_preview_message').text('');
                $('#scale_suggestion_row').hide();
                return;
            }

            $.post(
                "<?= site_url('config/scalePreview') ?>",
                {
                    scale_raw: $('#scale_raw').val(),
                    scale_format: $('#scale_format').val(),
                    scale_divisor: $('#scale_divisor').val()
                },
                function(response) {
                    $('#scale_preview_weight').text(response.success ? response.weight : '—');
                    $('#scale_preview_message').text(response.message);
                    render_suggestion(response);
                },
                'json'
            );
        };

        var schedule_preview = function() {
            clearTimeout(preview_timer);
            preview_timer = setTimeout(run_preview, 350);
        };

        $('#scale_raw, #scale_format, #scale_divisor').on('input change', schedule_preview);

        $('#scale_apply_suggestion').click(function() {
            $('#scale_format').val($('#scale_suggested_format').text());
            $('#scale_divisor').val($('#scale_suggested_divisor').text());
            run_preview();
        });

        $('#scale_config_form').validate($.extend(form_support.handler, {

            errorLabelContainer: "#scale_error_message_box",

            rules: {
                scale_divisor: {
                    required: true,
                    number: true,
                    min: 1
                }
            },

            messages: {
                scale_divisor: {
                    required: "<?= lang('Config.scale_divisor_invalid') ?>",
                    number: "<?= lang('Config.scale_divisor_invalid') ?>",
                    min: "<?= lang('Config.scale_divisor_invalid') ?>"
                }
            }
        }));
    });
</script>
