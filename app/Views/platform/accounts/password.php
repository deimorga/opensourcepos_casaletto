<?php

/**
 * Cambiar la propia contraseña.
 *
 * Se pide la actual además de la nueva. No es burocracia: la sesión de esta consola dura, y un
 * navegador que alguien dejó abierto no debería alcanzar para cambiar la llave de todos los
 * negocios de todos los clientes.
 *
 * Esta pantalla cambia SOLO la contraseña de quien está dentro. No existe -- ni debe existir -- una
 * versión de ella que cambie la de otro: a un superadministrador que no puede entrar se le
 * desbloquea o se le reemplaza.
 *
 * @var string|null $error
 * @var int         $min_password
 */
$this->extend('platform/console_layout');
$this->section('content');
?>

<div style="max-width: 480px;">
    <p class="text-body-secondary"><?= esc(lang('Platform.password_intro')) ?></p>

    <?php if ($error !== null): ?>
        <div class="alert alert-danger" role="alert"><?= esc($error) ?></div>
    <?php endif; ?>

    <?= form_open('platform/accounts/password') ?>

    <div class="mb-3">
        <label class="form-label" for="password_current"><?= esc(lang('Platform.password_current')) ?></label>
        <input class="form-control" type="password" id="password_current" name="password_current" required
            autocomplete="current-password">
    </div>

    <div class="mb-3">
        <label class="form-label" for="password_new"><?= esc(lang('Platform.password_new')) ?></label>
        <input class="form-control" type="password" id="password_new" name="password_new" required
            minlength="<?= (int) $min_password ?>" autocomplete="new-password" aria-describedby="password_new_help">
        <div class="form-text" id="password_new_help"><?= esc(lang('Platform.account_password_help')) ?></div>
    </div>

    <div class="mb-4">
        <label class="form-label" for="password_new_confirm"><?= esc(lang('Platform.password_new_confirm')) ?></label>
        <input class="form-control" type="password" id="password_new_confirm" name="password_new_confirm" required
            minlength="<?= (int) $min_password ?>" autocomplete="new-password">
    </div>

    <button class="btn btn-primary" type="submit"><?= esc(lang('Platform.password_change')) ?></button>
    <a class="btn btn-secondary" href="<?= base_url('platform/accounts') ?>"><?= esc(lang('Platform.cancel')) ?></a>

    <?= form_close() ?>
</div>

<?= $this->endSection() ?>
