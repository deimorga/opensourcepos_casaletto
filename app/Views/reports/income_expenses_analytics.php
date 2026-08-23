<?php
/**
 * Analytical report: income against operating expenses.
 *
 * Layout note: table_support.init() hardcodes `toolbar: '#toolbar'`, and bootstrap-table moves that
 * element into its own header above the table. The filters therefore live in their own container
 * with a different id, so they stay at the top where they are reached first instead of being
 * relocated below the chart.
 *
 * @var string      $controller_name
 * @var string      $table_headers
 * @var array       $payment_options
 * @var array       $granularities
 * @var array       $selected_filters
 * @var string|null $granularity
 * @var array       $config
 */
?>

<?= view('partial/header') ?>

<style>
    /* The chart is a summary, not the subject. ct-golden-section keeps a 100:61.8 ratio, which on a
       wide screen pushes everything else off the fold. A fixed height keeps the figures visible. */
    #income_expenses_chart { height: 210px; margin: 0 0 1em 0; }
    #income_expenses_chart .ct-label { fill: currentColor; color: inherit; font-size: .8rem; }
    #income_expenses_chart .ct-label.ct-horizontal { transform: none; text-anchor: middle; }
    #income_expenses_chart .ct-series-a .ct-line,
    #income_expenses_chart .ct-series-a .ct-point { stroke: #2e9e5b; stroke-width: 3px; }
    #income_expenses_chart .ct-series-b .ct-line,
    #income_expenses_chart .ct-series-b .ct-point { stroke: #c0392b; stroke-width: 3px; }

    #chart_legend { text-align: center; margin-bottom: 1em; font-size: .9em; }
    #chart_legend span { margin: 0 1em; }
    #chart_legend i { display: inline-block; width: 14px; height: 4px; vertical-align: middle; margin-right: .4em; }

    #report_filters { margin: 0 0 1em 0; }
    #report_filters .form-inline > * { margin-right: .5em; }

    #summary_cards { display: flex; flex-wrap: wrap; gap: .8em; margin-bottom: 1em; }
    #summary_cards .summary_card { flex: 1 1 160px; padding: .7em 1em; border: 1px solid rgba(127,127,127,.3); border-radius: 4px; }
    #summary_cards .summary_card .label { display: block; font-size: .8em; opacity: .75; margin-bottom: .25em; }
    #summary_cards .summary_card .value { display: block; font-size: 1.35em; font-weight: 600; }
    #summary_cards .summary_card.is_negative .value { color: #c0392b; }
    #summary_cards .summary_card.is_positive .value { color: #2e9e5b; }

    #cash_mode_notice { margin-bottom: 1em; }
</style>

<div id="page_title"><?= lang('Reports.income_expenses') ?></div>
<div id="page_subtitle"></div>

<div id="report_filters">
    <div class="form-inline" role="toolbar">
        <?= form_input(['name' => 'daterangepicker', 'class' => 'form-control input-sm', 'id' => 'daterangepicker']) ?>

        <?= form_dropdown('granularity', esc($granularities), $granularity ?? 'month', [
            'id'          => 'granularity',
            'class'       => 'selectpicker show-menu-arrow',
            'data-style'  => 'btn-default btn-sm',
            'data-width'  => 'fit',
            'title'       => lang('Reports.granularity')
        ]) ?>

        <?= form_multiselect('filters[]', esc($payment_options + ['include_deleted' => lang('Reports.include_deleted_expenses')]), $selected_filters, [
            'id'                        => 'filters',
            'data-none-selected-text'   => lang('Reports.payment_method'),
            'class'                     => 'selectpicker show-menu-arrow',
            'data-selected-text-format' => 'count > 1',
            'data-style'                => 'btn-default btn-sm',
            'data-width'                => 'fit'
        ]) ?>
    </div>
</div>

<div id="cash_mode_notice" class="alert alert-info" style="display: none;"></div>

<div id="summary_cards"></div>

<div id="chart_legend" style="display: none;">
    <span><i style="background:#2e9e5b;"></i><span id="legend_income"><?= lang('Reports.income') ?></span></span>
    <span><i style="background:#c0392b;"></i><?= lang('Reports.expenses') ?></span>
</div>
<div class="ct-chart" id="income_expenses_chart"></div>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('reports/graphs/multiline') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/daterangepicker') ?>

        <?= view('partial/bootstrap_tables_locale') ?>

        // The shared locale partial builds this message by concatenating the resource name into a
        // language key. This report's resource carries a slash, so that key cannot exist and the
        // untranslated key was being printed into the empty table.
        $.fn.bootstrapTable.locales['<?= current_language_code() ?>'].formatNoMatches = function() {
            return "<?= esc(lang('Reports.no_data_for_period'), 'js') ?>";
        };
        $.extend($.fn.bootstrapTable.defaults, $.fn.bootstrapTable.locales['<?= current_language_code() ?>']);

        <?php if (!empty($start_date)) { ?>
        // json_encode, not esc(): this is a JavaScript literal, and esc()'s html context does not
        // escape a backslash, which is all it takes to break out of the string.
        start_date = <?= json_encode((string) $start_date) ?>;
        <?php } ?>
        <?php if (!empty($end_date)) { ?>
        end_date = <?= json_encode((string) $end_date) ?>;
        <?php } ?>

        <?php if (!empty($start_date) || !empty($end_date)) { ?>
        // The picker has to show the range the report is actually displaying. Restoring only the
        // variables left the widget reading "today" while the table showed something else -- and any
        // change the user then made would have been computed from the wrong starting point.
        (function() {
            var picker = $('#daterangepicker').data('daterangepicker');

            if (picker) {
                picker.setStartDate(moment(start_date));
                picker.setEndDate(moment(end_date));
            }
        })();
        <?php } ?>

        // A grouping restored from the URL was already an explicit choice, so it is not recalculated.
        var granularity_touched = <?= $granularity === null ? 'false' : 'true' ?>;

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

        function money_card(label, value, tone) {
            return '<div class="summary_card ' + (tone || '') + '">'
                 + '<span class="label"></span><span class="value"></span></div>';
        }

        function render_summary(summary, income_label) {
            var cards = [
                { label: income_label,                                  value: summary.income,   tone: '' },
                { label: "<?= esc(lang('Reports.expenses'), 'js') ?>",  value: summary.expenses, tone: '' },
                { label: "<?= esc(lang('Reports.result'), 'js') ?>",    value: summary.result,   tone: summary.result_negative ? 'is_negative' : 'is_positive' },
                { label: "<?= esc(lang('Reports.margin'), 'js') ?>",    value: summary.margin,   tone: '' }
            ];

            var $holder = $('#summary_cards').empty();

            cards.forEach(function(card) {
                var $card = $(money_card());
                $card.addClass(card.tone);
                $card.find('.label').text(card.label);
                $card.find('.value').text(card.value);
                $holder.append($card);
            });
        }

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

                // Selecting a payment method changes what the report measures, so it says so rather
                // than leaving the reader to notice the figures moved.
                if (response.cash_mode && response.cash_mode_notice) {
                    $('#cash_mode_notice').text(response.cash_mode_notice).show();
                } else {
                    $('#cash_mode_notice').hide();
                }

                $('th[data-field="income"] .th-inner').text(response.income_header || '');
                $('#legend_income').text(response.income_header || '');

                render_summary(response.summary, response.income_header);

                // An empty chart is noise: with no rows it draws an axis from 0 to 1 and takes up
                // the screen saying nothing.
                var has_data = response.chart && response.chart.labels && response.chart.labels.length > 0;

                $('#income_expenses_chart').toggle(has_data);
                $('#chart_legend').toggle(has_data);

                if (has_data) {
                    draw_multiline_chart(
                        '#income_expenses_chart',
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
