<?php
/**
 * El estado del segundo factor de la cuenta que está en sesión, y la única acción que tiene
 * sentido desde ese estado: encenderlo si está apagado, apagarlo si está encendido.
 *
 * No hay «cambiar de teléfono» como acción propia. Cambiar de teléfono es apagar y volver a
 * encender, y apagar ya pide la contraseña; una tercera acción que sustituyera el secreto en
 * caliente sería la única forma de dejar la cuenta con un factor que no funciona.
 *
 * Los avisos de `flashdata` NO se pintan aquí: la envoltura ya los saca, y repetirlos los mostraría
 * dos veces.
 *
 * @var bool        $enabled
 * @var string|null $enabled_at
 * @var int         $codes_remaining
 * @var string      $email
 */
$this->extend('platform/console_layout');
?>

<?= $this->section('content') ?>

<div class="bg-body shadow-sm rounded p-4 mb-3">
    <p><?= esc(lang('Platform.totp_intro')) ?></p>
    <p class="text-body-secondary small mb-4"><?= esc(lang('Platform.totp_apps_help')) ?></p>

    <dl class="row mb-4">
        <dt class="col-sm-4"><?= esc(lang('Platform.account_email')) ?></dt>
        <dd class="col-sm-8"><?= esc($email) ?></dd>

        <dt class="col-sm-4"><?= esc(lang('Platform.account_second_factor')) ?></dt>
        <dd class="col-sm-8">
            <?php if ($enabled): ?>
                <span class="badge text-bg-success"><?= esc(lang('Platform.totp_state_on', [$enabled_at])) ?></span>
            <?php else: ?>
                <span class="badge text-bg-secondary"><?= esc(lang('Platform.totp_state_off')) ?></span>
            <?php endif; ?>
        </dd>
    </dl>

    <?php if (! $enabled): ?>
        <?= form_open('platform/accounts/totp/enroll') ?>
        <button class="btn btn-primary" type="submit"><?= esc(lang('Platform.totp_enroll')) ?></button>
        <?= form_close() ?>
    <?php else: ?>
        <?= form_open('platform/accounts/totp/disable') ?>
        <p class="mb-2"><?= esc(lang('Platform.totp_disable_confirm')) ?></p>
        <div class="row g-2 align-items-end">
            <div class="col-sm-6">
                <label class="form-label" for="password"><?= esc(lang('Platform.password')) ?></label>
                <input class="form-control" type="password" id="password" name="password" autocomplete="current-password" required>
            </div>
            <div class="col-sm-6">
                <button class="btn btn-outline-danger" type="submit"><?= esc(lang('Platform.totp_disable')) ?></button>
            </div>
        </div>
        <?= form_close() ?>
    <?php endif; ?>
</div>

<?php if ($enabled): ?>
    <div class="bg-body shadow-sm rounded p-4">
        <h2 class="h5"><?= esc(lang('Platform.recovery_codes_title')) ?></h2>

        <?php if ($codes_remaining === 0): ?>
            <?php /* Sin códigos y con el factor encendido, perder el teléfono deja la cuenta sin
                     ninguna puerta: no hay correo ni SMS por donde mandar nada. Por eso esto es una
                     alarma y no un dato más. */ ?>
            <div class="alert alert-warning"><?= esc(lang('Platform.recovery_codes_none_left')) ?></div>
        <?php else: ?>
            <p class="mb-3"><?= esc(lang('Platform.recovery_codes_remaining', [$codes_remaining])) ?></p>
        <?php endif; ?>

        <?= form_open('platform/accounts/totp/recovery-codes') ?>
        <button class="btn btn-outline-primary" type="submit"><?= esc(lang('Platform.recovery_codes_regenerate')) ?></button>
        <?= form_close() ?>
    </div>
<?php endif; ?>

<p class="text-body-secondary small mt-3"><?= esc(lang('Platform.totp_clock_note')) ?></p>

<?= $this->endSection() ?>
