<?php
/**
 * Sales::getQuote() / getQuotePdf() with an id that is not a saved quote.
 */
?>

<?= view('partial/header') ?>

<div class="alert alert-warning" role="alert"><?= esc(lang('Sales.quote_not_found')) ?></div>
<?= anchor('sales', '<span class="glyphicon glyphicon-shopping-cart">&nbsp;</span>' . lang('Sales.register'), ['class' => 'btn btn-info btn-sm']) ?>

<?= view('partial/footer') ?>
