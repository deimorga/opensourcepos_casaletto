<?php
/**
 * Presales are switched off for this business.
 *
 * A plain explanation and not an error page: the menu tile can still show for an employee who was
 * granted the module, and they deserve to know it is a setting, not a fault.
 */
?>

<?= view('partial/header') ?>

<div class="alert alert-info" role="status"><?= esc(lang('Presales.disabled')) ?></div>

<?= view('partial/footer') ?>
