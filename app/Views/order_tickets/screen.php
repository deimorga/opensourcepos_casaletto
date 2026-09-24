<?php
/**
 * The order-ticket screen. It IS the register's screen, minus the money.
 *
 * Same shared header (theme, menu, support banner), same register.css, and the same element ids --
 * #register_wrapper, #open_tabs_bar, #add_item_form, #register, #overall_sale, #sale_totals -- so it
 * looks and behaves like the sales screen the business already knows. What changes:
 *
 * - the tab bar lists live order tickets instead of open tables, and "+ Nueva comanda" opens one;
 * - the right-hand panel sends to the kitchen, marks delivered or cancels. It never charges: the
 *   register pulls the ticket and charges it there (docs/Tecnico/comandas-y-cuenta-abierta.md §0.1);
 * - it works on a phone: the header gets a viewport, and css/order_tickets.css stacks the two panels
 *   and turns the dish table into cards under 768px.
 *
 * Works without JavaScript: the search box falls back to a GET that lists the matches, and every
 * action is a plain form. The script at the bottom adds the register's live search, the name prompt
 * for a new ticket, and the offline notice -- the server depends on none of it.
 *
 * Every form carries the page's single-use request token (OrderTickets::refuse_repeated_submission()),
 * and the edit form carries what the screen showed (seen_quantity, seen_note) so a save cannot erase
 * a change made from another phone.
 *
 * @var list<array<string, mixed>>                   $tickets  live tickets of this site, for the bar
 * @var array<int, array{dishes: int, pending: int}> $counts
 * @var array<string, mixed>|null                    $ticket   the selected one, or null
 * @var bool                                         $live
 * @var list<array<string, mixed>>                   $lines
 * @var array<int, string>                           $item_numbers item_id => item_number
 * @var int                                          $pending
 * @var list<array<string, mixed>>                   $rounds
 * @var string                                       $term
 * @var list<array<string, mixed>>                   $results
 * @var bool                                         $can_cancel
 * @var string                                       $request_token
 */

use App\Libraries\Order_ticket_request_guard;
use App\Models\Order_ticket_line;

$token    = [Order_ticket_request_guard::FIELD => $request_token];
$selected = $ticket === null ? 0 : (int) $ticket['order_ticket_id'];

$success = session()->getFlashdata('success');
$warning = session()->getFlashdata('warning');
$error   = session()->getFlashdata('error');
?>

<?= view('partial/header') ?>

<div id="ot_screen">

<?php if ($error): ?>
    <div class="alert alert-dismissible alert-danger" role="alert"><?= esc($error) ?></div>
<?php endif; ?>
<?php if ($warning): ?>
    <div class="alert alert-dismissible alert-warning" role="alert"><?= esc($warning) ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-dismissible alert-success" role="status"><?= esc($success) ?></div>
<?php endif; ?>
<div class="alert alert-danger ot-offline" role="alert" hidden><?= esc(lang('Order_tickets.offline')) ?></div>

<div id="register_wrapper">

    <?php // The register's bar of open tabs, holding order tickets. ?>
    <div id="open_tabs_bar" class="panel panel-default">
        <div class="panel-body form-group" style="padding-bottom: 5px;">
            <ul class="nav nav-pills">
                <?php foreach ($tickets as $open): ?>
                    <?php
                    $open_id = (int) $open['order_ticket_id'];
                    $count   = $counts[$open_id] ?? ['dishes' => 0, 'pending' => 0];
                    ?>
                    <li class="<?= $open_id === $selected ? 'active' : '' ?>">
                        <a href="<?= esc(base_url('comandas/' . $open_id), 'attr') ?>" title="<?= esc(lang('Order_tickets.dishes', [$count['dishes']]) . ' · ' . lang('Order_tickets.opened_at', [date('H:i', strtotime((string) $open['opened_at']))])) ?>">
                            <span class="glyphicon glyphicon-cutlery">&nbsp;</span><?= esc($open['name']) ?>
                            <?php if ($count['pending'] > 0): ?>
                                <span class="badge" title="<?= esc(lang('Order_tickets.pending', [$count['pending']])) ?>"><?= (int) $count['pending'] ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
                <li>
                    <a href="<?= esc(base_url('comandas/nueva'), 'attr') ?>" id="new_ticket_button" title="<?= esc(lang('Order_tickets.new_ticket'), 'attr') ?>">
                        <span class="glyphicon glyphicon-plus">&nbsp;</span><?= esc(lang('Order_tickets.new_ticket')) ?>
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <?php // "+ Nueva comanda" asks for the name and posts here, as the register's "+ Nueva mesa" does. ?>
    <?= form_open('comandas/crear', ['id' => 'new_ticket_form'], $token) ?>
        <input type="hidden" name="name" id="new_ticket_name">
    <?= form_close() ?>

    <?php if ($ticket === null): ?>

        <table class="sales_table_100" id="register">
            <tbody>
                <tr>
                    <td>
                        <div class="alert alert-info">
                            <?= esc(lang($tickets === [] ? 'Order_tickets.no_tickets' : 'Order_tickets.pick_ticket')) ?>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>

    <?php else: ?>

        <?php if ($live): ?>
            <?php
            // Without JavaScript this is a GET that reloads with the matches listed below. With it,
            // the box is the register's live search and picking an item posts #ot_add_form.
            ?>
            <form id="add_item_form" class="form-horizontal panel panel-default" method="get" action="<?= esc(base_url('comandas/' . $selected), 'attr') ?>" role="search">
                <div class="panel-body form-group">
                    <ul>
                        <li class="pull-left first_li">
                            <label for="item" class="control-label"><?= lang('Sales.find_or_scan_item') ?></label>
                        </li>
                        <li class="pull-left ot-search-field">
                            <input type="search" name="q" id="item" class="form-control input-sm" size="50" autocomplete="off" enterkeyhint="search"
                                   value="<?= esc($term, 'attr') ?>" placeholder="<?= esc(lang('Order_tickets.search_placeholder'), 'attr') ?>">
                            <span class="ui-helper-hidden-accessible" role="status"></span>
                        </li>
                        <li class="pull-right">
                            <button type="submit" class="btn btn-default btn-sm"><span class="glyphicon glyphicon-search">&nbsp;</span><?= esc(lang('Order_tickets.search')) ?></button>
                        </li>
                    </ul>
                </div>
            </form>

            <?= form_open('comandas/' . $selected . '/linea', ['id' => 'ot_add_form'], $token) ?>
                <input type="hidden" name="item_id" id="ot_add_item_id">
                <input type="hidden" name="quantity" value="1">
            <?= form_close() ?>

            <?php if ($term !== ''): ?>
                <?php // #ot-results: adding a dish from here comes back to the same search (OrderTickets::back_to()). ?>
                <div class="panel panel-default" id="ot-results">
                    <div class="panel-body">
                        <?php if ($results === []): ?>
                            <div class="alert alert-info"><?= esc(lang('Order_tickets.no_results', [$term])) ?></div>
                        <?php else: ?>
                            <table class="sales_table_100 ot-results-table">
                                <?php foreach ($results as $item): ?>
                                    <tr>
                                        <td class="ot-results-name"><?= esc($item['name']) ?></td>
                                        <td class="ot-results-price"><?= esc(to_currency((string) $item['unit_price'])) ?></td>
                                        <td class="ot-results-add">
                                            <?= form_open('comandas/' . $selected . '/linea', ['data-once' => '1', 'class' => 'ot-inline-form'], $token) ?>
                                                <input type="hidden" name="item_id" value="<?= (int) $item['item_id'] ?>">
                                                <input type="hidden" name="q" value="<?= esc($term, 'attr') ?>">
                                                <input type="number" name="quantity" value="1" min="0.001" step="any" inputmode="decimal" class="form-control input-sm ot-qty"
                                                       aria-label="<?= esc(lang('Sales.quantity'), 'attr') ?>">
                                                <input type="text" name="kitchen_note" maxlength="255" autocomplete="off" class="form-control input-sm ot-note"
                                                       placeholder="<?= esc(lang('Order_tickets.kitchen_note_placeholder'), 'attr') ?>"
                                                       aria-label="<?= esc(lang('Order_tickets.kitchen_note_placeholder'), 'attr') ?>">
                                                <button class="btn btn-info btn-sm" type="submit"><span class="glyphicon glyphicon-plus">&nbsp;</span><?= esc(lang('Order_tickets.add')) ?></button>
                                            <?= form_close() ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php
        // The register's dish table. Each row's inputs belong to a form outside the table through
        // the `form` attribute: a <form> cannot wrap a <tr>. The edit form also carries what this
        // screen showed (seen_*), so the save is refused if another phone changed the dish since.
        ?>
        <table class="sales_table_100" id="register">
            <thead>
                <tr>
                    <th style="width: 5%;"><?= lang('Order_tickets.void_line') ?></th>
                    <th style="width: 13%;" class="ot-col-number"><?= lang('Sales.item_number') ?></th>
                    <th style="width: 34%;"><?= lang('Sales.item_name') ?></th>
                    <th style="width: 12%;"><?= lang('Sales.price') ?></th>
                    <th style="width: 12%;"><?= lang('Sales.quantity') ?></th>
                    <th style="width: 14%;"><?= lang('Sales.total') ?></th>
                    <th style="width: 10%;"><?= lang('Sales.update') ?></th>
                </tr>
            </thead>
            <tbody id="cart_contents">
                <?php if ($lines === []): ?>
                    <tr>
                        <td colspan="7">
                            <div class="alert alert-info"><?= esc(lang('Order_tickets.no_dishes')) ?></div>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($lines as $line): ?>
                    <?php
                    $line_id  = (int) $line['order_ticket_line_id'];
                    $voided   = $line['status'] === Order_ticket_line::STATUS_VOIDED;
                    $sent     = ! $voided && $line['round_id'] !== null;
                    $editable = $live && ! $voided;
                    $edit_id  = 'ot_edit_' . $line_id;
                    $void_id  = 'ot_void_' . $line_id;
                    $quantity = Order_ticket_line::display_quantity((string) $line['quantity']);
                    ?>
                    <tr class="ot-line<?= $voided ? ' ot-line-voided' : '' ?><?= $sent ? ' ot-line-sent' : '' ?>">
                        <td class="ot-cell-void">
                            <?php if ($editable): ?>
                                <button type="submit" form="<?= $void_id ?>" class="btn btn-link ot-icon-button" title="<?= esc(lang('Order_tickets.void_line'), 'attr') ?>"
                                        aria-label="<?= esc(lang('Order_tickets.void_line') . ': ' . $line['item_name'], 'attr') ?>">
                                    <span class="glyphicon glyphicon-trash"></span>
                                </button>
                            <?php endif; ?>
                        </td>
                        <td class="ot-col-number"><?= esc($item_numbers[(int) $line['item_id']] ?? '') ?></td>
                        <td class="ot-cell-name">
                            <span class="ot-item-name"><?= esc($line['item_name']) ?></span>
                            <span class="ot-line-state">
                                <?php if ($voided): ?>
                                    <span class="label label-default"><?= esc(lang('Order_tickets.line_status_voided')) ?></span>
                                <?php elseif ($sent): ?>
                                    <span class="label label-info"><?= esc(lang('Order_tickets.line_status_sent')) ?></span>
                                <?php else: ?>
                                    <span class="label label-warning"><?= esc(lang('Order_tickets.line_status_pending')) ?></span>
                                <?php endif; ?>
                                <?php if ((int) $line['changed_after_send'] === 1): ?>
                                    <span class="label label-danger"><?= esc(lang('Order_tickets.changed_after_send')) ?></span>
                                <?php endif; ?>
                            </span>
                            <?php if ($editable): ?>
                                <input type="text" name="kitchen_note" form="<?= $edit_id ?>" maxlength="255" autocomplete="off" class="form-control input-sm ot-note"
                                       value="<?= esc((string) $line['kitchen_note'], 'attr') ?>"
                                       placeholder="<?= esc(lang('Order_tickets.kitchen_note_placeholder'), 'attr') ?>"
                                       aria-label="<?= esc(lang('Order_tickets.kitchen_note_placeholder') . ': ' . $line['item_name'], 'attr') ?>">
                            <?php elseif ((string) $line['kitchen_note'] !== ''): ?>
                                <span class="ot-note-text">&raquo; <?= esc($line['kitchen_note']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="ot-cell-price" data-label="<?= esc(lang('Sales.price'), 'attr') ?>"><?= esc(to_currency((string) $line['unit_price'])) ?></td>
                        <td class="ot-cell-qty" data-label="<?= esc(lang('Sales.quantity'), 'attr') ?>">
                            <?php if ($editable): ?>
                                <input type="number" name="quantity" form="<?= $edit_id ?>" value="<?= esc($quantity, 'attr') ?>" min="0.001" step="any" inputmode="decimal"
                                       class="form-control input-sm ot-qty" aria-label="<?= esc(lang('Sales.quantity') . ': ' . $line['item_name'], 'attr') ?>">
                            <?php else: ?>
                                <?= esc($quantity) ?>
                            <?php endif; ?>
                        </td>
                        <td class="ot-cell-total" data-label="<?= esc(lang('Sales.total'), 'attr') ?>"><?= esc(to_currency(bcmul((string) $line['quantity'], (string) $line['unit_price'], 2))) ?></td>
                        <td class="ot-cell-update">
                            <?php if ($editable): ?>
                                <button type="submit" form="<?= $edit_id ?>" class="btn btn-link ot-icon-button" title="<?= esc(lang('Sales.update'), 'attr') ?>"
                                        aria-label="<?= esc(lang('Sales.update') . ': ' . $line['item_name'], 'attr') ?>">
                                    <span class="glyphicon glyphicon-refresh"></span>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php // The forms the table's inputs point at. Outside the table, as HTML requires. ?>
        <?php foreach ($lines as $line): ?>
            <?php if ($live && $line['status'] !== Order_ticket_line::STATUS_VOIDED): ?>
                <?php $line_id = (int) $line['order_ticket_line_id']; ?>
                <?= form_open('comandas/' . $selected . '/linea/' . $line_id, ['id' => 'ot_edit_' . $line_id, 'data-once' => '1'], $token + [
                    'seen_quantity' => (string) $line['quantity'],
                    'seen_note'     => (string) $line['kitchen_note'],
                ]) ?>
                <?= form_close() ?>
                <?= form_open('comandas/' . $selected . '/linea/' . $line_id . '/anular', ['id' => 'ot_void_' . $line_id, 'data-once' => '1', 'data-confirm' => lang('Order_tickets.void_line') . ': ' . $line['item_name'] . '?'], $token) ?>
                <?= form_close() ?>
            <?php endif; ?>
        <?php endforeach; ?>

    <?php endif; ?>
</div>

<?php if ($ticket !== null): ?>
    <?php
    $active_lines = array_filter($lines, static fn (array $line): bool => $line['status'] !== Order_ticket_line::STATUS_VOIDED);
    $units        = array_reduce($active_lines, static fn (string $sum, array $line): string => bcadd($sum, (string) $line['quantity'], 3), '0');
    $total        = array_reduce($active_lines, static fn (string $sum, array $line): string => bcadd($sum, bcmul((string) $line['quantity'], (string) $line['unit_price'], 2), 2), '0');
    ?>
    <div id="overall_sale" class="panel panel-default">
        <div class="panel-body">
            <table class="sales_table_100 ot-ticket-head">
                <tr>
                    <th style="width: 55%;"><?= esc(lang('Order_tickets.name')) ?></th>
                    <th style="width: 45%; text-align: right;"><?= esc($ticket['name']) ?></th>
                </tr>
                <tr>
                    <th><?= esc(lang('Order_tickets.status')) ?></th>
                    <th style="text-align: right;">
                        <span class="label label-<?= $ticket['status'] === 'delivered' ? 'success' : ($live ? 'primary' : 'default') ?>"><?= esc(lang('Order_tickets.status_' . $ticket['status'])) ?></span>
                        <small><?= esc(lang('Order_tickets.opened_at', [date('H:i', strtotime((string) $ticket['opened_at']))])) ?></small>
                    </th>
                </tr>
                <?php if ((string) $ticket['note'] !== ''): ?>
                    <tr>
                        <td colspan="2" class="ot-ticket-note"><?= esc($ticket['note']) ?></td>
                    </tr>
                <?php endif; ?>
            </table>

            <table class="sales_table_100" id="sale_totals">
                <tr>
                    <th style="width: 55%;"><?= lang('Sales.quantity_of_items', [count($active_lines)]) ?></th>
                    <th style="width: 45%; text-align: right;"><?= esc(Order_ticket_line::display_quantity($units)) ?></th>
                </tr>
                <tr>
                    <th style="width: 55%; font-size: 150%"><?= lang('Sales.total') ?></th>
                    <th style="width: 45%; font-size: 150%; text-align: right;"><span id="sale_total"><?= esc(to_currency($total)) ?></span></th>
                </tr>
            </table>

            <?php if ($live): ?>
                <div id="ot_actions">
                    <?= form_open('comandas/' . $selected . '/enviar', ['data-once' => '1'], $token) ?>
                        <button class="btn btn-success btn-block" type="submit" id="ot_send_button" <?= $pending === 0 ? 'disabled data-disabled-by-server' : '' ?>>
                            <span class="glyphicon glyphicon-cutlery">&nbsp;</span><?= esc(lang('Order_tickets.send_to_kitchen', [$pending])) ?>
                        </button>
                    <?= form_close() ?>

                    <?php if ($ticket['status'] === 'open'): ?>
                        <?= form_open('comandas/' . $selected . '/entregada', ['data-once' => '1'], $token) ?>
                            <button class="btn btn-default btn-block" type="submit">
                                <span class="glyphicon glyphicon-ok">&nbsp;</span><?= esc(lang('Order_tickets.mark_delivered')) ?>
                            </button>
                        <?= form_close() ?>
                    <?php endif; ?>

                    <?php if ($can_cancel): ?>
                        <?= form_open('comandas/' . $selected . '/cancelar', ['data-once' => '1', 'class' => 'ot-cancel-form'], $token) ?>
                            <label for="ot_cancel_reason" class="control-label"><?= esc(lang('Order_tickets.cancel_reason')) ?></label>
                            <input type="text" class="form-control input-sm" id="ot_cancel_reason" name="reason" maxlength="255" required autocomplete="off">
                            <button class="btn btn-danger btn-block" type="submit">
                                <span class="glyphicon glyphicon-remove">&nbsp;</span><?= esc(lang('Order_tickets.cancel_ticket')) ?>
                            </button>
                        <?= form_close() ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($rounds !== []): ?>
                <table class="sales_table_100" id="payment_totals">
                    <tr>
                        <th colspan="3"><?= esc(lang('Order_tickets.rounds_title')) ?></th>
                    </tr>
                    <?php foreach ($rounds as $round): ?>
                        <?php $round_url = 'comandas/' . $selected . '/ronda/' . (int) $round['round_id']; ?>
                        <tr>
                            <td class="ot-round-name">
                                <?= esc(lang('Order_tickets.round_n', [(int) $round['number']])) ?> &middot; <?= esc(date('H:i', strtotime((string) $round['sent_at']))) ?>
                                <?php // "Not printed" is a real state: sent from a phone, the paper comes out at the till (§8.3). ?>
                                <br><small><?= esc(lang($round['printed_at'] === null ? 'Order_tickets.not_printed' : 'Order_tickets.printed')) ?></small>
                            </td>
                            <td><a class="btn btn-default btn-sm" href="<?= esc(base_url($round_url), 'attr') ?>"><?= esc(lang('Order_tickets.view')) ?></a></td>
                            <td><a class="btn btn-primary btn-sm" href="<?= esc(base_url($round_url . '?imprimir=1'), 'attr') ?>" target="_blank" rel="noopener"><span class="glyphicon glyphicon-print">&nbsp;</span><?= esc(lang('Order_tickets.print')) ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

</div>

<script type="text/javascript">
    // Progressive enhancement only. Without this file the screen still works: the search is a GET,
    // "+ Nueva comanda" is a link to the form, and a resubmitted form is refused by its single-use
    // token on the server.
    $(document).ready(function() {
        var offline = document.querySelector('.ot-offline');

        // The register's live search (sales/register.php), fed by comandas/buscar. Picking an item
        // adds it at once, as picking one in the register does.
        if ($('#item').length && $.fn.autocomplete) {
            $('#item').autocomplete({
                source: "<?= esc(base_url('comandas/buscar'), 'js') ?>",
                // Inside the screen, so css/order_tickets.css can keep it within a phone's width.
                appendTo: '#ot_screen',
                minLength: 1,
                autoFocus: false,
                delay: 300,
                select: function(event, ui) {
                    $('#ot_add_item_id').val(ui.item.value);
                    $(this).val(ui.item.label);
                    $('#ot_add_form').submit();
                    return false;
                },
                focus: function() {
                    return false;
                }
            });

            <?php if (($term ?? '') === ''): ?>
                // Back from adding a dish, the cursor is where the next one is typed. Not on a phone:
                // focusing would throw the keyboard over the dishes just added.
                if (window.matchMedia('(min-width: 768px)').matches) {
                    $('#item').focus();
                }
            <?php endif; ?>
        }

        // "+ Nueva comanda": the name, then straight in -- the register's "+ Nueva mesa".
        $('#new_ticket_button').on('click', function(event) {
            var name = window.prompt(<?= json_encode(lang('Order_tickets.name') . ' — ' . lang('Order_tickets.name_help')) ?>);

            if (name === null) {
                event.preventDefault();
                return;
            }

            if ($.trim(name) === '') {
                // Nothing typed: the full form explains what is needed.
                return;
            }

            event.preventDefault();
            $('#new_ticket_name').val($.trim(name));
            $('#new_ticket_form').submit();
        });

        $('form[data-confirm]').on('submit', function(event) {
            if (!window.confirm($(this).data('confirm'))) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        });

        $('form[data-once], #ot_add_form, #new_ticket_form').on('submit', function(event) {
            // No signal: say so in words and send nothing, instead of the browser's own error page.
            if (navigator.onLine === false) {
                event.preventDefault();
                offline.hidden = false;
                offline.scrollIntoView({ block: 'center' });
                return;
            }

            offline.hidden = true;

            // Disabled while the request travels, so a double tap does not queue a second one. The
            // row buttons live outside their form (form="..."), so they are found by that attribute.
            $(this).find('button[type="submit"]').add('button[form="' + this.id + '"]').prop('disabled', true);
        });

        // Back to this page (the back button, or a restore after a failed request): no button may stay
        // disabled, except the send button the server drew disabled because there is nothing to send.
        window.addEventListener('pageshow', function() {
            $('button[type="submit"]').each(function() {
                this.disabled = this.hasAttribute('data-disabled-by-server');
            });
        });

        window.addEventListener('online', function() {
            offline.hidden = true;
        });
    });
</script>

<?= view('partial/footer') ?>
