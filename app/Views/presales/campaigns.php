<?php
/**
 * The campaigns list: what a business sells in advance, and until when.
 *
 * Same shape as cashups/manage.php: bootstrap-table through table_support, the form in a modal
 * (modal-dlg), delete from the toolbar. The detail (products and delivery dates) is its own page.
 *
 * @var string $table_headers
 * @var array  $config
 */
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        table_support.init({
            resource: 'presales/campaigns',
            headers: <?= $table_headers ?>,
            pageSize: <?= (int) $config['lines_per_page'] ?>,
            uniqueId: 'campaign_id'
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <button class="btn btn-info btn-sm pull-right modal-dlg" data-btn-submit="<?= esc(lang('Common.submit')) ?>" data-href="<?= esc(site_url('presales/campaigns/view/-1')) ?>" title="<?= esc(lang('Presale_campaigns.new')) ?>">
        <span class="glyphicon glyphicon-tags">&nbsp;</span><?= esc(lang('Presale_campaigns.new')) ?>
    </button>
    <h3><?= esc(lang('Presales.campaigns')) ?></h3>
</div>

<div id="toolbar">
    <div class="pull-left form-inline" role="toolbar">
        <button id="delete" class="btn btn-default btn-sm print_hide">
            <span class="glyphicon glyphicon-trash">&nbsp;</span><?= esc(lang('Common.delete')) ?>
        </button>
    </div>
</div>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
