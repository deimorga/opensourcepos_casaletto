<?php
/**
 * The live tickets of this site: what the waiter sees on opening the screen, and reloads all night.
 *
 * Each row says how many dishes the ticket carries and how many have NOT gone to the kitchen yet.
 * The second number is the one that matters at a glance: a ticket with dishes pending is an order
 * somebody is still standing at the table taking, or one they forgot to send.
 *
 * @var list<array<string, mixed>>                     $tickets from Order_ticket::get_live_for_location()
 * @var array<int, array{dishes: int, pending: int}> $counts  from Order_ticket_line::count_by_ticket()
 */
$this->extend('order_tickets/layout');
$this->section('content');
?>

<?php if ($tickets === []): ?>
    <p class="text-body-secondary"><?= esc(lang('Order_tickets.no_tickets')) ?></p>
<?php else: ?>
    <ul class="ot-list">
        <?php foreach ($tickets as $ticket): ?>
            <?php
            $id    = (int) $ticket['order_ticket_id'];
            $count = $counts[$id] ?? ['dishes' => 0, 'pending' => 0];
            ?>
            <li>
                <a href="<?= esc(base_url('comandas/' . $id), 'attr') ?>">
                    <span>
                        <span class="fw-bold d-block"><?= esc($ticket['name']) ?></span>
                        <small class="text-body-secondary">
                            <?= esc(lang('Order_tickets.dishes', [$count['dishes']])) ?>
                            <?php if ($count['pending'] > 0): ?>
                                &middot; <strong><?= esc(lang('Order_tickets.pending', [$count['pending']])) ?></strong>
                            <?php endif; ?>
                            &middot; <?= esc(date('H:i', strtotime((string) $ticket['opened_at']))) ?>
                        </small>
                    </span>
                    <span class="badge text-bg-<?= $ticket['status'] === 'delivered' ? 'success' : 'secondary' ?>">
                        <?= esc(lang('Order_tickets.status_' . $ticket['status'])) ?>
                    </span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<div class="ot-actionbar">
    <a class="btn btn-primary" href="<?= esc(base_url('comandas/nueva'), 'attr') ?>"><?= esc(lang('Order_tickets.new_ticket')) ?></a>
</div>

<?php $this->endSection(); ?>
