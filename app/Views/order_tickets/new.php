<?php
/**
 * Opening a ticket: a free name (D5) and an optional note for the whole order.
 *
 * On the screen, "+ Nueva comanda" asks for the name and posts straight away, as the register's
 * "+ Nueva mesa" does. This page is what that link opens when there is no JavaScript, when nothing
 * was typed in the prompt, and where the server sends a refused form back -- old() brings back what
 * was typed, so a waiter standing at the table does not retype the order because of one missing field.
 *
 * Drawn with the shared POS header and a Bootstrap 3 panel, like every other form in the system.
 * maxlength matches order_tickets.name (64); the server cuts it again anyway.
 *
 * @var string $request_token
 */
$error = session()->getFlashdata('error');
?>

<?= view('partial/header') ?>

<div id="ot_screen">
    <?php if ($error): ?>
        <div class="alert alert-dismissible alert-danger" role="alert"><?= esc($error) ?></div>
    <?php endif; ?>

    <div class="panel panel-default ot-new-panel">
        <div class="panel-heading">
            <h3 class="panel-title"><span class="glyphicon glyphicon-plus">&nbsp;</span><?= esc(lang('Order_tickets.new_ticket')) ?></h3>
        </div>
        <div class="panel-body">
            <?= form_open('comandas/crear', ['class' => 'form-horizontal'], [\App\Libraries\Order_ticket_request_guard::FIELD => $request_token]) ?>
                <div class="form-group">
                    <label class="control-label col-sm-3" for="ot-name"><?= esc(lang('Order_tickets.name')) ?></label>
                    <div class="col-sm-9">
                        <input class="form-control" type="text" id="ot-name" name="name" maxlength="64" required autofocus
                               autocomplete="off" autocapitalize="characters" value="<?= esc(old('name') ?? '', 'attr') ?>"
                               aria-describedby="ot-name-help">
                        <span class="help-block" id="ot-name-help"><?= esc(lang('Order_tickets.name_help')) ?></span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="control-label col-sm-3" for="ot-note"><?= esc(lang('Order_tickets.note')) ?></label>
                    <div class="col-sm-9">
                        <input class="form-control" type="text" id="ot-note" name="note" maxlength="255"
                               autocomplete="off" value="<?= esc(old('note') ?? '', 'attr') ?>">
                    </div>
                </div>

                <div class="form-group">
                    <div class="col-sm-offset-3 col-sm-9">
                        <a class="btn btn-default" href="<?= esc(base_url('comandas'), 'attr') ?>"><?= esc(lang('Order_tickets.back')) ?></a>
                        <button class="btn btn-success" type="submit"><span class="glyphicon glyphicon-ok">&nbsp;</span><?= esc(lang('Order_tickets.create')) ?></button>
                    </div>
                </div>
            <?= form_close() ?>
        </div>
    </div>
</div>

<?= view('partial/footer') ?>
