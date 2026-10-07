<?php
/**
 * The presales list: one row per presale, with what was paid, what is left and whether it is late.
 *
 * Same layout as the cash-up list (cashups/manage.php): the shared header, a bootstrap-table fed by
 * presales/search, filters in the toolbar and the detail in a dialog. The search box finds a presale by
 * its number, the customer's name or the customer's phone.
 *
 * @var string                     $table_headers
 * @var list<array<string, mixed>> $campaigns
 * @var list<string>               $dates
 * @var array<string, string>      $states
 * @var array                      $config
 */
?>

<?= view('partial/header', [], ['saveData' => false]) ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale', [], ['saveData' => false]) ?>

        table_support.init({
            resource: 'presales',
            headers: <?= $table_headers ?>,
            pageSize: <?= (int) $config['lines_per_page'] ?>,
            uniqueId: 'presale_id',
            queryParams: function() {
                return $.extend(arguments[0], {
                    campaign_id: $('#presales_campaign').val(),
                    delivery_date: $('#presales_delivery_date').val(),
                    states: $('#presales_states').val() || []
                });
            }
        });

        $('#presales_campaign, #presales_delivery_date, #presales_states').on('change', function() {
            table_support.refresh();
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-info btn-sm pull-right" href="<?= site_url('presales/new') ?>">
        <span class="glyphicon glyphicon-plus">&nbsp;</span><?= esc(lang('Presales.new')) ?>
    </a>
</div>

<div id="toolbar">
    <div class="pull-left form-inline" role="toolbar">
        <label class="sr-only" for="presales_campaign"><?= esc(lang('Presales.campaign')) ?></label>
        <select id="presales_campaign" class="form-control input-sm">
            <option value=""><?= esc(lang('Presales.all_campaigns')) ?></option>
            <?php foreach ($campaigns as $campaign): ?>
                <option value="<?= (int) $campaign['campaign_id'] ?>"><?= esc($campaign['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <label class="sr-only" for="presales_delivery_date"><?= esc(lang('Presales.delivery_date')) ?></label>
        <select id="presales_delivery_date" class="form-control input-sm">
            <option value=""><?= esc(lang('Presales.all_dates')) ?></option>
            <?php foreach ($dates as $date): ?>
                <option value="<?= esc($date, 'attr') ?>"><?= esc(to_date(strtotime($date))) ?></option>
            <?php endforeach; ?>
        </select>

        <label class="sr-only" for="presales_states"><?= esc(lang('Presales.state')) ?></label>
        <?= form_multiselect('presales_states[]', esc($states), [], [
            'id'                        => 'presales_states',
            'class'                     => 'selectpicker show-menu-arrow',
            'data-none-selected-text'   => lang('Presales.state'),
            'data-selected-text-format' => 'count > 1',
            'data-style'                => 'btn-default btn-sm',
            'data-width'                => 'fit',
        ]) ?>
    </div>
</div>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer', [], ['saveData' => false]) ?>
