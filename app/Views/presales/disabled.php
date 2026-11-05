<?php
/**
 * Presales are switched off for this business.
 *
 * A plain explanation and not an error page: the menu tile can still show for an employee who was
 * granted the module, and they deserve to know it is a setting, not a fault.
 *
 * Also the page of a presale that cannot be changed (Presales::getEdit()), with its own $message.
 *
 * @var string|null $message
 */
?>

<?= view('partial/header') ?>

<div class="alert alert-info" role="status"><?= esc($message ?? lang('Presales.disabled')) ?></div>

<?= view('partial/footer') ?>
