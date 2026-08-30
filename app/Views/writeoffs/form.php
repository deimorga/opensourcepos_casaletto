<?php
/**
 * Record one write-off: item, location, quantity, reason, comment.
 *
 * @var array       $stock_locations location_id => name
 * @var array       $reasons         reason code => translated label
 * @var array       $submitted       what was posted, when the form is being re-shown after a refusal
 * @var string|null $error
 * @var string|null $success
 */
?>

<?= view('partial/header') ?>

<div id="page_title"><?= lang('Writeoffs.new') ?></div>

<?php if (!empty($success)) { ?>
    <div class="alert alert-dismissible alert-success"><?= esc($success) ?></div>
<?php } ?>

<?php if (!empty($error)) { ?>
    <div class="alert alert-dismissible alert-danger"><?= esc($error) ?></div>
<?php } ?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>

<?= form_open('writeoffs/save', ['id' => 'writeoff_form', 'class' => 'form-horizontal']) ?>

    <?php
    // The picker writes the id here and the name into the visible field. Posting the id and not the
    // typed text is what stops "Queso" from matching three different products.
    ?>
    <?= form_hidden('item_id', (string) ($submitted['item_id'] ?? '')) ?>

    <div class="form-group form-group-sm">
        <?= form_label(lang('Writeoffs.item'), 'item_name', ['class' => 'required control-label col-xs-2']) ?>
        <div class="col-xs-4">
            <?= form_input([
                'name'         => 'item_name',
                'id'           => 'item_name',
                'class'        => 'form-control input-sm',
                'autocomplete' => 'off',
                'value'        => $submitted['item_name'] ?? ''
            ]) ?>
        </div>
    </div>

    <?php if (count($stock_locations) > 1) { ?>
        <div class="form-group form-group-sm">
            <?= form_label(lang('Writeoffs.stock_location'), 'stock_location', ['class' => 'required control-label col-xs-2']) ?>
            <div class="col-xs-4">
                <?= form_dropdown('stock_location', $stock_locations, (string) ($submitted['location_id'] ?? ''), ['id' => 'stock_location', 'class' => 'form-control input-sm']) ?>
            </div>
        </div>
    <?php } else { ?>
        <?= form_hidden('stock_location', (string) (array_key_first($stock_locations) ?? '')) ?>
    <?php } ?>

    <div class="form-group form-group-sm">
        <?= form_label(lang('Writeoffs.quantity'), 'quantity', ['class' => 'required control-label col-xs-2']) ?>
        <div class="col-xs-2">
            <?php
            // type="text" and not type="number": a browser number field applies the *browser's*
            // locale to what is typed, and this value is read server-side by
            // Sale_lib::normalize_weight_input(), which accepts a dot or a comma and nothing else.
            // inputmode gives a phone or tablet the numeric keypad without handing the value over.
            ?>
            <?= form_input([
                'name'         => 'quantity',
                'id'           => 'quantity',
                'class'        => 'form-control input-sm',
                'inputmode'    => 'decimal',
                'autocomplete' => 'off',
                'value'        => $submitted['quantity'] ?? ''
            ]) ?>
        </div>
        <div class="col-xs-4">
            <span class="help-block" style="margin-top: 6px;"><?= lang('Writeoffs.quantity_invalid') ?></span>
        </div>
    </div>

    <div class="form-group form-group-sm">
        <?= form_label(lang('Writeoffs.reason'), 'reason_code', ['class' => 'required control-label col-xs-2']) ?>
        <div class="col-xs-4">
            <?= form_dropdown('reason_code', $reasons, $submitted['reason_code'] ?? '', ['id' => 'reason_code', 'class' => 'form-control input-sm']) ?>
        </div>
    </div>

    <div class="form-group form-group-sm">
        <?= form_label(lang('Writeoffs.comment'), 'comment', ['class' => 'control-label col-xs-2']) ?>
        <div class="col-xs-6">
            <?= form_textarea([
                'name'  => 'comment',
                'id'    => 'comment',
                'class' => 'form-control input-sm',
                'rows'  => 2,
                'value' => $submitted['comment'] ?? ''
            ]) ?>
        </div>
    </div>

    <div class="form-group form-group-sm">
        <div class="col-xs-offset-2 col-xs-6">
            <?= form_submit([
                'name'  => 'submit_writeoff',
                'id'    => 'submit_writeoff',
                'value' => lang('Writeoffs.submit'),
                'class' => 'btn btn-primary btn-sm'
            ]) ?>
            <a class="btn btn-default btn-sm" href="<?= site_url('writeoffs/report') ?>"><?= lang('Writeoffs.report') ?></a>
        </div>
    </div>

<?= form_close() ?>

<?= view('partial/footer') ?>

<script type="text/javascript">
    $(document).ready(function() {
        var fill_item = function(event, ui) {
            event.preventDefault();
            $("input[name='item_id']").val(ui.item.value);
            $("input[name='item_name']").val(DOMPurify.sanitize(ui.item.label));
        };

        $('#item_name').autocomplete({
            // This module's own endpoint, not items/suggest: that one is behind the `items`
            // permission and would 302 an employee who may record spoilage but not edit products.
            source: '<?= site_url('writeoffs/suggest') ?>',
            minChars: 0,
            delay: 15,
            autoFocus: false,
            select: fill_item,
            focus: fill_item
        });

        // Clearing the visible name has to clear the id with it, otherwise the form still posts
        // the product that was picked before it was erased.
        $("#item_name").on('change keyup', function() {
            if (!$(this).val()) {
                $("input[name='item_id']").val('');
            }
        });
    });
</script>
