<?php
/**
 * One campaign: its products (with their prices) and its delivery dates.
 *
 * Same tab markup as cashups/form.php. The tables are drawn by script from presales/campaigns/data/N
 * with .text(): the values are plain, and building a cell out of a product name as markup is how a
 * name becomes a script tag. Server messages reach $.notify already escaped.
 *
 * @var array  $campaign the presale_campaigns row
 * @var string $period   the selling period, already in the business's date format
 * @var array  $config
 */
$id = (int) $campaign['campaign_id'];
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-default btn-sm pull-right" href="<?= esc(site_url('presales/campaigns')) ?>">
        <span class="glyphicon glyphicon-arrow-left">&nbsp;</span><?= esc(lang('Presale_campaigns.back_to_list')) ?>
    </a>
    <h3><?= esc((string) $campaign['name']) ?></h3>
</div>

<p class="text-muted" id="campaign_summary">
    <?= esc(lang('Presale_campaigns.sale_period')) ?>: <b><?= esc($period) ?></b>
    &middot; <?= esc(lang('Presale_campaigns.discount_percent')) ?>: <b><?= esc(presale_campaign_percent((string) $campaign['discount_percent'])) ?></b>
    &middot; <?= esc(lang('Presale_campaigns.min_initial_percent')) ?>: <b><?= esc(presale_campaign_percent((string) $campaign['min_initial_percent'])) ?></b>
    &middot; <?= esc(lang('Presale_campaigns.active')) ?>: <b><?= esc((int) $campaign['active'] === 1 ? lang('Presale_campaigns.yes') : lang('Presale_campaigns.no')) ?></b>
</p>

<ul class="nav nav-tabs" data-tabs="tabs" id="campaign_tabs" role="tablist">
    <li class="active" role="presentation">
        <a data-toggle="tab" href="#campaign_products" role="tab" aria-controls="campaign_products" aria-selected="true"><?= esc(lang('Presale_campaigns.products')) ?> <span class="badge" id="products_badge">0</span></a>
    </li>
    <li role="presentation">
        <a data-toggle="tab" href="#campaign_dates" role="tab" aria-controls="campaign_dates" aria-selected="false"><?= esc(lang('Presale_campaigns.dates')) ?> <span class="badge" id="dates_badge">0</span></a>
    </li>
</ul>

<div class="tab-content" style="padding-top: 15px;">

    <div class="tab-pane fade in active" id="campaign_products" role="tabpanel">
        <div class="form-inline" style="margin-bottom: 10px;">
            <input type="hidden" id="product_id" value="">
            <label for="product_search" class="sr-only"><?= esc(lang('Presale_campaigns.product_search')) ?></label>
            <input type="text" id="product_search" class="form-control input-sm" style="min-width: 260px;" autocomplete="off" placeholder="<?= esc(lang('Presale_campaigns.product_search')) ?>">
            <button type="button" id="add_product" class="btn btn-primary btn-sm">
                <span class="glyphicon glyphicon-plus">&nbsp;</span><?= esc(lang('Presale_campaigns.add_product')) ?>
            </button>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-hover" id="items_table">
                <thead>
                    <tr>
                        <th><?= esc(lang('Presale_campaigns.product')) ?></th>
                        <th class="text-right"><?= esc(lang('Presale_campaigns.catalogue_price')) ?></th>
                        <th class="text-right"><?= esc(lang('Presale_campaigns.base_price')) ?></th>
                        <th><?= esc(lang('Presale_campaigns.own_discount')) ?></th>
                        <th><?= esc(lang('Presale_campaigns.campaign_price')) ?></th>
                        <th class="text-right"><?= esc(lang('Presale_campaigns.effective_price')) ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <p class="help-block" id="products_empty" style="display: none;"><?= esc(lang('Presale_campaigns.empty_products')) ?></p>
        <p class="help-block"><?= esc(lang('Presale_campaigns.prices_note')) ?></p>
    </div>

    <div class="tab-pane fade" id="campaign_dates" role="tabpanel">
        <div class="form-inline" style="margin-bottom: 10px;">
            <label for="delivery_date" class="sr-only"><?= esc(lang('Presale_campaigns.delivery_date')) ?></label>
            <div class="input-group input-group-sm">
                <span class="input-group-addon"><span class="glyphicon glyphicon-calendar"></span></span>
                <input type="text" id="delivery_date" class="form-control input-sm campaign-date" autocomplete="off" placeholder="<?= esc(lang('Presale_campaigns.delivery_date')) ?>">
            </div>
            <button type="button" id="add_date" class="btn btn-primary btn-sm">
                <span class="glyphicon glyphicon-plus">&nbsp;</span><?= esc(lang('Presale_campaigns.add_date')) ?>
            </button>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-hover" id="dates_table" style="max-width: 480px;">
                <thead>
                    <tr>
                        <th><?= esc(lang('Presale_campaigns.delivery_date')) ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <p class="help-block" id="dates_empty" style="display: none;"><?= esc(lang('Presale_campaigns.empty_dates')) ?></p>
    </div>
</div>

<script type="text/javascript">
    $(document).ready(function() {
        var BASE = <?= json_encode(site_url('presales/campaigns')) ?>;
        var ID = <?= $id ?>;
        var T = {
            remove: <?= json_encode(lang('Presale_campaigns.remove')) ?>,
            save: <?= json_encode(lang('Presale_campaigns.save')) ?>,
            inherits: <?= json_encode(lang('Presale_campaigns.inherits')) ?>,
            ownDiscountHelp: <?= json_encode(lang('Presale_campaigns.own_discount_help')) ?>,
            priceHelp: <?= json_encode(lang('Presale_campaigns.campaign_price_help')) ?>,
            weight: <?= json_encode(lang('Presale_campaigns.weight_item')) ?>,
            confirmItem: <?= json_encode(lang('Presale_campaigns.confirm_remove_item')) ?>,
            confirmDate: <?= json_encode(lang('Presale_campaigns.confirm_remove_date')) ?>,
            notPicked: <?= json_encode(lang('Presale_campaigns.item_not_picked')) ?>,
            failed: <?= json_encode(lang('Presale_campaigns.saving_failed')) ?>
        };

        <?= view('partial/datepicker_locale') ?>

        // Date only, like the campaign form; see campaign_form.php for why .datetime is added after.
        $('.campaign-date').datetimepicker({
            format: <?= json_encode(dateformat_bootstrap($config['dateformat'])) ?>,
            minView: 2,
            autoclose: true,
            todayBtn: true,
            todayHighlight: true,
            bootcssVer: 3,
            language: <?= json_encode(current_language_code()) ?>
        }).addClass('datetime');

        var notify = function(response) {
            $.notify(response.message, {type: response.success ? 'success' : 'danger'});
        };

        // POST, tell the person what happened, and redraw only when it worked.
        var act = function(url, data, done) {
            $.post(url, data, function(response) {
                notify(response);
                if (response.success) {
                    load();
                    done && done();
                }
            }, 'json').fail(function(xhr) {
                var message = T.failed;
                try { message = xhr.responseJSON.message || message; } catch (e) {}
                $.notify(message, {type: 'danger'});
            });
        };

        var button = function(icon, label, cls) {
            return $('<button type="button" class="btn btn-xs"></button>')
                .addClass(cls)
                .attr('title', label)
                .append($('<span class="glyphicon"></span>').addClass(icon))
                .append($('<span class="sr-only"></span>').text(label));
        };

        var draw_items = function(items) {
            var $body = $('#items_table tbody').empty();
            $('#products_badge').text(items.length);
            $('#products_empty').toggle(items.length === 0);

            $.each(items, function(index, item) {
                var $row = $('<tr></tr>');
                var $name = $('<td></td>').text(item.name);
                if (item.item_number) {
                    $name.append($('<small class="text-muted"></small>').text(' ' + item.item_number));
                }
                if (item.is_weight) {
                    $name.append(' ').append($('<span class="label label-default"></span>').text(T.weight));
                }

                var $discount = $('<input type="text" class="form-control input-sm own-discount" inputmode="decimal" autocomplete="off">')
                    .val(item.discount_input).attr('aria-label', <?= json_encode(lang('Presale_campaigns.own_discount')) ?> + ' ' + item.name)
                    .attr('placeholder', item.inherited);
                var $price = $('<input type="text" class="form-control input-sm own-price" inputmode="decimal" autocomplete="off">')
                    .val(item.price_input).attr('aria-label', <?= json_encode(lang('Presale_campaigns.campaign_price')) ?> + ' ' + item.name);

                var $save = button('glyphicon-ok', T.save, 'btn-success').on('click', function() {
                    act(BASE + '/' + ID + '/update_item/' + item.item_id, {
                        discount_percent: $discount.val(),
                        campaign_price: $price.val()
                    });
                });
                var $remove = button('glyphicon-trash', T.remove, 'btn-danger').on('click', function() {
                    if (confirm(T.confirmItem)) {
                        act(BASE + '/' + ID + '/remove_item/' + item.item_id, {});
                    }
                });

                $row.append($name)
                    .append($('<td class="text-right"></td>').text(item.catalogue_price))
                    .append($('<td class="text-right"></td>').text(item.base_price))
                    .append($('<td style="min-width: 110px;"></td>').append($discount))
                    .append($('<td style="min-width: 110px;"></td>').append($price))
                    .append($('<td class="text-right"></td>').append($('<b></b>').text(item.effective_price)))
                    .append($('<td class="text-nowrap"></td>').append($save).append(' ').append($remove));
                $body.append($row);
            });
        };

        var draw_dates = function(dates) {
            var $body = $('#dates_table tbody').empty();
            $('#dates_badge').text(dates.length);
            $('#dates_empty').toggle(dates.length === 0);

            $.each(dates, function(index, date) {
                var $remove = button('glyphicon-trash', T.remove, 'btn-danger').on('click', function() {
                    if (confirm(T.confirmDate)) {
                        act(BASE + '/' + ID + '/remove_date/' + date.date_id, {});
                    }
                });
                $body.append($('<tr></tr>')
                    .append($('<td></td>').text(date.date))
                    .append($('<td class="text-right"></td>').append($remove)));
            });
        };

        var load = function() {
            $.get(BASE + '/data/' + ID, function(response) {
                if (response.success) {
                    draw_items(response.items);
                    draw_dates(response.dates);
                }
            }, 'json');
        };

        // The picker. This module's own suggest endpoint, not items/suggest (see PresaleCampaigns).
        var fill_item = function(event, ui) {
            event.preventDefault();
            $('#product_id').val(ui.item.value);
            $('#product_search').val(DOMPurify.sanitize(ui.item.label));
        };

        $('#product_search').autocomplete({
            source: BASE + '/suggest',
            minChars: 0,
            delay: 15,
            autoFocus: false,
            select: fill_item,
            focus: fill_item
        });

        // Erasing the name erases the id with it, or the form would add the product picked before.
        $('#product_search').on('change keyup', function() {
            if (!$(this).val()) {
                $('#product_id').val('');
            }
        });

        $('#add_product').on('click', function() {
            var item_id = $('#product_id').val();

            if (!item_id) {
                $.notify(T.notPicked, {type: 'danger'});
                return;
            }

            act(BASE + '/' + ID + '/add_item', {item_id: item_id}, function() {
                $('#product_id').val('');
                $('#product_search').val('').focus();
            });
        });

        $('#add_date').on('click', function() {
            act(BASE + '/' + ID + '/add_date', {delivery_date: $('#delivery_date').val()}, function() {
                $('#delivery_date').val('').trigger('change');
            });
        });

        $('#campaign_tabs a[data-toggle="tab"]').on('shown.bs.tab', function() {
            $('#campaign_tabs a[role="tab"]').attr('aria-selected', 'false');
            $('#campaign_tabs li.active a[role="tab"]').attr('aria-selected', 'true');
        });

        load();
    });
</script>

<?= view('partial/footer') ?>
