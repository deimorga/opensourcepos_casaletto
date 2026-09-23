<?php
/**
 * One order ticket, on the waiter's phone.
 *
 * Works with no JavaScript at all, on purpose: the search is a GET that reloads the page, and editing
 * or voiding a dish lives in a <details> element. The waiter can lose signal or reload at any moment,
 * and the page is only ever the state of the database. The one script at the bottom is progressive
 * enhancement -- it disables a button while its request travels -- and the server does not depend on
 * it: sending twice is refused by Order_ticket_round::send() itself.
 *
 * @var array<string, mixed>             $ticket
 * @var bool                             $live       open or delivered: still accepts dishes
 * @var list<array<string, mixed>>       $lines      every line, voided ones included (struck through)
 * @var int                              $pending    what the next send would carry
 * @var list<array<string, mixed>>       $rounds
 * @var string                           $term       what was typed in the search box
 * @var list<array<string, mixed>>       $results
 * @var bool                             $can_cancel
 * @var string                           $request_token single-use, see OrderTickets::refuse_repeated_submission()
 */
$this->extend('order_tickets/layout');
$this->section('content');

$id = (int) $ticket['order_ticket_id'];
?>

<p class="mb-3">
    <span class="badge text-bg-<?= $ticket['status'] === 'delivered' ? 'success' : ($live ? 'secondary' : 'dark') ?>">
        <?= esc(lang('Order_tickets.status_' . $ticket['status'])) ?>
    </span>
    <small class="text-body-secondary ms-1"><?= esc(lang('Order_tickets.opened_at', [date('H:i', strtotime((string) $ticket['opened_at']))])) ?></small>
    <?php if ((string) $ticket['note'] !== ''): ?>
        <span class="d-block mt-1"><?= esc($ticket['note']) ?></span>
    <?php endif; ?>
</p>

<?php if ($live): ?>
    <form method="get" action="<?= esc(base_url('comandas/' . $id), 'attr') ?>" role="search" class="d-flex gap-2 mb-3">
        <input class="form-control" type="search" name="q" value="<?= esc($term, 'attr') ?>" enterkeyhint="search"
               placeholder="<?= esc(lang('Order_tickets.search_placeholder'), 'attr') ?>"
               aria-label="<?= esc(lang('Order_tickets.search_placeholder'), 'attr') ?>" autocomplete="off">
        <button class="btn btn-outline-primary" type="submit"><?= esc(lang('Order_tickets.search')) ?></button>
    </form>

    <?php if ($term !== ''): ?>
        <?php if ($results === []): ?>
            <p class="text-body-secondary"><?= esc(lang('Order_tickets.no_results', [$term])) ?></p>
        <?php else: ?>
            <?php // #ot-results: adding a dish comes back here, to the same search (OrderTickets::back_to()). ?>
            <ul class="ot-list" id="ot-results">
                <?php foreach ($results as $item): ?>
                    <li class="ot-list-item d-block">
                        <?= form_open('comandas/' . $id . '/linea', ['data-once' => '1'], [\App\Libraries\Order_ticket_request_guard::FIELD => $request_token]) ?>
                            <input type="hidden" name="item_id" value="<?= (int) $item['item_id'] ?>">
                            <input type="hidden" name="q" value="<?= esc($term, 'attr') ?>">
                            <div class="d-flex justify-content-between gap-2">
                                <strong><?= esc($item['name']) ?></strong>
                                <span class="text-nowrap"><?= esc(to_currency((string) $item['unit_price'])) ?></span>
                            </div>
                            <div class="d-flex gap-2 mt-2">
                                <input class="form-control" style="max-width: 6rem" type="number" name="quantity" value="1"
                                       min="0.001" step="any" inputmode="decimal"
                                       aria-label="<?= esc(lang('Order_tickets.quantity'), 'attr') ?>">
                                <input class="form-control" type="text" name="kitchen_note" maxlength="255" autocomplete="off"
                                       placeholder="<?= esc(lang('Order_tickets.kitchen_note_placeholder'), 'attr') ?>"
                                       aria-label="<?= esc(lang('Order_tickets.kitchen_note_placeholder'), 'attr') ?>">
                                <button class="btn btn-primary" type="submit"><?= esc(lang('Order_tickets.add')) ?></button>
                            </div>
                        <?= form_close() ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<h2 class="h6 mt-4"><?= esc(lang('Order_tickets.dishes_title')) ?></h2>

<?php if ($lines === []): ?>
    <p class="text-body-secondary"><?= esc(lang('Order_tickets.no_dishes')) ?></p>
<?php else: ?>
    <ul class="ot-list">
        <?php foreach ($lines as $line): ?>
            <?php
            $line_id = (int) $line['order_ticket_line_id'];
            $voided  = $line['status'] === 'voided';
            $sent    = ! $voided && $line['round_id'] !== null;
            $class   = $voided ? 'ot-line-voided' : ($sent ? 'ot-line-sent' : '');
            ?>
            <li class="ot-list-item d-block <?= $class ?>">
                <div class="d-flex justify-content-between gap-2">
                    <span><strong><?= esc(\App\Models\Order_ticket_line::display_quantity((string) $line['quantity'])) ?> &times;</strong> <?= esc($line['item_name']) ?></span>
                    <span class="text-nowrap"><?= esc(to_currency(bcmul((string) $line['quantity'], (string) $line['unit_price'], 2))) ?></span>
                </div>
                <?php if ((string) $line['kitchen_note'] !== ''): ?>
                    <small class="d-block">&raquo; <?= esc($line['kitchen_note']) ?></small>
                <?php endif; ?>
                <div class="mt-1">
                    <?php if ($voided): ?>
                        <small class="ot-line-status"><?= esc(lang('Order_tickets.line_status_voided')) ?></small>
                    <?php elseif ($sent): ?>
                        <small class="ot-line-status"><?= esc(lang('Order_tickets.line_status_sent')) ?></small>
                    <?php endif; ?>
                    <?php if ((int) $line['changed_after_send'] === 1): ?>
                        <span class="ot-badge-changed"><?= esc(lang('Order_tickets.changed_after_send')) ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($live && ! $voided): ?>
                    <details class="mt-2">
                        <summary><?= esc(lang('Order_tickets.edit')) ?></summary>
                        <?php // seen_*: what this screen showed, so a save cannot erase a change it never saw. ?>
                        <?= form_open('comandas/' . $id . '/linea/' . $line_id, ['class' => 'd-flex gap-2 mt-2', 'data-once' => '1'], [
                            \App\Libraries\Order_ticket_request_guard::FIELD => $request_token,
                            'seen_quantity'                                   => (string) $line['quantity'],
                            'seen_note'                                       => (string) $line['kitchen_note'],
                        ]) ?>
                            <input class="form-control" style="max-width: 6rem" type="number" name="quantity"
                                   value="<?= esc((string) (float) $line['quantity'], 'attr') ?>" min="0.001" step="any" inputmode="decimal"
                                   aria-label="<?= esc(lang('Order_tickets.quantity'), 'attr') ?>">
                            <input class="form-control" type="text" name="kitchen_note" maxlength="255" autocomplete="off"
                                   value="<?= esc((string) $line['kitchen_note'], 'attr') ?>"
                                   aria-label="<?= esc(lang('Order_tickets.kitchen_note_placeholder'), 'attr') ?>">
                            <button class="btn btn-outline-primary" type="submit"><?= esc(lang('Order_tickets.save')) ?></button>
                        <?= form_close() ?>
                        <?= form_open('comandas/' . $id . '/linea/' . $line_id . '/anular', ['class' => 'mt-2', 'data-once' => '1'], [\App\Libraries\Order_ticket_request_guard::FIELD => $request_token]) ?>
                            <button class="btn btn-outline-danger" type="submit"><?= esc(lang('Order_tickets.void_line')) ?></button>
                        <?= form_close() ?>
                    </details>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($rounds !== []): ?>
    <h2 class="h6 mt-4"><?= esc(lang('Order_tickets.rounds_title')) ?></h2>
    <ul class="ot-list">
        <?php foreach ($rounds as $round): ?>
            <?php $round_url = 'comandas/' . $id . '/ronda/' . (int) $round['round_id']; ?>
            <li class="ot-list-item">
                <span>
                    <strong><?= esc(lang('Order_tickets.round_n', [(int) $round['number']])) ?></strong>
                    <small class="text-body-secondary">&middot; <?= esc(date('H:i', strtotime((string) $round['sent_at']))) ?></small>
                    <?php // "Not printed" is a real state: sent from a phone, the paper comes out at the till (§8.3). ?>
                    <small class="d-block <?= $round['printed_at'] === null ? 'fw-bold' : 'text-body-secondary' ?>">
                        <?= esc(lang($round['printed_at'] === null ? 'Order_tickets.not_printed' : 'Order_tickets.printed')) ?>
                    </small>
                </span>
                <span class="d-flex gap-2">
                    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url($round_url), 'attr') ?>"><?= esc(lang('Order_tickets.view')) ?></a>
                    <a class="btn btn-outline-primary btn-sm" href="<?= esc(base_url($round_url . '?imprimir=1'), 'attr') ?>" target="_blank" rel="noopener"><?= esc(lang('Order_tickets.print')) ?></a>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($live): ?>
    <div class="d-grid gap-2 mt-4">
        <?php if ($ticket['status'] === 'open'): ?>
            <?= form_open('comandas/' . $id . '/entregada', ['data-once' => '1', 'class' => 'd-grid'], [\App\Libraries\Order_ticket_request_guard::FIELD => $request_token]) ?>
                <button class="btn btn-outline-success" type="submit"><?= esc(lang('Order_tickets.mark_delivered')) ?></button>
            <?= form_close() ?>
        <?php endif; ?>

        <?php if ($can_cancel): ?>
            <details>
                <summary class="text-danger"><?= esc(lang('Order_tickets.cancel_ticket')) ?></summary>
                <?= form_open('comandas/' . $id . '/cancelar', ['class' => 'd-grid gap-2 mt-2', 'data-once' => '1'], [\App\Libraries\Order_ticket_request_guard::FIELD => $request_token]) ?>
                    <label class="form-label" for="ot-cancel-reason"><?= esc(lang('Order_tickets.cancel_reason')) ?></label>
                    <textarea class="form-control" id="ot-cancel-reason" name="reason" maxlength="255" rows="2" required></textarea>
                    <button class="btn btn-danger" type="submit"><?= esc(lang('Order_tickets.confirm_cancel')) ?></button>
                <?= form_close() ?>
            </details>
        <?php endif; ?>
    </div>

    <div class="ot-actionbar">
        <?= form_open('comandas/' . $id . '/enviar', ['data-once' => '1', 'class' => 'd-grid'], [\App\Libraries\Order_ticket_request_guard::FIELD => $request_token]) ?>
            <button class="btn btn-primary" type="submit" <?= $pending === 0 ? 'disabled data-disabled-by-server' : '' ?>>
                <?= esc(lang('Order_tickets.send_to_kitchen', [$pending])) ?>
            </button>
        <?= form_close() ?>
    </div>
<?php endif; ?>

<?php $this->endSection(); ?>

<?php $this->section('scripts'); ?>
<div class="alert alert-danger ot-offline" role="alert" hidden><?= esc(lang('Order_tickets.offline')) ?></div>
<script>
    // Progressive enhancement only. The server does not depend on any of this: a resubmitted form is
    // refused by its single-use token, and sending twice creates one round.
    (function () {
        var offline = document.querySelector('.ot-offline');

        document.querySelectorAll('form[data-once]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                // No signal: say so in words and send nothing, instead of letting the browser show
                // its own error page. Nothing was saved, and the waiter needs to know exactly that.
                if (navigator.onLine === false) {
                    event.preventDefault();
                    offline.hidden = false;
                    offline.scrollIntoView({ block: 'center' });
                    return;
                }

                offline.hidden = true;

                // Disabled while the request travels, so a double tap on a phone does not queue a
                // second one.
                form.querySelectorAll('button[type="submit"]').forEach(function (button) {
                    button.disabled = true;
                });
            });
        });

        // Coming back to this page (the back button, or the browser restoring it after a failed
        // request) must not leave its buttons disabled forever.
        window.addEventListener('pageshow', function () {
            document.querySelectorAll('form[data-once] button[type="submit"]').forEach(function (button) {
                button.disabled = button.hasAttribute('data-disabled-by-server');
            });
        });

        window.addEventListener('online', function () {
            offline.hidden = true;
        });
    })();
</script>
<?php $this->endSection(); ?>
