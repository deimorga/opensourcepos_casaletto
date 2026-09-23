<?php
/**
 * Order tickets are switched off for this business.
 *
 * A plain explanation and not an error page: the menu tile can still show for an employee who was
 * granted the module, and a waiter who taps it deserves to know it is a setting, not a fault. The
 * layout keeps the logout link, so switching the module off never locks anybody in.
 */
$this->extend('order_tickets/layout');
$this->section('content');
?>

<div class="alert alert-info" role="status"><?= esc(lang('Order_tickets.disabled')) ?></div>

<?php $this->endSection(); ?>
