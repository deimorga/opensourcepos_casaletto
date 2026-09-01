<?php

/**
 * Confirmación del restablecimiento de la contraseña de un negocio (D5).
 *
 * POR QUÉ AQUÍ NO SE TECLEA NADA, A DIFERENCIA DE LA PANTALLA DE ELIMINAR
 *
 * Aquella destruye y no se deshace; esta se deshace haciéndola otra vez. Y sobre todo: existe para
 * resolver en una llamada el caso de un cliente que no puede entrar a su propio negocio, así que
 * cada campo de más que haya que teclear alarga esa llamada sin evitar ningún daño.
 *
 * Lo que sí hace la pantalla es NOMBRAR antes de actuar -- el negocio, su base y el usuario exacto
 * -- y avisar de lo único irreversible que ocurre aquí: quien esté usando la contraseña anterior
 * deja de poder entrar en ese mismo instante.
 *
 * EL USUARIO ES UN CAMPO Y NO UNA SUPOSICIÓN
 *
 * Un negocio aprovisionado por nosotros tiene `admin`. Casaletto es adoptado, su administrador se
 * llama de otra forma y sus seis empleados son personas reales. Suponer el nombre aquí sería, en el
 * mejor caso, no encontrar a nadie, y en el peor cambiarle la contraseña al empleado equivocado del
 * negocio que está vendiendo.
 *
 * @var object $tenant
 * @var string $username el que la plataforma tenga guardado, o el de serie
 */
$this->extend('platform/console_layout');
$this->section('content');
?>

<div style="max-width: 44rem;">
    <p>
        <a href="<?= base_url('platform/admin/' . rawurlencode((string) $tenant->slug)) ?>">&larr; <?= esc(lang('Platform.business_back')) ?></a>
    </p>

    <table class="table table-sm table-bordered bg-body mb-4">
        <tbody>
            <tr>
                <th scope="row" class="w-25"><?= esc(lang('Platform.business_name')) ?></th>
                <td><?= esc(trim((string) ($tenant->company_name ?? '')) !== '' ? $tenant->company_name : $tenant->slug) ?></td>
            </tr>
            <tr>
                <th scope="row"><?= esc(lang('Platform.slug')) ?></th>
                <td><code><?= esc($tenant->slug) ?></code></td>
            </tr>
            <tr>
                <th scope="row"><?= esc(lang('Platform.database')) ?></th>
                <td><code><?= esc($tenant->db_name) ?></code></td>
            </tr>
        </tbody>
    </table>

    <div class="alert alert-warning" role="alert"><?= esc(lang('Platform.reset_password_body')) ?></div>

    <?= form_open('platform/admin/' . rawurlencode((string) $tenant->slug) . '/reset-password') ?>

    <div class="mb-3">
        <label class="form-label" for="username"><?= esc(lang('Platform.reset_password_user')) ?></label>
        <input class="form-control" type="text" id="username" name="username" required
            value="<?= esc($username, 'attr') ?>" autocomplete="off" aria-describedby="username_help">
        <div class="form-text" id="username_help">
            <?= esc(lang('Platform.reset_password_user_help', [\App\Libraries\TenantProvisioner::DEFAULT_ADMIN_USERNAME])) ?>
        </div>
    </div>

    <button class="btn btn-danger" type="submit"><?= esc(lang('Platform.reset_password_button')) ?></button>
    <a class="btn btn-secondary" href="<?= base_url('platform/admin/' . rawurlencode((string) $tenant->slug)) ?>"><?= esc(lang('Platform.cancel')) ?></a>

    <?= form_close() ?>
</div>

<?= $this->endSection() ?>
