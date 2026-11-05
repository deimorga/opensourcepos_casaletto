<?php
/**
 * Presales are switched off for this business.
 *
 * A plain explanation and not an error page: the menu tile can still show for an employee who was
 * granted the module, and they deserve to know it is a setting, not a fault.
 *
 * Also the page of a presale that cannot be changed (Presales::getEdit()), with its own message. A
 * name of its own, not $message: the renderer keeps other views' data for the rest of the request, and
 * a `message` left behind by one of them showed on the switched-off page (CI, 2026-11-04).
 *
 * @var string|null $presale_unavailable
 */
?>

<?= view('partial/header') ?>

<div class="alert alert-info" role="status"><?= esc($presale_unavailable ?? lang('Presales.disabled')) ?></div>

<?= view('partial/footer') ?>
