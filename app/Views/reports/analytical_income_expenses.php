<?php
/**
 * Analytical report: income against operating expenses.
 *
 * Unlike the other twenty reports this one does not open with a form that reloads the page. It uses
 * the same toolbar the Sales and Expenses grids use, so filters apply without losing the screen and
 * survive in the URL. See docs/Tecnico/reportes-analiticos-ingresos-gastos.md.
 *
 * @var string $controller_name
 * @var string $table_headers
 * @var array  $payment_options
 * @var array  $granularities
 * @var array  $selected_filters
 * @var array  $config
 */
?>

<?= view('partial/header') ?>

<div id="page_title"><?= lang('Reports.income_expenses') ?></div>
<div id="page_subtitle"></div>

<div id="toolbar">
    <div class="pull-left form-inline" role="toolbar">
        <?= form_input(['name' => 'daterangepicker', 'class' => 'form-control input-sm', 'id' => 'daterangepicker']) ?>

        <?= form_dropdown('granularity', esc($granularities), 'month', [
            'id'          => 'granularity',
            'class'       => 'selectpicker show-menu-arrow',
            'data-style'  => 'btn-default btn-sm',
            'data-width'  => 'fit',
            'title'       => lang('Reports.granularity')
        ]) ?>

        <?= form_multiselect('filters[]', esc($payment_options + ['include_deleted' => lang('Reports.include_deleted_expenses')]), $selected_filters, [
            'id'                        => 'filters',
            'data-none-selected-text'   => lang('Common.none_selected_text'),
            'class'                     => 'selectpicker show-menu-arrow',
            'data-selected-text-format' => 'count > 1',
            'data-style'                => 'btn-default btn-sm',
            'data-width'                => 'fit'
        ]) ?>
    </div>
</div>

<div class="ct-chart ct-golden-section" id="chart1"></div>

<div id="table_holder">
    <table id="table"></table>
</div>

<div id="report_summary_holder"></div>

<?= view('reports/graphs/multiline') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/daterangepicker') ?>

        <?= view('partial/bootstrap_tables_locale') ?>

        // Once the user picks a grouping by hand it stops being recalculated. Until then the range
        // decides it: nobody choosing "All time" wants a row per day, and nobody choosing
        // "Yesterday" wants a row per month.
        var granularity_touched = false;

        $('#granularity').on('changed.bs.select', function() {
            granularity_touched = true;
            table_support.refresh();
        });

        function derive_granularity() {
            if (granularity_touched) {
                return;
            }

            var days = Math.round((new Date(end_date) - new Date(start_date)) / 86400000) + 1;
            var derived = days <= 14 ? 'day' : (days <= 92 ? 'week' : 'month');

            // Derived from the length of the range, never from the preset's label: those labels are
            // translated strings, and comparing translated strings is the exact mechanism that left
            // the payment filters broken. A number of days does not depend on the language.
            $('#granularity').val(derived);
            $('#granularity').selectpicker('refresh');
        }

        $("#daterangepicker").on('apply.daterangepicker', function() {
            derive_granularity();
        });

        table_support.init({
            resource: '<?= esc($controller_name) ?>',
            headers: <?= $table_headers ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'period',
            onLoadSuccess: function(response) {
                if (!response) {
                    return;
                }

                $('#page_subtitle').text(response.subtitle || '');
                $('#report_summary_holder').html(response.summary || '');

                // The income column is renamed by the server, because selecting a payment method
                // changes what the report measures. Saying so on screen is the whole point.
                $('#table').bootstrapTable('refreshOptions', {
                    columns: $('#table').bootstrapTable('getOptions').columns
                });
                $('th[data-field="income"] .th-inner').text(response.income_header || '');

                if (response.chart) {
                    draw_multiline_chart(
                        '#chart1',
                        response.chart.labels,
                        response.chart.income,
                        response.chart.expenses,
                        response.income_header,
                        "<?= esc(lang('Reports.expenses'), 'js') ?>"
                    );
                }
            },
            queryParams: function() {
                return $.extend(arguments[0], {
                    "start_date":  start_date,
                    "end_date":    end_date,
                    "granularity": $('#granularity').val(),
                    "filters":     $('#filters').val()
                });
            }
        });

        derive_granularity();
    });
</script>

<?= view('partial/table_filter_persistence', ['options' => ['additional_params' => ['granularity']]]) ?>

<?= view('partial/footer') ?>
