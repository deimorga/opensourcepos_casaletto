<?php

/**
 * Alta de un superadministrador.
 *
 * La contraseña se escribe aquí y no se genera: quien la crea es quien se la va a entregar a otra
 * persona, y una contraseña inventada por la máquina que además hay que copiar de una pantalla
 * añade un paso sin añadir seguridad. Lo que sí hace la pantalla es decirlo -- «no se envía a
 * ninguna parte» -- porque lo contrario se da por supuesto y luego nadie la anota.
 *
 * Tras un rechazo vuelve el correo tecleado, nunca la contraseña. Rellenar de vuelta un campo de
 * contraseña la mete en el HTML de una respuesta que los intermediarios pueden guardar, y ahorra
 * un tecleo que no le duele a nadie.
 *
 * @var string|null $error
 * @var string      $email
 * @var bool        $is_admin
 * @var int         $min_password
 */
$this->extend('platform/console_layout');
$this->section('content');
?>

<div style="max-width: 640px;">
    <p class="text-body-secondary"><?= esc(lang('Platform.new_account_intro')) ?></p>

    <?php if ($error !== null): ?>
        <div class="alert alert-danger" role="alert"><?= esc($error) ?></div>
    <?php endif; ?>

    <?= form_open('platform/accounts/create') ?>

    <div class="mb-3">
        <label class="form-label" for="email"><?= esc(lang('Platform.account_email')) ?></label>
        <input class="form-control" type="email" id="email" name="email" value="<?= esc($email, 'attr') ?>"
            required autocomplete="off" autocapitalize="none" spellcheck="false">
    </div>

    <div class="mb-3">
        <label class="form-label" for="password"><?= esc(lang('Platform.account_password')) ?></label>
        <input class="form-control" type="password" id="password" name="password" required
            minlength="<?= (int) $min_password ?>"
            autocomplete="new-password" aria-describedby="password_help">
        <div class="form-text" id="password_help"><?= esc(lang('Platform.account_password_help')) ?></div>
    </div>

    <div class="mb-3">
        <label class="form-label" for="password_confirm"><?= esc(lang('Platform.account_password_confirm')) ?></label>
        <input class="form-control" type="password" id="password_confirm" name="password_confirm" required
            minlength="<?= (int) $min_password ?>" autocomplete="new-password">
    </div>

    <div class="form-check mb-4">
        <input class="form-check-input" type="checkbox" id="is_platform_admin" name="is_platform_admin" value="1"
            <?= $is_admin ? 'checked' : '' ?> aria-describedby="is_platform_admin_help">
        <label class="form-check-label" for="is_platform_admin"><?= esc(lang('Platform.account_is_admin')) ?></label>
        <div class="form-text" id="is_platform_admin_help"><?= esc(lang('Platform.account_is_admin_help')) ?></div>
    </div>

    <button class="btn btn-primary" type="submit"><?= esc(lang('Platform.account_create')) ?></button>
    <a class="btn btn-secondary" href="<?= base_url('platform/accounts') ?>"><?= esc(lang('Platform.cancel')) ?></a>

    <?= form_close() ?>
</div>

<?= $this->endSection() ?>
