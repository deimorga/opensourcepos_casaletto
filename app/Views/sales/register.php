<?php
/**
 * @var string $controller_name
 * @var array $modes
 * @var array $mode
 * @var array $empty_tables
 * @var array $selected_table
 * @var array $open_tabs
 * @var array $stock_locations
 * @var array $stock_location
 * @var array $cart
 * @var bool $items_module_allowed
 * @var bool $change_price
 * @var int $customer_id
 * @var int $customer_discount_type
 * @var float $customer_discount
 * @var float $customer_total
 * @var string $customer_required
 * @var float|int $item_count
 * @var float|int $total_units
 * @var float $subtotal
 * @var array $taxes
 * @var float $total
 * @var float $payments_total
 * @var float $amount_due
 * @var bool $payments_cover_total
 * @var array $payment_options
 * @var array $selected_payment_type
 * @var bool $pos_mode
 * @var array $payments
 * @var string $mode_label
 * @var string $comment
 * @var bool $print_after_sale
 * @var bool $email_receipt
 * @var bool $price_work_orders
 * @var string $invoice_number
 * @var int $cash_mode
 * @var float $non_cash_total
 * @var float $cash_amount_due
 * @var array $config
 * @var array $weight_entry
 */

use App\Libraries\Sale_lib;
use App\Models\Employee;
use App\Models\Item;
use App\Models\Item_quantity;

?>

<?= view('partial/header') ?>

<?php
if (isset($error)) {
    echo '<div class="alert alert-dismissible alert-danger">' . esc($error) . '</div>';
}

if (!empty($warning)) {
    echo '<div class="alert alert-dismissible alert-warning">' . esc($warning) . '</div>';
}

if (isset($success)) {
    echo '<div class="alert alert-dismissible alert-success">' . esc($success) . '</div>';
}

helper('url');
?>

<div id="register_wrapper">

    <!-- Top register controls -->
    <?= form_open("$controller_name/changeMode", ['id' => 'mode_form', 'class' => 'form-horizontal panel panel-default']) ?>
        <div class="panel-body form-group">
            <ul>
                <li class="pull-left first_li">
                    <label class="control-label"><?= lang(ucfirst($controller_name) . '.mode') ?></label>
                </li>
                <li class="pull-left">
                    <?= form_dropdown('mode', $modes, $mode, ['onchange' => "$('#mode_form').submit();", 'class' => 'selectpicker show-menu-arrow', 'data-style' => 'btn-default btn-sm', 'data-width' => 'fit']) ?>
                </li>
                <?php if ($config['dinner_table_enable']) { ?>
                    <li class="pull-left first_li">
                        <label class="control-label"><?= lang(ucfirst($controller_name) . '.table') ?></label>
                    </li>
                    <li class="pull-left">
                        <?= form_dropdown('dinner_table', $empty_tables, $selected_table, ['onchange' => "$('#mode_form').submit();", 'class' => 'selectpicker show-menu-arrow', 'data-style' => 'btn-default btn-sm', 'data-width' => 'fit']) ?>
                    </li>
                <?php } ?>
                <?php if (count($stock_locations) > 1) { ?>
                    <li class="pull-left">
                        <label class="control-label"><?= lang(ucfirst($controller_name) . '.stock_location') ?></label>
                    </li>
                    <li class="pull-left">
                        <?= form_dropdown('stock_location', $stock_locations, $stock_location, ['onchange' => "$('#mode_form').submit();", 'class' => 'selectpicker show-menu-arrow', 'data-style' => 'btn-default btn-sm', 'data-width' => 'fit']) ?>
                    </li>
                <?php } ?>

                <li class="pull-right">
                    <button class="btn btn-default btn-sm modal-dlg" id="show_suspended_sales_button" data-href="<?= esc("$controller_name/suspended") ?>"
                        title="<?= lang(ucfirst($controller_name) . '.suspended_sales') ?>">
                        <span class="glyphicon glyphicon-align-justify">&nbsp;</span><?= lang(ucfirst($controller_name) . '.suspended_sales') ?>
                    </button>
                </li>

                <?php
                $employee = model(Employee::class);
                if ($employee->has_grant('reports_sales', session('person_id'))) {
                ?>
                    <li class="pull-right">
                        <?= anchor(
                            "$controller_name/manage",
                            '<span class="glyphicon glyphicon-list-alt">&nbsp;</span>' . lang(ucfirst($controller_name) . '.takings'),
                            array('class' => 'btn btn-primary btn-sm', 'id' => 'sales_takings_button', 'title' => lang(ucfirst($controller_name) . '.takings'))
                        ) ?>
                    </li>
                <?php } ?>
            </ul>
        </div>
    <?= form_close() ?>

    <?php if ($config['dinner_table_enable']) { ?>
        <div id="open_tabs_bar" class="panel panel-default">
            <div class="panel-body form-group" style="padding-bottom: 5px;">
                <ul class="nav nav-pills">
                    <?php foreach ($open_tabs as $open_tab) { ?>
                        <li class="<?= ((int) $open_tab['dinner_table_id'] === (int) $selected_table) ? 'active' : '' ?>">
                            <a href="#" class="open_tab_button" data-dinner-table-id="<?= esc($open_tab['dinner_table_id']) ?>">
                                <span class="glyphicon glyphicon-cutlery">&nbsp;</span><?= esc($open_tab['dinner_table_name'] ?? ('#' . $open_tab['dinner_table_id'])) ?>
                            </a>
                        </li>
                    <?php } ?>
                    <li>
                        <a href="#" id="new_table_button" title="<?= lang('Sales.new_table') ?>">
                            <span class="glyphicon glyphicon-plus">&nbsp;</span><?= lang('Sales.new_table') ?>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    <?php } ?>

    <?= form_open("$controller_name/createTable", ['id' => 'new_table_form']) ?>
        <input type="hidden" name="table_name" id="new_table_name">
    <?= form_close() ?>

    <?php $tabindex = 0; ?>

    <?= form_open("$controller_name/add", ['id' => 'add_item_form', 'class' => 'form-horizontal panel panel-default']) ?>
        <div class="panel-body form-group">
            <ul>
                <li class="pull-left first_li">
                    <label for="item" class="control-label"><?= lang(ucfirst($controller_name) . '.find_or_scan_item_or_receipt') ?></label>
                </li>
                <li class="pull-left">
                    <?= form_input(['name' => 'item', 'id' => 'item', 'class' => 'form-control input-sm', 'size' => '50', 'tabindex' => ++$tabindex]) ?>
                    <span class="ui-helper-hidden-accessible" role="status"></span>
                </li>
                <li class="pull-right">
                    <button id="new_item_button" class="btn btn-info btn-sm pull-right modal-dlg" data-btn-new="<?= lang('Common.new') ?>" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= "items/view" ?>" title="<?= lang(ucfirst($controller_name) . ".new_item") ?>">
                        <span class="glyphicon glyphicon-tag">&nbsp;</span><?= lang(ucfirst($controller_name) . ".new_item") ?>
                    </button>
                </li>
            </ul>
        </div>
    <?= form_close() ?>

    <?php
    // The weight field exists only while an item priced by weight is waiting
    // to be weighed, and nothing else on this page changes. That is the whole
    // isolation mechanism: a shop whose items are all sold by the unit never
    // has an item waiting, so it never sees any of this -- and unlike a
    // setting, there is nothing here anybody can get wrong.
    $weight_entry = $weight_entry ?? [];

    if (!empty($weight_entry)) {
        // The keypad types the separator this tenant reads, so what the
        // cashier sees while typing matches what the line shows afterwards.
        // Both separators are accepted on the way in either way -- a scale in
        // keyboard mode types a dot no matter where it is plugged in.
        $weight_decimal_separator = (new NumberFormatter($config['number_locale'], NumberFormatter::DECIMAL))
            ->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
        $weight_keypad_rows = [['7', '8', '9'], ['4', '5', '6'], ['1', '2', '3']];

        // The prompt is the only thing telling the cashier which number to
        // type, so it names the unit the item is priced in. Kilograms is the
        // only weighed unit there is (App\Models\Item::ALLOWED_UNITS_OF_MEASURE
        // says why), but the label is still keyed off the line's own unit
        // rather than hard-coded: nothing here converts, so the day a second
        // weighed unit is ever justified, a wrong label would be a wrong price
        // and not a cosmetic slip.
        $weight_unit_of_measure = Sale_lib::weight_entry_unit_of_measure($weight_entry);
        $weight_field_label = Sale_lib::translate_or(
            'Sales.weight_in_' . $weight_unit_of_measure,
            'Weight in kilograms'
        );
        $weight_price_label = Sale_lib::translate_or(
            'Sales.price_per_' . $weight_unit_of_measure,
            'Price per kilogram'
        );
    ?>
        <div id="weight_entry_panel" class="panel panel-warning" role="region" aria-labelledby="weight_entry_heading">
            <div class="panel-heading">
                <?php
                // h2, not h3: this is the only heading the register has, and
                // .panel-title makes both look identical. The level is what a
                // screen reader announces.
                ?>
                <h2 class="panel-title" id="weight_entry_heading">
                    <span class="glyphicon glyphicon-scale" aria-hidden="true">&nbsp;</span>
                    <?= esc(Sale_lib::translate_or('Sales.weigh_item', 'Weigh')) ?>:
                    <strong><?= esc($weight_entry['name']) ?></strong>
                    <?php if (!empty($weight_entry['item_number'])) { ?>
                        <span class="weight-entry-item-number">(<?= esc($weight_entry['item_number']) ?>)</span>
                    <?php } ?>
                </h2>
            </div>
            <div class="panel-body">
                <?= form_open("$controller_name/addWeight", ['id' => 'weight_entry_form']) ?>
                    <div class="weight-entry-layout">
                        <?php
                        // The input, then the two decisions, then the keypad:
                        // document order is tab order, and a keypad is a
                        // convenience for a finger, not the way a keyboard user
                        // gets through this screen.
                        ?>
                        <div class="weight-entry-main">
                            <div class="form-group<?= isset($error) ? ' has-error' : '' ?>">
                                <label class="weight-entry-label" for="weight">
                                    <?= esc($weight_field_label) ?>
                                </label>
                                <?= form_input([
                                    'name'         => 'weight',
                                    'id'           => 'weight',
                                    'type'         => 'text',
                                    // Never type="number": in a comma locale the
                                    // browser calls "0,735" invalid and hands back
                                    // an empty string, which would drop the weight
                                    // on the floor without saying so.
                                    'class'        => 'form-control',
                                    'value'        => '',
                                    'inputmode'    => 'decimal',
                                    'autocomplete' => 'off',
                                    'aria-describedby' => 'weight_entry_hint',
                                    'aria-invalid' => isset($error) ? 'true' : 'false',
                                    'tabindex'     => ++$tabindex
                                ]) ?>
                                <p class="weight-entry-hint" id="weight_entry_hint">
                                    <?= esc($weight_price_label) ?>:
                                    <strong><?= to_currency($weight_entry['unit_price']) ?></strong>
                                    &middot;
                                    <?= esc(Sale_lib::translate_or('Sales.weight_example', 'for example')) ?>
                                    <?= esc('0' . $weight_decimal_separator . '735') ?>
                                </p>
                            </div>

                            <div class="weight-entry-actions">
                                <button type="submit" class="btn btn-primary" id="add_weight_button" tabindex="<?= ++$tabindex ?>">
                                    <span class="glyphicon glyphicon-ok" aria-hidden="true">&nbsp;</span><?= esc(Sale_lib::translate_or('Sales.add_weighed_item', 'Add to sale')) ?>
                                </button>
                                <button type="submit" class="btn btn-default" id="cancel_weight_button" tabindex="<?= ++$tabindex ?>"
                                    formaction="<?= esc(site_url("$controller_name/cancelWeight")) ?>" formnovalidate>
                                    <span class="glyphicon glyphicon-remove" aria-hidden="true">&nbsp;</span><?= esc(Sale_lib::translate_or('Sales.weight_cancel', 'Cancel')) ?>
                                </button>
                            </div>
                        </div>

                        <?php
                        // The keypad is the contingency that keeps the shop
                        // open on the day the scale fails, or the day somebody
                        // spills coffee on the keyboard. It is not decoration.
                        ?>
                        <div class="weight-keypad" role="group" aria-label="<?= esc(Sale_lib::translate_or('Sales.weight_keypad', 'Numeric keypad')) ?>">
                            <?php foreach ($weight_keypad_rows as $weight_keypad_row) { ?>
                                <?php foreach ($weight_keypad_row as $weight_key) { ?>
                                    <button type="button" class="btn btn-default weight-key" data-weight-key="<?= esc($weight_key) ?>"><?= esc($weight_key) ?></button>
                                <?php } ?>
                            <?php } ?>
                            <button type="button" class="btn btn-default weight-key" data-weight-key="0">0</button>
                            <button type="button" class="btn btn-default weight-key" data-weight-key="separator"><?= esc($weight_decimal_separator) ?></button>
                            <button type="button" class="btn btn-default weight-key" data-weight-key="backspace">
                                <span class="glyphicon glyphicon-erase" aria-hidden="true"></span>
                                <span class="sr-only"><?= esc(Sale_lib::translate_or('Sales.weight_backspace', 'Delete the last digit')) ?></span>
                            </button>
                        </div>
                    </div>
                <?= form_close() ?>
            </div>
        </div>
    <?php } ?>

    <!-- Sale Items List -->

    <table class="sales_table_100" id="register">
        <thead>
            <tr>
                <th style="width: 5%;"><?= lang('Common.delete') ?></th>
                <th style="width: 15%;"><?= lang(ucfirst($controller_name) . '.item_number') ?></th>
                <th style="width: 30%;"><?= lang(ucfirst($controller_name) . '.item_name') ?></th>
                <th style="width: 10%;"><?= lang(ucfirst($controller_name) . '.price') ?></th>
                <th style="width: 10%;"><?= lang(ucfirst($controller_name) . '.quantity') ?></th>
                <th style="width: 15%;"><?= lang(ucfirst($controller_name) . '.discount') ?></th>
                <th style="width: 10%;"><?= lang(ucfirst($controller_name) . '.total') ?></th>
                <th style="width: 5%;"><?= lang(ucfirst($controller_name) . '.update') ?></th>
            </tr>
        </thead>

        <tbody id="cart_contents">
            <?php if (count($cart) == 0) { ?>
                <tr>
                    <td colspan="8">
                        <div class="alert alert-dismissible alert-info"><?= lang(ucfirst($controller_name) . '.no_items_in_cart') ?></div>
                    </td>
                </tr>
            <?php
            } else {
                // Kit ingredient rows (print_option == PRINT_NO, set on every component
                // when the kit's print_option is "kit item only") are buffered here and
                // flushed as a collapsible group under the kit's own row (print_option ==
                // PRINT_YES), which always renders right after its ingredients in this
                // reversed iteration order -- see Sales::postAdd()/Sale_lib::add_item_kit(),
                // the kit item is added to the cart before its components.
                $pending_kit_children = [];

                // A weight is never shown with fewer decimals than it has.
                // The value rendered into the quantity input is exactly what
                // the next edit of that line posts back, so a 0,735 displayed
                // as "1" -- which is what to_quantity_decimals() produces when
                // the tenant's quantity_decimals is 0 -- would replace the
                // weight with one kilo the moment the cashier corrects
                // anything else on the line, silently and with no way to tell
                // afterwards. quantity_scale() is the same floor the
                // arithmetic already uses, so the two cannot drift apart.
                $format_line_quantity = function (array $item) use ($config): string {
                    if (!Sale_lib::line_sells_by_weight($item)) {
                        return to_quantity_decimals($item['quantity']);
                    }

                    $digits = Item_quantity::quantity_scale();
                    $formatter = new NumberFormatter($config['number_locale'], NumberFormatter::DECIMAL);
                    $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $digits);
                    $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $digits);

                    if (empty($config['thousands_separator'])) {
                        $formatter->setTextAttribute(NumberFormatter::GROUPING_SEPARATOR_SYMBOL, '');
                    }

                    return $formatter->format((float) $item['quantity']);
                };

                foreach (array_reverse($cart, true) as $line => $item) {
                    $is_kit_ingredient = $item['print_option'] == PRINT_NO;
                    ob_start();
            ?>
                    <?= form_open("$controller_name/editItem/$line", ['class' => 'form-horizontal', 'id' => "cart_$line"]) ?>
                        <tr<?= $is_kit_ingredient ? ' class="kit-ingredient-row" data-kit-group="__KIT_GROUP__"' : '' ?>>
                            <td>
                                <?php
                                echo anchor("$controller_name/deleteItem/$line", '<span class="glyphicon glyphicon-trash"></span>');
                                echo form_hidden('location', (string)$item['item_location']);
                                echo form_input(['type' => 'hidden', 'name' => 'item_id', 'value' => $item['item_id']]);
                                ?>
                            </td>
                            <?php if ($item['item_type'] == ITEM_TEMP) { ?>
                                <td><?= form_input(['name' => 'item_number', 'id' => 'item_number', 'class' => 'form-control input-sm', 'value' => $item['item_number'], 'tabindex' => ++$tabindex]) ?></td>
                                <td style="text-align: center;">
                                    <?= form_input(['name' => 'name', 'id' => 'name', 'class' => 'form-control input-sm', 'value' => $item['name'], 'tabindex' => ++$tabindex]) ?>
                                </td>
                            <?php } else { ?>
                                <td><?= esc($item['item_number']) ?></td>
                                <td style="text-align: center;">
                                    <?= esc($item['name']) . ' ' . esc(implode(' ', [$item['attribute_values'], $item['attribute_dtvalues']])) ?>
                                    <?php if (!$is_kit_ingredient && count($pending_kit_children) > 0) { ?>
                                        <a href="javascript:void(0);" class="kit-toggle" data-kit-group="<?= $line ?>" title="<?= lang(ucfirst($controller_name) . '.show_kit_ingredients') ?>">
                                            <span class="glyphicon glyphicon-triangle-right"></span>
                                            <?= count($pending_kit_children) ?> <?= lang(ucfirst($controller_name) . '.kit_ingredients') ?>
                                        </a>
                                    <?php } ?>
                                    <br>
                                    <?php if ($item['stock_type'] == '0'): echo '[' . to_quantity_decimals($item['in_stock']) . ' in ' . esc($item['stock_name']) . ']';
                                    endif; ?>
                                </td>
                            <?php } ?>

                            <td>
                                <?php
                                if ($items_module_allowed && $change_price) {
                                    echo form_input(['name' => 'price', 'class' => 'form-control input-sm', 'value' => to_currency_no_money($item['price']), 'tabindex' => ++$tabindex, 'onClick' => 'this.select();']);
                                } else {
                                    echo to_currency($item['price']);
                                    echo form_hidden('price', to_currency_no_money($item['price']));
                                }
                                ?>
                            </td>

                            <td>
                                <?php
                                if ($item['is_serialized']) {
                                    echo $format_line_quantity($item);
                                    echo form_hidden('quantity', $item['quantity']);
                                } else {
                                    echo form_input(['name' => 'quantity', 'class' => 'form-control input-sm', 'value' => $format_line_quantity($item), 'tabindex' => ++$tabindex, 'onClick' => 'this.select();']);
                                }

                                // 'kg' is an international symbol, so it reads
                                // the same in every language this screen ships
                                // in -- but it still comes from the line, never
                                // written in here, so a line can never be
                                // labelled with a unit it is not priced in. A
                                // line sold by the unit gets nothing, which is
                                // what every line in a shop that does not weigh
                                // anything looks like today.
                                if (Sale_lib::line_sells_by_weight($item)) {
                                    echo '<span class="line-unit-of-measure">' . esc(Sale_lib::line_unit_of_measure_symbol($item)) . '</span>';
                                }
                                ?>
                            </td>

                            <td>
                                <div class="input-group">
                                    <?= form_input(['name' => 'discount', 'class' => 'form-control input-sm', 'value' => $item['discount_type'] ? to_currency_no_money($item['discount']) : to_decimals($item['discount']), 'tabindex' => ++$tabindex, 'onClick' => 'this.select();']) ?>
                                    <span class="input-group-btn">
                                        <?= form_checkbox(['id' => 'discount_toggle', 'name' => 'discount_toggle', 'value' => 1, 'data-toggle' => "toggle", 'data-size' => 'small', 'data-onstyle' => 'success', 'data-on' => '<b>' . $config['currency_symbol'] . '</b>', 'data-off' => '<b>%</b>', 'data-line' => $line, 'checked' => $item['discount_type'] == 1]) ?>
                                    </span>
                                </div>
                            </td>

                            <td>
                                <?php
                                if ($item['item_type'] == ITEM_AMOUNT_ENTRY) {    // TODO: === ?
                                    echo form_input(['name' => 'discounted_total', 'class' => 'form-control input-sm', 'value' => to_currency_no_money($item['discounted_total']), 'tabindex' => ++$tabindex, 'onClick' => 'this.select();']);
                                } else {
                                    echo to_currency($item['discounted_total']);
                                }
                                ?>
                            </td>

                            <td>
                                <a href="javascript:document.getElementById('<?= "cart_$line" ?>').submit();" title="<?= lang(ucfirst($controller_name) . '.update') ?>">
                                    <span class="glyphicon glyphicon-refresh"></span>
                                </a>
                            </td>
                        </tr>
                        <tr<?= $is_kit_ingredient ? ' class="kit-ingredient-row" data-kit-group="__KIT_GROUP__"' : '' ?>>
                            <?php if ($item['item_type'] == ITEM_TEMP) { ?>
                                <td><?= form_input(['type' => 'hidden', 'name' => 'item_id', 'value' => $item['item_id']]) ?></td>
                                <td style="text-align: center;" colspan="6">
                                    <?= form_input(['name' => 'item_description', 'id' => 'item_description', 'class' => 'form-control input-sm', 'value' => $item['description'], 'tabindex' => ++$tabindex]) ?>
                                </td>
                                <td> </td>
                            <?php } else { ?>
                                <td>&nbsp;</td>
                                <?php if ($item['allow_alt_description']) { ?>
                                    <td style="color: #2F4F4F;"><?= lang(ucfirst($controller_name) . '.description_abbrv') ?></td>
                                <?php } ?>

                                <td colspan="2" style="text-align: left;">
                                    <?php
                                    if ($item['allow_alt_description']) {
                                        echo form_input(['name' => 'description', 'class' => 'form-control input-sm', 'value' => $item['description'], 'onClick' => 'this.select();']);
                                    } else {
                                        if ($item['description'] != '') {
                                            echo esc($item['description']);
                                            echo form_hidden('description', $item['description']);
                                        } else {
                                            echo lang(ucfirst($controller_name) . '.no_description');
                                            echo form_hidden('description', '');
                                        }
                                    }
                                    ?>
                                </td>
                                <td>&nbsp;</td>
                                <td style="color: #2F4F4F;">
                                    <?php
                                    if ($item['is_serialized']) {
                                        echo lang(ucfirst($controller_name) . '.serial');
                                    }
                                    ?>
                                </td>
                                <td colspan="4" style="text-align: left;">
                                    <?php
                                    if ($item['is_serialized']) {
                                        echo form_input(['name' => 'serialnumber', 'class' => 'form-control input-sm', 'value' => $item['serialnumber'], 'onClick' => 'this.select();']);
                                    } else {
                                        echo form_hidden('serialnumber', '');
                                    }
                                    ?>
                                </td>
                            <?php } ?>
                        </tr>
                    <?= form_close() ?>
            <?php
                    $row_html = ob_get_clean();

                    if ($is_kit_ingredient) {
                        $pending_kit_children[] = $row_html;
                        continue;
                    }

                    // Print the kit's own row first, then its (hidden) ingredient rows
                    // right after it -- otherwise, since ingredients were buffered while
                    // walking backwards through insertion order, they'd print above the
                    // kit row and expanding one at the bottom of a long cart would force
                    // scrolling back up to see it.
                    echo $row_html;

                    if (count($pending_kit_children) > 0) {
                        echo str_replace('__KIT_GROUP__', (string) $line, implode('', $pending_kit_children));
                        $pending_kit_children = [];
                    }
                }

                // Orphaned kit ingredient rows (their kit's own row was individually
                // deleted from the cart) -- render plainly rather than hide them where
                // nothing can un-hide them.
                if (count($pending_kit_children) > 0) {
                    echo implode('', $pending_kit_children);
                }
            }
            ?>
        </tbody>
    </table>
</div>

<!-- Overall Sale -->

<div id="overall_sale" class="panel panel-default">
    <div class="panel-body">
        <?= form_open("$controller_name/selectCustomer", ['id' => 'select_customer_form', 'class' => 'form-horizontal']) ?>
            <?php if (isset($customer)) { ?>
                <table class="sales_table_100">
                    <tr>
                        <th style="width: 55%;"><?= lang(ucfirst($controller_name) . '.customer') ?></th>
                        <th style="width: 45%; text-align: right;"><?= anchor("customers/view/$customer_id", esc($customer), ['class' => 'modal-dlg', 'data-btn-submit' => lang('Common.submit'), 'title' => lang('Customers.update')]) ?></th>
                    </tr>
                    <?php if (!empty($customer_email)) { ?>
                        <tr>
                            <th style="width: 55%;"><?= lang(ucfirst($controller_name) . '.customer_email') ?></th>
                            <th style="width: 45%; text-align: right;"><?= esc($customer_email) ?></th>
                        </tr>
                    <?php } ?>
                    <?php if (!empty($customer_address)) { ?>
                        <tr>
                            <th style="width: 55%;"><?= lang(ucfirst($controller_name) . '.customer_address') ?></th>
                            <th style="width: 45%; text-align: right;"><?= esc($customer_address) ?></th>
                        </tr>
                    <?php } ?>
                    <?php if (!empty($customer_location)) { ?>
                        <tr>
                            <th style="width: 55%;"><?= lang(ucfirst($controller_name) . '.customer_location') ?></th>
                            <th style="width: 45%; text-align: right;"><?= esc($customer_location) ?></th>
                        </tr>
                    <?php } ?>
                    <tr>
                        <th style="width: 55%;"><?= lang(ucfirst($controller_name) . '.customer_discount') ?></th>
                        <th style="width: 45%; text-align: right;"><?= ($customer_discount_type == FIXED) ? to_currency($customer_discount) : $customer_discount . '%' ?></th>
                    </tr>
                    <?php if ($config['customer_reward_enable']): ?>
                        <?php if (!empty($customer_rewards)) { ?>
                            <tr>
                                <th style="width: 55%;"><?= lang(ucfirst($controller_name) . '.rewards_package') ?></th>
                                <th style="width: 45%; text-align: right;"><?= esc($customer_rewards['package_name']) ?></th>
                            </tr>
                            <tr>
                                <th style="width: 55%;"><?= lang('Customers.available_points') ?></th>
                                <th style="width: 45%; text-align: right;"><?= esc($customer_rewards['points']) ?></th>
                            </tr>
                        <?php } ?>
                    <?php endif; ?>
                    <tr>
                        <th style="width: 55%;"><?= lang(ucfirst($controller_name) . '.customer_total') ?></th>
                        <th style="width: 45%; text-align: right;"><?= to_currency($customer_total) ?></th>
                    </tr>
                    <?php if (!empty($mailchimp_info)) { ?>
                        <tr>
                            <th style="width: 55%;"><?= lang(ucfirst($controller_name) . '.customer_mailchimp_status') ?></th>
                            <th style="width: 45%; text-align: right;"><?= esc($mailchimp_info['status']) ?></th>
                        </tr>
                    <?php } ?>
                </table>

                <?= anchor(
                    "$controller_name/removeCustomer",
                    '<span class="glyphicon glyphicon-remove">&nbsp;</span>' . lang('Common.remove') . ' ' . lang('Customers.customer'),
                    ['class' => 'btn btn-danger btn-sm', 'id' => 'remove_customer_button', 'title' => lang('Common.remove') . ' ' . lang('Customers.customer')]
                )
                ?>
            <?php } else { ?>
                <div class="form-group" id="select_customer">
                    <label id="customer_label" for="customer" class="control-label" style="margin-bottom: 1em; margin-top: -1em;">
                        <?= lang(ucfirst($controller_name) . '.select_customer') . esc(" $customer_required") ?>
                    </label>
                    <?= form_input(['name' => 'customer', 'id' => 'customer', 'class' => 'form-control input-sm', 'value' => lang(ucfirst($controller_name) . '.start_typing_customer_name')]) ?>

                    <button class="btn btn-info btn-sm modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= "customers/view" ?>" title="<?= lang(ucfirst($controller_name) . ".new_customer") ?>">
                        <span class="glyphicon glyphicon-user">&nbsp;</span><?= lang(ucfirst($controller_name) . ".new_customer") ?>
                    </button>
                    <button class="btn btn-default btn-sm modal-dlg" id="show_keyboard_help" data-href="<?= esc("$controller_name/salesKeyboardHelp") ?>" title="<?= lang(ucfirst($controller_name) . '.key_title') ?>">
                        <span class="glyphicon glyphicon-share-alt">&nbsp;</span><?= lang(ucfirst($controller_name) . '.key_help') ?>
                    </button>
                </div>
            <?php } ?>
        <?= form_close() ?>

        <table class="sales_table_100" id="sale_totals">
            <tr>
                <th style="width: 55%;"><?= lang(ucfirst($controller_name) . '.quantity_of_items', [$item_count]) ?></th>
                <th style="width: 45%; text-align: right;"><?= $total_units ?></th>
            </tr>
            <tr>
                <th style="width: 55%;"><?= lang(ucfirst($controller_name) . '.sub_total') ?></th>
                <th style="width: 45%; text-align: right;"><?= to_currency($subtotal) ?></th>
            </tr>
            <?php foreach ($taxes as $tax_group_index => $tax) { ?>
                <tr>
                    <th style="width: 55%;"><?= (float)$tax['tax_rate'] . '% ' . esc($tax['tax_group']) ?></th>
                    <th style="width: 45%; text-align: right;"><?= to_currency_tax($tax['sale_tax_amount']) ?></th>
                </tr>
            <?php } ?>
            <tr>
                <th style="width: 55%; font-size: 150%"><?= lang(ucfirst($controller_name) . '.total') ?></th>
                <th style="width: 45%; font-size: 150%; text-align: right;"><span id="sale_total"><?= to_currency($total) ?></span></th>
            </tr>
        </table>

        <?php if (count($cart) > 0) { // Only show this part if there are Items already in the register ?>
            <table class="sales_table_100" id="payment_totals">
                <tr>
                    <th style="width: 55%;"><?= lang(ucfirst($controller_name) . '.payments_total') ?></th>
                    <th style="width: 45%; text-align: right;"><?= to_currency($payments_total) ?></th>
                </tr>
                <tr>
                    <th style="width: 55%; font-size: 120%"><?= lang(ucfirst($controller_name) . '.amount_due') ?></th>
                    <th style="width: 45%; font-size: 120%; text-align: right;"><span id="sale_amount_due"><?= to_currency($amount_due) ?></span></th>
                </tr>
            </table>

            <div id="payment_details">
                <?php if ($payments_cover_total) { // Show Complete sale button instead of Add Payment if there is no amount due left ?>
                    <?= form_open("$controller_name/addPayment", ['id' => 'add_payment_form', 'class' => 'form-horizontal']) ?>
                        <input type="hidden" name="complete_after_payment" value="0">
                        <table class="sales_table_100">
                            <tr>
                                <td><?= lang(ucfirst($controller_name) . '.payment') ?></td>
                                <td>
                                    <?= form_dropdown('payment_type', $payment_options, $selected_payment_type, ['id' => 'payment_types', 'class' => 'selectpicker show-menu-arrow', 'data-style' => 'btn-default btn-sm', 'data-width' => 'fit', 'disabled' => 'disabled']) ?>
                                </td>
                            </tr>
                            <tr>
                                <td><span id="amount_tendered_label"><?= lang(ucfirst($controller_name) . '.amount_tendered') ?></span></td>
                                <td>
                                    <?= form_input(['name' => 'amount_tendered', 'id' => 'amount_tendered', 'class' => 'form-control input-sm disabled', 'disabled' => 'disabled', 'value' => '0', 'size' => '5', 'tabindex' => ++$tabindex, 'onClick' => 'this.select();']) ?>
                                </td>
                            </tr>
                        </table>
                    <?= form_close() ?>

                    <?php
                    // Only show this part if in sale or return mode
                    if ($pos_mode) {
                        $due_payment = false;

                        if (count($payments) > 0) {
                            foreach ($payments as $payment_id => $payment) {
                                if ($payment['payment_type'] == lang(ucfirst($controller_name) . '.due')) {
                                    $due_payment = true;
                                }
                            }
                        }

                        if (!$due_payment || ($due_payment && isset($customer))) {    // TODO: $due_payment is not needed because the first clause insures that it will always be true if it gets to this point.  Can be shortened to if (!$due_payment || isset($customer))
                    ?>
                            <div class="btn btn-sm btn-success pull-right" id="finish_sale_button" tabindex="<?= ++$tabindex ?>">
                                <span class="glyphicon glyphicon-ok">&nbsp;</span><?= lang(ucfirst($controller_name) . '.complete_sale') ?>
                            </div>
                    <?php
                        }
                    }
                    ?>
                <?php } else { ?>
                    <?= form_open("$controller_name/addPayment", ['id' => 'add_payment_form', 'class' => 'form-horizontal']) ?>
                        <input type="hidden" name="complete_after_payment" value="0">
                        <table class="sales_table_100">
                            <tr>
                                <td><?= lang(ucfirst($controller_name) . '.payment') ?></td>
                                <td>
                                    <?= form_dropdown('payment_type', $payment_options,  $selected_payment_type, ['id' => 'payment_types', 'class' => 'selectpicker show-menu-arrow', 'data-style' => 'btn-default btn-sm', 'data-width' => 'fit']) ?>
                                </td>
                            </tr>
                            <tr>
                                <td><span id="amount_tendered_label"><?= lang(ucfirst($controller_name) . '.amount_tendered') ?></span></td>
                                <td>
                                    <?= form_input(['name' => 'amount_tendered', 'id' => 'amount_tendered', 'class' => 'form-control input-sm non-giftcard-input', 'value' => to_currency_no_money($amount_due), 'size' => '5', 'tabindex' => ++$tabindex, 'onClick' => 'this.select();']) ?>
                                    <?= form_input(['name' => 'amount_tendered', 'id' => 'amount_tendered', 'class' => 'form-control input-sm giftcard-input', 'disabled' => true, 'value' => to_currency_no_money($amount_due), 'size' => '5', 'tabindex' => ++$tabindex]) ?>
                                </td>
                            </tr>
                        </table>
                    <?= form_close() ?>

                    <div class="btn btn-sm btn-success pull-right" id="add_payment_button" tabindex="<?= ++$tabindex ?>">
                        <span class="glyphicon glyphicon-credit-card">&nbsp;</span><?= lang(ucfirst($controller_name) . '.add_payment') ?>
                    </div>
                <?php } ?>

                <?php if (count($payments) > 0) { // Only show this part if there is at least one payment entered. ?>
                    <table class="sales_table_100" id="register">
                        <thead>
                            <tr>
                                <th style="width: 10%;"><?= lang('Common.delete') ?></th>
                                <th style="width: 60%;"><?= lang(ucfirst($controller_name) . '.payment_type') ?></th>
                                <th style="width: 20%;"><?= lang(ucfirst($controller_name) . '.payment_amount') ?></th>
                            </tr>
                        </thead>

                        <tbody id="payment_contents">
                            <?php foreach ($payments as $payment_id => $payment) { ?>
                                <tr>
                                    <td><?= anchor("$controller_name/deletePayment/". esc(base64url_encode($payment_id), 'url'), '<span class="glyphicon glyphicon-trash"></span>') ?></td>
                                    <td><?= esc($payment['payment_type']) ?></td>
                                    <td style="text-align: right;"><?= to_currency($payment['payment_amount']) ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php } ?>
            </div>

            <?= form_open("$controller_name/cancel", ['id' => 'buttons_form']) ?>
            <div class="form-group" id="buttons_sale">
                <?php if (!($config['dinner_table_enable'] && (int) $selected_table > 2)) { ?>
                    <!-- Hidden for open table tabs: autosave already persists
                         the cart on every mutation (see
                         Sales::_autosave_open_tab()), and Sale::save_value()
                         occupies the dinner table for any non-COMPLETED
                         status including SUSPENDED without ever releasing it,
                         so suspending a table here would strand it as
                         occupied but invisible in the open tabs bar (only
                         Cancel/Complete correctly free it). See
                         docs/Tecnico/ventas-en-paralelo-pestanas.md
                         section 11. -->
                    <div class="btn btn-sm btn-default pull-left" id="suspend_sale_button"><span class="glyphicon glyphicon-align-justify">&nbsp;</span><?= lang(ucfirst($controller_name) . '.suspend_sale') ?></div>
                <?php } ?>
                <?php if (!$pos_mode && isset($customer)) { // Only show this part if the payment covers the total ?>
                    <div class="btn btn-sm btn-success" id="finish_invoice_quote_button"><span class="glyphicon glyphicon-ok">&nbsp;</span><?= esc($mode_label) ?></div>
                <?php } ?>

                <div class="btn btn-sm btn-danger pull-right" id="cancel_sale_button"><span class="glyphicon glyphicon-remove">&nbsp;</span><?= lang(ucfirst($controller_name) . '.cancel_sale') ?></div>
            </div>
            <?= form_close() ?>

            <?php if ($payments_cover_total || !$pos_mode) { // Only show this part if the payment cover the total ?>
                <div class="container-fluid">
                    <div class="no-gutter row">
                        <div class="form-group form-group-sm">
                            <div class="col-xs-12">
                                <?= form_label(lang('Common.comments'), 'comments', ['class' => 'control-label', 'id' => 'comment_label', 'for' => 'comment']) ?>
                                <?= form_textarea(['name' => 'comment', 'id' => 'comment', 'class' => 'form-control input-sm', 'value' => $comment, 'rows' => '2']) ?>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="form-group form-group-sm">
                            <div class="col-xs-6">
                                <label for="sales_print_after_sale" class="control-label checkbox">
                                    <?= form_checkbox(['name' => 'sales_print_after_sale', 'id' => 'sales_print_after_sale', 'value' => 1, 'checked' => $print_after_sale]) ?>
                                    <?= lang(ucfirst($controller_name) . '.print_after_sale') ?>
                                </label>
                            </div>

                            <?php if (!empty($customer_email)) { ?>
                                <div class="col-xs-6">
                                    <label for="email_receipt" class="control-label checkbox">
                                        <?= form_checkbox(['name' => 'email_receipt', 'id' => 'email_receipt', 'value' => 1, 'checked' => $email_receipt]) ?>
                                        <?= lang(ucfirst($controller_name) . '.email_receipt') ?>
                                    </label>
                                </div>
                            <?php } ?>
                            <?php if ($mode == 'sale_work_order') { ?>
                                <div class="col-xs-6">
                                    <label for="price_work_orders" class="control-label checkbox">
                                        <?= form_checkbox(['name' => 'price_work_orders', 'id' => 'price_work_orders', 'value' => 1, 'checked' => $price_work_orders]) ?>
                                        <?= lang(ucfirst($controller_name) . '.include_prices') ?>
                                    </label>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                    <?php if (($mode == 'sale_invoice') && $config['invoice_enable']) { ?>
                        <div class="row">
                            <div class="form-group form-group-sm">
                                <div class="col-xs-6">
                                    <label for="sales_invoice_number" class="control-label checkbox">
                                        <?= lang(ucfirst($controller_name) . '.invoice_enable') ?>
                                    </label>
                                </div>

                                <div class="col-xs-6">
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-addon input-sm">#</span>
                                        <?= form_input(['name' => 'sales_invoice_number', 'id' => 'sales_invoice_number', 'class' => 'form-control input-sm', 'value' => $invoice_number]) ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php } ?>
                </div>
        <?php
            }
        }
        ?>
    </div>
</div>

<style type="text/css">
    tr.kit-ingredient-row {
        display: none;
        color: #888;
        font-size: 0.9em;
    }
    a.kit-toggle {
        margin-left: 8px;
        white-space: nowrap;
    }

    /*
     * Weight entry. Sized for a finger on a touch terminal, which is why it
     * ignores the register's 13px mouse-era scale: this is the one thing on
     * the screen the cashier has to hit while somebody waits.
     */
    #weight_entry_panel {
        margin-top: 6px;
        margin-bottom: 6px;
    }

    #weight_entry_panel .panel-title {
        font-size: 16px;
    }

    .weight-entry-item-number {
        font-weight: normal;
        opacity: 0.8;
    }

    .weight-entry-layout {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        align-items: flex-start;
    }

    .weight-entry-main {
        flex: 1 1 260px;
        min-width: 0;
    }

    .weight-entry-label {
        display: block;
        font-size: 15px;
        margin-bottom: 4px;
    }

    input#weight {
        height: 64px;
        padding: 4px 12px;
        font-size: 32px;
        font-weight: bold;
        text-align: right;
    }

    .weight-entry-hint {
        margin: 8px 0 0;
        font-size: 13px;
    }

    .weight-entry-actions {
        display: flex;
        gap: 8px;
        margin-top: 12px;
    }

    .weight-entry-actions .btn {
        flex: 1 1 0;
        min-height: 56px;
        font-size: 16px;
        white-space: normal;
    }

    .weight-keypad {
        flex: 0 0 auto;
        display: grid;
        grid-template-columns: repeat(3, 76px);
        gap: 8px;
    }

    .weight-key {
        min-height: 60px;
        padding: 0;
        font-size: 22px;
        font-weight: bold;
    }

    .line-unit-of-measure {
        margin-left: 4px;
        font-size: 12px;
        white-space: nowrap;
    }

    /* Narrow terminals: the keypad drops below the field instead of squeezing it. */
    @media (max-width: 640px) {
        .weight-keypad {
            grid-template-columns: repeat(3, 1fr);
            width: 100%;
        }
    }
</style>
<script type="text/javascript">
    const keyboardShortcuts = <?= json_encode($keyboardShortcuts ?? []) ?>;
    const paymentsCoverTotal = <?= json_encode((bool) $payments_cover_total) ?>;
    const shortcutCodes = {
        items: keyboardShortcuts?.items?.code ?? null,
        customers: keyboardShortcuts?.customers?.code ?? null,
        suspend: keyboardShortcuts?.suspend?.code ?? null,
        suspended: keyboardShortcuts?.suspended?.code ?? null,
        amount: keyboardShortcuts?.amount?.code ?? null,
        payment: keyboardShortcuts?.payment?.code ?? null,
        complete: keyboardShortcuts?.complete?.code ?? null,
        finish: keyboardShortcuts?.finish?.code ?? null,
        help: keyboardShortcuts?.help?.code ?? null,
        cancel: keyboardShortcuts?.cancel?.code ?? null
    };

    $(document).ready(function() {
        const redirect = function() {
            window.location.href = "<?= site_url('sales'); ?>";
        };

        $("#remove_customer_button").click(function() {
            $.post("<?= site_url('sales/removeCustomer'); ?>", redirect);
        });

        $(document).on("click", ".kit-toggle", function(event) {
            event.preventDefault();
            var group = $(this).data("kit-group");
            $("tr.kit-ingredient-row[data-kit-group='" + group + "']").toggle();
            $(this).find(".glyphicon").toggleClass("glyphicon-triangle-right glyphicon-triangle-bottom");
        });

        $(".open_tab_button").click(function(event) {
            event.preventDefault();
            var tableId = $(this).data("dinner-table-id");
            var $select = $("select[name='dinner_table']");
            // The dropdown only lists empty tables (see $empty_tables in
            // Sales::_reload()), so a tab for an already-occupied table has
            // no matching <option> and .val() would silently no-op. Add a
            // throwaway option so the value survives form submission --
            // the page reloads right after, so no need to keep it in sync
            // with the selectpicker widget's own rendering.
            if ($select.find("option[value='" + tableId + "']").length === 0) {
                $select.append($("<option>").val(tableId));
            }
            $select.val(tableId);
            $("#mode_form").submit();
        });

        $("#new_table_button").click(function(event) {
            event.preventDefault();
            var name = prompt("<?= lang('Sales.new_table_prompt') ?>");
            if (name && name.trim() !== "") {
                $("#new_table_name").val(name.trim());
                $("#new_table_form").submit();
            }
        });

        $(".delete_item_button").click(function() {
            const item_id = $(this).data('item-id');
            $.post("<?= site_url('sales/deleteItem/'); ?>" + item_id, redirect);
        });

        $(".delete_payment_button").click(function() {
            const item_id = $(this).data('payment-id');
            $.post("<?= site_url('sales/deletePayment/'); ?>" + item_id, redirect);
        });

        $("input[name='item_number']").change(function() {
            var item_id = $(this).parents('tr').find("input[name='item_id']").val();
            var item_number = $(this).val();
            $.ajax({
                url: "<?= site_url('sales/changeItemNumber') ?>",
                method: 'post',
                data: {
                    'item_id': item_id,
                    'item_number': item_number,
                },
                dataType: 'json'
            });
        });

        $("input[name='name']").change(function() {
            var item_id = $(this).parents('tr').find("input[name='item_id']").val();
            var item_name = $(this).val();
            $.ajax({
                url: "<?= site_url('sales/changeItemName') ?>",
                method: 'post',
                data: {
                    'item_id': item_id,
                    'item_name': item_name,
                },
                dataType: 'json'
            });
        });

        $("input[name='item_description']").change(function() {
            var item_id = $(this).parents('tr').find("input[name='item_id']").val();
            var item_description = $(this).val();
            $.ajax({
                url: "<?= site_url('sales/changeItemDescription') ?>",
                method: 'post',
                data: {
                    'item_id': item_id,
                    'item_description': item_description,
                },
                dataType: 'json'
            });
        });

        // Where the cursor goes on load. An item priced by the kilo is scanned
        // and then weighed, so while one is waiting the weight field takes the
        // focus and the scale -- which in keyboard mode simply types where the
        // cursor is -- lands in the right place with nobody having to aim it.
        // With nothing waiting this is the scan field, exactly as before.
        var $weight_field = $('#weight');

        if ($weight_field.length) {
            $weight_field.focus();
        } else {
            $('#item').focus();
        }

        // The keypad is what keeps the shop open when the scale is unplugged
        // or the keyboard has been put away, so it edits the same field the
        // scale types into rather than a control of its own.
        $('#weight_entry_panel').on('click', '.weight-key', function() {
            var key = $(this).attr('data-weight-key');
            var value = String($weight_field.val());

            if (key === 'backspace') {
                value = value.slice(0, -1);
            } else if (key === 'separator') {
                // One separator only, and never leading: "0," reads as a
                // weight, "," on its own does not.
                if (value.indexOf('.') === -1 && value.indexOf(',') === -1) {
                    value = (value === '' ? '0' : value) + <?= json_encode($weight_decimal_separator ?? ',') ?>;
                }
            } else {
                value = value + key;
            }

            $weight_field.val(value);
            // Back to the field after every key, so the physical keyboard and
            // the on-screen one can be used in the same breath.
            $weight_field.focus();
        });

        // ============================================================
        // LA BÁSCULA, POR EL PROGRAMA LOCAL
        // ============================================================
        //
        // Solo se enciende cuando esta caja está configurada con transporte "agent" Y hay un
        // artículo esperando su peso. En cualquier otro negocio -- Casaletto entre ellos, que
        // trabaja en modo teclado -- este bloque no abre ninguna conexión ni existe para nada.
        (function () {
            var transporte = <?= json_encode($config['scale_transport'] ?? 'keys') ?>;

            if (transporte !== 'agent' || $weight_field.length === 0) {
                return;
            }

            // TRES LECTURAS QUE COINCIDAN, Y NO ES UN NÚMERO ELEGIDO A OJO
            //
            // Medido contra la báscula real de Paraíso (ROCHI RC-A01E, 2026-09-01): con el objeto
            // completamente quieto, 8 de cada 266 tramas -- el 3 % -- se desviaban entre 5 y 25
            // gramos, y la trama mala llega igual de bien formada que la buena. La racha máxima de
            // un valor equivocado fue de DOS, así que con dos no basta. Con tres, ningún valor
            // espurio pasó en ninguna de las dos capturas independientes.
            //
            // Cuesta ~1,7 s. Sin esto, una de cada 33 pesadas cobraría de más o de menos, en
            // silencio y sin error en ninguna parte.
            var LECTURAS_QUE_DEBEN_COINCIDIR = 3;

            // Se pregunta más seguido de lo que la báscula transmite (~565 ms) para que el peso
            // aparezca en cuanto se estabilice y no un tick tarde. Las respuestas repetidas no
            // cuentan: se descartan por su marca de tiempo, más abajo.
            var CADA_MS = 250;

            // Cuánto se espera antes de decir algo. El silencio es indistinguible de «funcionando»,
            // y en el mostrador eso es lo peor que puede pasar: el cajero no sabe si esperar o
            // digitar. Da para unas quince tramas, muy por encima de las tres que hacen falta.
            var PACIENCIA_MS = 9000;

            var url = 'ws://127.0.0.1:7878/ws';
            var socket = null;
            var timer = null;
            var vistas = [];
            var tomado = false;
            var manual = false;
            var llegoAlgo = false;
            var vigilante = null;

            var $estado = $('<p class="weight-entry-hint" id="weight_scale_status" aria-live="polite"></p>');
            $('#weight_entry_hint').after($estado);

            function decir(texto) {
                $estado.text(texto);
            }

            function parar() {
                if (timer) { clearInterval(timer); timer = null; }
                if (vigilante) { clearTimeout(vigilante); vigilante = null; }
            }

            // En cuanto el cajero escribe, la báscula deja de mandar. Pelear con la persona que
            // tiene la mercancía en la mano es peor que no ayudarla: el teclado y el teclado en
            // pantalla son la salida el día que la báscula falle.
            $weight_field.on('input', function () {
                if (!tomado) { manual = true; parar(); }
                tomado = false;
            });

            function pedir() {
                if (socket && socket.readyState === 1 && !manual) {
                    socket.send(JSON.stringify({ id: String(Date.now()), op: 'scale.read' }));
                }
            }

            function recibir(mensaje) {
                var d;
                try { d = JSON.parse(mensaje.data); } catch (e) { return; }

                if (d.op === 'error') {
                    parar();
                    // Los dos códigos se tratan distinto a propósito: una caja sin báscula es
                    // normal y el cajero solo tiene que digitar; una báscula que está y no
                    // contesta es una avería que alguien tiene que atender.
                    decir(d.code === 'sin_bascula'
                        ? <?= json_encode(Sale_lib::translate_or('Sales.scale_none', 'This till has no scale: type the weight.')) ?>
                        : <?= json_encode(Sale_lib::translate_or('Sales.scale_no_reading', 'The scale is not answering: type the weight.')) ?>);
                    return;
                }

                if (d.op !== 'scale.weight' || manual) {
                    return;
                }

                // UNA LECTURA SE CUENTA UNA SOLA VEZ, POR SU MARCA DE TIEMPO
                //
                // El agente guarda la última trama y la vuelve a entregar mientras siga fresca
                // (`frescura_ms`, 3 s por omisión), así que preguntando cada 250 ms la MISMA lectura
                // llega una docena de veces con la misma marca. Lo que hay que confirmar son tres
                // tramas que la báscula emitió POR SEPARADO, no tres respuestas a tres preguntas.
                //
                // La primera versión de esto exigía tres marcas distintas entre las últimas tres
                // respuestas, y con esa caché eso casi nunca ocurre: la caja se quedaba en «Leyendo
                // la báscula…» para siempre. Se vio en el mostrador, no en ninguna prueba.
                var at = String(d.at);

                if (vistas.length > 0 && vistas[vistas.length - 1].at === at) {
                    return;
                }

                llegoAlgo = true;
                vistas.push({ raw: String(d.raw), at: at });

                if (vistas.length > LECTURAS_QUE_DEBEN_COINCIDIR) { vistas.shift(); }
                if (vistas.length < LECTURAS_QUE_DEBEN_COINCIDIR) { return; }

                for (var i = 1; i < vistas.length; i++) {
                    if (vistas[i].raw !== vistas[0].raw) { return; }
                }

                parar();
                interpretar(vistas[0].raw);
            }

            // La trama la interpreta el SERVIDOR, no esta página: el formato vive en la
            // configuración del negocio, así que otra báscula se resuelve llenando una pantalla y
            // no reescribiendo este archivo.
            function interpretar(raw) {
                $.ajax({
                    url: '<?= esc(site_url("$controller_name/scaleWeight")) ?>',
                    type: 'POST',
                    data: { raw: raw },
                    dataType: 'json',
                    success: function (r) {
                        if (!r || !r.ok) {
                            decir(<?= json_encode(Sale_lib::translate_or('Sales.scale_unreadable', 'The scale sent something this till could not read: type the weight.')) ?>);
                            return;
                        }

                        // UN CERO NO ES UN PESO QUE SE PUEDA VENDER, ASI QUE SE SIGUE MIRANDO
                        //
                        // En un mostrador el cajero busca primero el producto y DESPUES lo pone en
                        // la báscula, así que la primera lectura estable es siempre el plato vacío.
                        // La primera versión de esto se quedaba con ese cero y dejaba de mirar: el
                        // cajero ponía la mercancía encima y el campo seguía en 0,000.
                        //
                        // Se vio en el mostrador con un melón, y antes con una impresora de 405 g.
                        if (parseFloat(r.weight) === 0) {
                            vistas = [];
                            // El cero prueba que la cadena entera funciona, así que el vigilante ya
                            // no tiene nada que vigilar: lo que falta es que alguien ponga algo.
                            if (vigilante) { clearTimeout(vigilante); vigilante = null; }
                            decir(<?= json_encode(Sale_lib::translate_or('Sales.scale_empty', 'The scale is at zero: place the product on it.')) ?>);

                            if (!timer && !manual) { timer = setInterval(pedir, CADA_MS); }

                            return;
                        }

                        // Se llena el campo y ahí se detiene. NO se envía la venta sola: quien
                        // confirma que ese es el peso de lo que está sobre el plato es la persona,
                        // y un botón de más cuesta menos que una línea cobrada por error.
                        tomado = true;
                        $weight_field.val(String(r.weight).replace('.', <?= json_encode($weight_decimal_separator ?? ',') ?>));
                        $weight_field.focus();
                        decir(<?= json_encode(Sale_lib::translate_or('Sales.scale_taken', 'Weight taken from the scale. Check it and add it to the sale.')) ?>);
                    },
                    error: function () {
                        decir(<?= json_encode(Sale_lib::translate_or('Sales.scale_unreadable', 'The scale sent something this till could not read: type the weight.')) ?>);
                    }
                });
            }

            try {
                socket = new WebSocket(url);
            } catch (e) {
                decir(<?= json_encode(Sale_lib::translate_or('Sales.scale_agent_down', 'The till program is not running: type the weight.')) ?>);
                return;
            }

            decir(<?= json_encode(Sale_lib::translate_or('Sales.scale_waiting', 'Reading the scale...')) ?>);

            socket.onopen = function () {
                timer = setInterval(pedir, CADA_MS);
                pedir();

                vigilante = setTimeout(function () {
                    if (tomado || manual) { return; }
                    parar();
                    // Se distingue «no llegó nada» de «llegaron lecturas y no se ponen de acuerdo»:
                    // la primera es una avería que alguien tiene que atender, la segunda es una
                    // balanza que se mueve, y el cajero hace cosas distintas en cada caso.
                    decir(llegoAlgo
                        ? <?= json_encode(Sale_lib::translate_or('Sales.scale_unstable', 'The weight is not settling: steady the item or type the weight.')) ?>
                        : <?= json_encode(Sale_lib::translate_or('Sales.scale_no_reading', 'The scale is not answering: type the weight.')) ?>);
                }, PACIENCIA_MS);
            };
            socket.onmessage = recibir;
            // Un agente caído es invisible para el cajero, que solo ve que no aparece el peso. Se
            // dice, y se dice con lo que tiene que hacer: digitarlo.
            socket.onerror = function () { parar(); decir(<?= json_encode(Sale_lib::translate_or('Sales.scale_agent_down', 'The till program is not running: type the weight.')) ?>); };
            socket.onclose = function () { parar(); };

            $(window).on('beforeunload', function () { parar(); if (socket) { socket.close(); } });
        })();

        $('#item').blur(function() {
            $(this).val("<?= lang(ucfirst($controller_name) . '.start_typing_item_name') ?>");
        });

        $('#item').autocomplete({
            source: "<?= esc("$controller_name/itemSearch") ?>",
            minChars: 0,
            autoFocus: false,
            delay: 500,
            select: function(a, ui) {
                $(this).val(ui.item.value);
                $('#add_item_form').submit();
                return false;
            }
        });

        $('#item').keypress(function(e) {
            if (e.which == 13) {
                $('#add_item_form').submit();
                return false;
            }
        });

        var clear_fields = function() {
            if ($(this).val().match("<?= lang(ucfirst($controller_name) . '.start_typing_item_name') . '|' . lang(ucfirst($controller_name) . '.start_typing_customer_name') ?>")) {
                $(this).val('');
            }
        };

        $('#item, #customer').click(clear_fields).dblclick(function(event) {
            $(this).autocomplete('search');
        });

        $('#customer').blur(function() {
            $(this).val("<?= lang(ucfirst($controller_name) . '.start_typing_customer_name') ?>");
        });

        $('#customer').autocomplete({
            source: "<?= site_url('customers/suggest') ?>",
            minChars: 0,
            delay: 10,
            select: function(a, ui) {
                $(this).val(ui.item.value);
                $('#select_customer_form').submit();
                return false;
            }
        });

        $('#customer').keypress(function(e) {
            if (e.which == 13) {
                $('#select_customer_form').submit();
                return false;
            }
        });

        $('.giftcard-input').autocomplete({
            source: "<?= site_url('giftcards/suggest') ?>",
            minChars: 0,
            delay: 10,
            select: function(a, ui) {
                $(this).val(ui.item.value);
                $('#add_payment_form').submit();
                return false;
            }
        });

        $('#comment').keyup(function() {
            $.post("<?= esc(site_url("$controller_name/setComment")) ?>", {
                comment: $('#comment').val()
            });
        });

        <?php if ($config['invoice_enable']) { ?>
            $('#sales_invoice_number').keyup(function() {
                $.post("<?= esc(site_url("$controller_name/setInvoiceNumber")) ?>", {
                    sales_invoice_number: $('#sales_invoice_number').val()
                });
            });

        <?php } ?>

        $('#sales_print_after_sale').change(function() {
            $.post("<?= esc(site_url("$controller_name/setPrintAfterSale")) ?>", {
                sales_print_after_sale: $(this).is(':checked')
            });
        });

        $('#price_work_orders').change(function() {
            $.post("<?= esc(site_url("$controller_name/setPriceWorkOrders")) ?>", {
                price_work_orders: $(this).is(':checked')
            });
        });

        $('#email_receipt').change(function() {
            $.post("<?= esc(site_url("$controller_name/setEmailReceipt")) ?>", {
                email_receipt: $(this).is(':checked')
            });
        });

        $('#finish_sale_button').click(function() {
            $('#buttons_form').attr('action', "<?= "$controller_name/complete" ?>");
            $('#buttons_form').submit();
        });

        $('#finish_invoice_quote_button').click(function() {
            $('#buttons_form').attr('action', "<?= "$controller_name/complete" ?>");
            $('#buttons_form').submit();
        });

        $('#suspend_sale_button').click(function() {
            $('#buttons_form').attr('action', "<?= site_url("$controller_name/suspend") ?>");
            $('#buttons_form').submit();
        });

        $('#cancel_sale_button').click(function() {
            if (confirm("<?= lang(ucfirst($controller_name) . '.confirm_cancel_sale') ?>")) {
                $('#buttons_form').attr('action', "<?= site_url("$controller_name/cancel") ?>");
                $('#buttons_form').submit();
            }
        });

        $('#add_payment_button').click(function() {
            $('#add_payment_form').find('input[name="complete_after_payment"]').val('0');
            $('#add_payment_form').submit();
        });

        $('#payment_types').change(check_payment_type).ready(check_payment_type);

        $('#cart_contents input').keypress(function(event) {
            if (event.which == 13) {
                $(this).parents('tr').prevAll('form:first').submit();
            }
        });

        $('#amount_tendered').keypress(function(event) {
            if (event.which == 13) {
                $('#add_payment_form').submit();
            }
        });

        $('#finish_sale_button').keypress(function(event) {
            if (event.which == 13) {
                $('#finish_sale_form').submit();
            }
        });

        dialog_support.init('a.modal-dlg, button.modal-dlg');

        table_support.handle_submit = function(resource, response, stay_open) {
            $.notify({
                message: response.message
            }, {
                type: response.success ? 'success' : 'danger'
            })

            if (response.success) {
                if (resource.match(/customers$/)) {
                    $('#customer').val(response.id);
                    $('#select_customer_form').submit();
                } else {
                    var $stock_location = $("select[name='stock_location']").val();
                    $('#item_location').val($stock_location);
                    // The token, not the bare id: this value is about to be posted into the same
                    // field a scanner writes to. See App\Models\Item::ID_TOKEN_PREFIX.
                    $('#item').val('<?= esc(Item::ID_TOKEN_PREFIX, 'js') ?>' + response.id);
                    if (stay_open) {
                        $('#add_item_form').ajaxSubmit();
                    } else {
                        $('#add_item_form').submit();
                    }
                }
            }
        }

        $('[name="price"],[name="quantity"],[name="discount"],[name="description"],[name="serialnumber"],[name="discounted_total"]').change(function() {
            $(this).parents('tr').prevAll('form:first').submit()
        });

        $('[name="discount_toggle"]').change(function() {
            var input = $('<input>').attr('type', 'hidden').attr('name', 'discount_type').val(($(this).prop('checked')) ? 1 : 0);
            $('#cart_' + $(this).attr('data-line')).append($(input));
            $('#cart_' + $(this).attr('data-line')).submit();
        });
    });

    function check_payment_type() {
        var cash_mode = <?= json_encode($cash_mode) ?>;

        if ($("#payment_types").val() == "<?= lang(ucfirst($controller_name) . '.giftcard') ?>") {
            $("#sale_total").html("<?= to_currency($total) ?>");
            $("#sale_amount_due").html("<?= to_currency($amount_due) ?>");
            $("#amount_tendered_label").html("<?= lang(ucfirst($controller_name) . '.giftcard_number') ?>");
            $("#amount_tendered:enabled").val('').focus();
            $(".giftcard-input").attr('disabled', false);
            $(".non-giftcard-input").attr('disabled', true);
            $(".giftcard-input:enabled").val('').focus();
        } else if (($("#payment_types").val() == "<?= lang(ucfirst($controller_name) . '.cash') ?>" && cash_mode == '1')) {
            $("#sale_total").html("<?= to_currency($non_cash_total) ?>");
            $("#sale_amount_due").html("<?= to_currency($cash_amount_due) ?>");
            $("#amount_tendered_label").html("<?= lang(ucfirst($controller_name) . '.amount_tendered') ?>");
            $("#amount_tendered:enabled").val("<?= to_currency_no_money($cash_amount_due) ?>");
            $(".giftcard-input").attr('disabled', true);
            $(".non-giftcard-input").attr('disabled', false);
        } else {
            $("#sale_total").html("<?= to_currency($non_cash_total) ?>");
            $("#sale_amount_due").html("<?= to_currency($amount_due) ?>");
            $("#amount_tendered_label").html("<?= lang(ucfirst($controller_name) . '.amount_tendered') ?>");
            $("#amount_tendered:enabled").val("<?= to_currency_no_money($amount_due) ?>");
            $(".giftcard-input").attr('disabled', true);
            $(".non-giftcard-input").attr('disabled', false);
        }
    }

    // Add Keyboard Shortcuts/Hotkeys to Sale Register
    document.body.onkeyup = function(event) {
        if ($(event.target).closest('.modal').length || $('.modal.in').length) {
            return;
        }
        if (event.altKey) {
            switch (event.keyCode) {
                case shortcutCodes.items:
                    $("#item").focus();
                    $("#item").select();
                    break;
                case shortcutCodes.customers:
                    $("#customer").focus();
                    $("#customer").select();
                    break;
                case shortcutCodes.suspend:
                    $("#suspend_sale_button").click();
                    break;
                case shortcutCodes.suspended:
                    $("#show_suspended_sales_button").click();
                    break;
                case shortcutCodes.amount:
                    $("#amount_tendered").focus();
                    $("#amount_tendered").select();
                    break;
                case shortcutCodes.payment:
                    $("#add_payment_button").click();
                    break;
                case shortcutCodes.complete:
                    if (paymentsCoverTotal && $("#finish_sale_button").length) {
                        $("#finish_sale_button").click();
                    } else {
                        $("#add_payment_button").click();
                    }
                    break;
                case shortcutCodes.finish:
                    $("#finish_invoice_quote_button").click();
                    break;
                case shortcutCodes.help:
                    $("#show_keyboard_help").click();
                    break;
            }
        }

        switch (event.keyCode) {
            case shortcutCodes.cancel:
                $("#cancel_sale_button").click();
                break;
        }
    }
</script>

<?= view('partial/footer') ?>
