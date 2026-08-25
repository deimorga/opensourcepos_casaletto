<?php
/**
 * @var string $controller_name
 * @var string $table_headers
 * @var array $filters
 * @var array $selected_filters
 * @var array $config
 * @var string|null $start_date
 * @var string|null $end_date
 */
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        // Load the preset datarange picker
        <?= view('partial/daterangepicker') ?>

        <?= view('partial/bootstrap_tables_locale') ?>

        // Override dates from server if provided
        <?php if (isset($start_date) && $start_date): ?>
        // json_encode, not esc(): this is a JavaScript literal. esc()'s default html context does
        // not escape a backslash, and a backslash is enough to escape the closing quote and run on
        // into the rest of the script. The encoder emits its own quotes.
        start_date = <?= json_encode((string) $start_date) ?>;
        <?php endif; ?>
        <?php if (isset($end_date) && $end_date): ?>
        end_date = <?= json_encode((string) $end_date) ?>;
        <?php endif; ?>

        table_support.init({
            resource: '<?= esc($controller_name) ?>',
            headers: <?= $table_headers ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'expense_id',
            onLoadSuccess: function(response) {
                if ($("#table tbody tr").length > 1) {
                    $("#payment_summary").html(response.payment_summary);
                    $("#table tbody tr:last td:first").html("");
                    $("#table tbody tr:last").css('font-weight', 'bold');
                }
            },
            queryParams: function() {
                return $.extend(arguments[0], {
                    "start_date": start_date,
                    "end_date": end_date,
                    "filters": $("#filters").val()
                });
            }
        });

    });
</script>
<?= view('partial/table_filter_persistence') ?>

<?= view('partial/print_receipt', ['print_after_sale' => false, 'selected_printer' => 'takings_printer']) ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <button onclick="printdoc()" class="btn btn-info btn-sm pull-right">
        <span class="glyphicon glyphicon-print">&nbsp;</span><?= lang('Common.print') ?>
    </button>
    <button class="btn btn-info btn-sm pull-right modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= "$controller_name/view" ?>" title="<?= lang(ucfirst($controller_name) . '.new') ?>">
        <span class="glyphicon glyphicon-tags">&nbsp;</span><?= lang(ucfirst($controller_name) . '.new') ?>
    </button>
</div>

<div id="toolbar">
    <div class="pull-left form-inline" role="toolbar">
        <button id="delete" class="btn btn-default btn-sm print_hide">
            <span class="glyphicon glyphicon-trash">&nbsp;</span><?= lang('Common.delete') ?>
        </button>
        <?= form_input(['name' => 'daterangepicker', 'class' => 'form-control input-sm', 'id' => 'daterangepicker']) ?>
        <?php
        // This list is the only filter dropdown in the application that grows on its own: a new
        // expense category adds an entry. Counting what is actually in it decides whether it needs
        // a search box, rather than guessing from how it looks today.
        $filter_count = 0;

        foreach ($filters as $entry) {
            $filter_count += is_array($entry) ? count($entry) : 1;
        }
        ?>
        <?= form_multiselect('filters[]', esc($filters), $selected_filters ?? [], array_merge([
            'id'                        => 'filters',
            'data-none-selected-text'   => lang('Common.none_selected_text'),
            'class'                     => 'selectpicker show-menu-arrow',
            'data-selected-text-format' => 'count > 1',
            'data-style'                => 'btn-default btn-sm',
            'data-width'                => 'fit',
            // Cap the menu and let it scroll past that. Left to itself bootstrap-select grows to
            // the window, which on a tall screen means a dropdown running the height of the page
            // and on a short one means the last categories are unreachable.
            'data-size'                 => 12
        ], $filter_count > 15 ? [
            'data-live-search'             => 'true',
            'data-live-search-placeholder' => lang('Common.search')
        ] : [])) ?>
    </div>
</div>

<div id="table_holder">
    <table id="table"></table>
</div>

<div id="payment_summary"></div>

<?= view('partial/footer') ?>
