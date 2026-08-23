<?php
/**
 * Two-series line chart for the analytical reports.
 *
 * reports/graphs/line.php is hardwired to a single series and takes its data from PHP at render
 * time. This one exposes a function instead, because the analytical report feeds the chart from the
 * same JSON that feeds its table and redraws whenever a filter changes.
 *
 * @var array $config
 */

$currency_symbol = esc($config['currency_symbol'], 'js');
$currency_prefix = is_right_side_currency_symbol() ? '' : $currency_symbol;
$currency_suffix = is_right_side_currency_symbol() ? $currency_symbol : '';
?>

<script type="text/javascript">
    var income_expenses_chart = null;

    function draw_multiline_chart(selector, labels, series_income, series_expenses, name_income, name_expenses) {
        var data = {
            labels: labels,
            series: [
                { name: name_income,   data: series_income },
                { name: name_expenses, data: series_expenses }
            ]
        };

        var money = function(value) {
            return '<?= $currency_prefix ?>' + value + '<?= $currency_suffix ?>';
        };

        var options = {
            width: '100%',
            height: '100%',
            showPoint: true,
            lineSmooth: false,
            chartPadding: { top: 20, bottom: 40 },
            axisX: { offset: 60, position: 'end', labelOffset: { x: 0, y: 8 } },
            axisY: { offset: 90, labelOffset: { x: -20, y: 0 }, labelInterpolationFnc: money },
            // No ctAxisTitle here: that plugin throws when given empty titles, and both axes are
            // self-evident with the table sitting right below -- periods across, money up.
            plugins: [
                Chartist.plugins.tooltip({
                    pointClass: 'ct-tooltip-point',
                    transformTooltipTextFnc: money
                })
            ]
        };

        // Chartist has no update-in-place that survives a changing label count, so the chart is
        // detached and rebuilt whenever the filters change.
        if (income_expenses_chart) {
            income_expenses_chart.detach();
        }

        income_expenses_chart = new Chartist.Line(selector, data, options);

        income_expenses_chart.on('draw', function(data) {
            if (data.type === 'point') {
                var circle = new Chartist.Svg('circle', {
                    cx: [data.x], cy: [data.y], r: [4],
                    'ct:value': data.value.y,
                    'ct:meta': data.meta,
                    class: 'ct-tooltip-point'
                }, 'ct-area');
                data.element.replace(circle);
            }
        });

        return income_expenses_chart;
    }
</script>
