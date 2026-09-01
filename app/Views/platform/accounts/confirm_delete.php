<?php

/**
 * Confirmación de baja de un superadministrador.
 *
 * Misma forma que la pantalla de eliminar un negocio, y por el mismo motivo: se escribe el correo,
 * no se marca una casilla. Una casilla se marca en la fila equivocada; un correo hay que leerlo
 * antes de poder copiarlo.
 *
 * Cuando la baja va a ser rechazada de todas formas -- es uno mismo, o es el último
 * superadministrador -- no hay formulario que enviar, solo el motivo. Es el mismo trato que recibe
 * un negocio adoptado en `platform/admin/.../delete`.
 *
 * @var object      $account
 * @var string|null $blocked motivo por el que no hay nada que confirmar, o null
 */
$this->extend('platform/console_layout');
$this->section('content');
?>

<div style="max-width: 640px;">
    <table class="table table-sm table-bordered bg-body mb-4">
        <tbody>
            <tr>
                <th scope="row" class="w-25"><?= esc(lang('Platform.account_email')) ?></th>
                <td><code><?= esc($account->email) ?></code></td>
            </tr>
            <tr>
                <th scope="row"><?= esc(lang('Platform.account_role')) ?></th>
                <td>
                    <?= esc((bool) $account->is_platform_admin
                        ? lang('Platform.account_role_admin')
                        : lang('Platform.account_role_owner')) ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?= esc(lang('Platform.account_last_login')) ?></th>
                <td>
                    <?php if ($account->last_login_at !== null): ?>
                        <?= esc(date('Y-m-d H:i', (int) strtotime((string) $account->last_login_at))) ?>
                    <?php else: ?>
                        <?= esc(lang('Platform.account_never_logged_in')) ?>
                    <?php endif; ?>
                </td>
            </tr>
        </tbody>
    </table>

    <?php if ($blocked !== null): ?>
        <div class="alert alert-warning" role="alert"><?= esc($blocked) ?></div>
        <a class="btn btn-secondary" href="<?= base_url('platform/accounts') ?>"><?= esc(lang('Platform.cancel')) ?></a>
    <?php else: ?>
        <p><?= esc(lang('Platform.account_confirm_delete_body')) ?></p>

        <?= form_open('platform/accounts/' . (int) $account->id . '/delete') ?>

        <div class="mb-4">
            <label class="form-label fw-bold" for="confirm_email">
                <?= esc(lang('Platform.account_confirm_email_label')) ?>
            </label>
            <input class="form-control" type="text" id="confirm_email" name="confirm_email" value=""
                autocomplete="off" autocapitalize="none" spellcheck="false" aria-describedby="confirm_email_help">
            <div class="form-text" id="confirm_email_help">
                <?= esc(lang('Platform.account_confirm_email_help', [$account->email])) ?>
            </div>
        </div>

        <button class="btn btn-danger" type="submit"><?= esc(lang('Platform.account_delete_button')) ?></button>
        <a class="btn btn-secondary" href="<?= base_url('platform/accounts') ?>"><?= esc(lang('Platform.cancel')) ?></a>

        <?= form_close() ?>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
