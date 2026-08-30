<?php
/**
 * Date range (and location, when there is more than one) for the write-off report.
 *
 * @var array $stock_locations empty when the tenant has a single location
 * @var array $config
 */
?>

<?= view('partial/header') ?>

<div id="page_title"><?= lang('Writeoffs.report_input') ?></div>

<?= form_open('#', ['id' => 'writeoff_report_form', 'class' => 'form-horizontal']) ?>

    <div class="form-group form-group-sm">
        <?= form_label(lang('Reports.date_range'), 'report_date_range_label', ['class' => 'required control-label col-xs-2']) ?>
        <div class="col-xs-3">
            <?= form_input(['name' => 'daterangepicker', 'class' => 'form-control input-sm', 'id' => 'daterangepicker']) ?>
        </div>
    </div>

    <?php if (!empty($stock_locations)) { ?>
        <div class="form-group form-group-sm">
            <?= form_label(lang('Writeoffs.stock_location'), 'report_stock_location_label', ['class' => 'required control-label col-xs-2']) ?>
            <div class="col-xs-3">
                <?= form_dropdown('stock_location', $stock_locations, 'all', ['id' => 'location_id', 'class' => 'form-control input-sm']) ?>
            </div>
        </div>
    <?php } ?>

    <?= form_button([
        'name'    => 'generate_report',
        'id'      => 'generate_report',
        'content' => lang('Common.submit'),
        'class'   => 'btn btn-primary btn-sm'
    ]) ?>

<?= form_close() ?>

<?= view('partial/footer') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/daterangepicker') ?>

        $("#generate_report").click(function() {
            // encodeURIComponent, because with a tenant configured to show times the picker hands
            // back "2026-08-01 00:00:00" and the space would end the path segment.
            window.location = [
                "<?= site_url('writeoffs/report') ?>",
                encodeURIComponent(start_date),
                encodeURIComponent(end_date),
                $("#location_id").val() || 'all'
            ].join("/");
        });
    });
</script>
