<?php
/**
 * Order tickets configuration.
 *
 * Two switches. The first turns the whole module on and needs Tables: an order ticket reaches the
 * register by opening a throwaway table in the tab bar, and that bar lives behind
 * dinner_table_enable. The server refuses the save otherwise; the help text says so up front so
 * nobody has to learn it by failing. The second switch is the kitchen screen, which only means
 * something while the first one is on.
 *
 * Every order_tickets_* setting is read with ?? '0' on purpose. The configuration screens are shared
 * by every tenant in the platform, including the ones running against a settings cache that
 * predates 20260923010000_AddOrderTicketsConfigKeys. A direct $config['order_tickets_enable'] would
 * take their whole configuration screen down with it.
 *
 * See docs/Tecnico/comandas-y-cuenta-abierta.md.
 *
 * @var array $config
 */
?>

<?= form_open('config/saveOrderTickets/', ['id' => 'order_tickets_config_form', 'class' => 'form-horizontal']) ?>
    <div id="config_wrapper">
        <fieldset id="config_info">

            <ul id="order_tickets_error_message_box" class="error_message_box"></ul>

            <div class="form-group form-group-sm">
                <?= form_label(lang('Config.order_tickets_enable'), 'order_tickets_enable', ['class' => 'control-label col-xs-2']) ?>
                <div class="col-xs-1">
                    <?= form_checkbox([
                        'name'    => 'order_tickets_enable',
                        'value'   => 'order_tickets_enable',
                        'id'      => 'order_tickets_enable',
                        'checked' => ($config['order_tickets_enable'] ?? '0') == '1'
                    ]) ?>
                </div>
                <div class="col-xs-6">
                    <span class="help-block"><?= esc(lang('Config.order_tickets_enable_help')) ?></span>
                </div>
            </div>

            <div class="form-group form-group-sm">
                <?= form_label(lang('Config.order_tickets_kitchen_enable'), 'order_tickets_kitchen_enable', ['class' => 'control-label col-xs-2']) ?>
                <div class="col-xs-1">
                    <?= form_checkbox([
                        'name'    => 'order_tickets_kitchen_enable',
                        'value'   => 'order_tickets_kitchen_enable',
                        'id'      => 'order_tickets_kitchen_enable',
                        'checked' => ($config['order_tickets_kitchen_enable'] ?? '0') == '1'
                    ]) ?>
                </div>
                <div class="col-xs-6">
                    <span class="help-block"><?= esc(lang('Config.order_tickets_kitchen_help')) ?></span>
                </div>
            </div>

            <?= form_submit([
                'name'  => 'submit_order_tickets',
                'id'    => 'submit_order_tickets',
                'value' => lang('Common.submit'),
                'class' => 'btn btn-primary btn-sm pull-right'
            ]) ?>

        </fieldset>
    </div>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {

        // The kitchen screen only means something while order tickets are on. Same pattern as
        // enable_disable_dinner_table_enable in table_config.php. The server forces it to '0'
        // anyway; this is only so the screen does not offer a choice that would be thrown away.
        var enable_disable_order_tickets_enable = (function() {
            var order_tickets_enable = $("#order_tickets_enable").is(":checked");
            $("#order_tickets_kitchen_enable").prop("disabled", !order_tickets_enable);
            return arguments.callee;
        })();

        $("#order_tickets_enable").change(enable_disable_order_tickets_enable);

        $('#order_tickets_config_form').validate($.extend(form_support.handler, {
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    beforeSerialize: function(arr, $form, options) {
                        // A disabled input is not serialized. Re-enable it so what is sent is what is
                        // on screen; the server decides what it is worth.
                        $("#order_tickets_kitchen_enable").prop("disabled", false);
                        return true;
                    },
                    success: function(response) {
                        $.notify({
                            message: response.message
                        }, {
                            type: response.success ? 'success' : 'danger'
                        });
                        enable_disable_order_tickets_enable();
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: "#order_tickets_error_message_box"
        }));
    });
</script>
