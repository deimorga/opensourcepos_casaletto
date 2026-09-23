<?php
/**
 * Opening a ticket: a free name (D5) and an optional note for the whole order.
 *
 * The name is what the cashier will look for among the tabs, so the help says so with the business's
 * own examples. maxlength matches order_tickets.name (64); the server cuts it again anyway, since a
 * phone keyboard or a pasted text can ignore the attribute.
 *
 * old() brings back what was typed when the server refused the form, so a waiter standing at the
 * table does not retype the order because of one missing field.
 */
$this->extend('order_tickets/layout');
$this->section('content');
?>

<?= form_open('comandas/crear', ['class' => 'd-grid gap-3']) ?>
    <div>
        <label class="form-label" for="ot-name"><?= esc(lang('Order_tickets.name')) ?></label>
        <input class="form-control" type="text" id="ot-name" name="name" maxlength="64" required
               autocomplete="off" autocapitalize="characters" value="<?= esc(old('name') ?? '', 'attr') ?>"
               aria-describedby="ot-name-help">
        <div class="form-text" id="ot-name-help"><?= esc(lang('Order_tickets.name_help')) ?></div>
    </div>

    <div>
        <label class="form-label" for="ot-note"><?= esc(lang('Order_tickets.note')) ?></label>
        <input class="form-control" type="text" id="ot-note" name="note" maxlength="255"
               autocomplete="off" value="<?= esc(old('note') ?? '', 'attr') ?>">
    </div>

    <div class="ot-actionbar">
        <button class="btn btn-primary" type="submit"><?= esc(lang('Order_tickets.create')) ?></button>
    </div>
<?= form_close() ?>

<?php $this->endSection(); ?>
