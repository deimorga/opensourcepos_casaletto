<?php
/**
 * What was written off, by item and by reason, with the cost.
 *
 * Built on bootstrapTable exactly like reports/tabular so the export buttons, the column picker and
 * the paging behave the way every other report in this application does. It is a view of its own
 * rather than a call into reports/tabular because that one renders its summary block through
 * lang("Reports.$key"), and this module's wording lives in its own language file.
 *
 * @var string $title
 * @var string $subtitle
 * @var array  $headers
 * @var array  $data
 * @var array  $by_reason      one row per reason: reason, quantity, write_off_cost
 * @var string $total_cost
 * @var string $total_quantity
 * @var array  $config
 */
?>

<?= view('partial/header') ?>

<div id="page_title"><?= esc($title) ?></div>

<div id="page_subtitle"><?= esc($subtitle) ?></div>

<div id="table_holder">
    <table id="table"></table>
</div>

<div id="report_summary">
    <?php if (empty($data)) { ?>
        <div class="summary_row"><?= lang('Writeoffs.no_write_offs') ?></div>
    <?php } else { ?>
        <?php foreach ($by_reason as $row) { ?>
            <div class="summary_row"><?= esc($row['reason']) . ': ' . esc($row['write_off_cost']) . ' (' . esc($row['quantity']) . ')' ?></div>
        <?php } ?>
        <div class="summary_row"><strong><?= lang('Writeoffs.total_cost') . ': ' . esc($total_cost) ?></strong></div>
        <div class="summary_row"><?= lang('Writeoffs.total_quantity') . ': ' . esc($total_quantity) ?></div>
    <?php } ?>
</div>

<?= view('partial/footer') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        $('#table')
            .addClass("table-striped")
            .addClass("table-bordered")
            .bootstrapTable({
                columns: <?= transform_headers(esc($headers), true, false) ?>,
                stickyHeader: true,
                pageSize: <?= $config['lines_per_page'] ?>,
                sortable: true,
                showExport: true,
                exportDataType: 'all',
                exportTypes: ['json', 'xml', 'csv', 'txt', 'excel', 'pdf'],
                pagination: true,
                showColumns: true,
                data: <?= json_encode($data) ?>,
                iconSize: 'sm',
                paginationVAlign: 'bottom',
                escape: true,
                search: true
            });
    });
</script>
