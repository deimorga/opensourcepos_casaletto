<?php

use App\Libraries\Wiring_lock;

/**
 * La ficha del negocio (§6.3): la pantalla donde de verdad se gestiona un cliente.
 *
 * CONTESTA TRES PREGUNTAS Y NADA MÁS
 *
 * Quién es este negocio, cómo está configurado, y con qué contraseña se entra. Las tres son las que
 * hoy obligan a abrir un cliente de base de datos, que es exactamente lo que este módulo vino a
 * dejar de hacer.
 *
 * LA CONTRASEÑA NO ESTÁ EN EL HTML SI NADIE LA PIDIÓ
 *
 * `$reveal` viene de `?reveal=1` en la dirección. Sin esa marca, `$credential['password']` llega
 * igualmente relleno desde el controlador -- hace falta para saber el estado -- pero esta plantilla
 * no lo escribe en ninguna parte. Abrir la ficha para mirar si un negocio está activo no debería
 * dejar la contraseña de un cliente en la pantalla ni en la captura que alguien haga de ella.
 *
 * EL BLOQUE DE ENTREGA ES UN <textarea> Y ESO ES DELIBERADO
 *
 * Es lo que se pega en el mensaje al cliente, así que tiene que poderse seleccionar entero de una
 * vez. Un botón de «copiar» necesitaría JavaScript, y esta consola no carga ninguno: el área de
 * texto en solo lectura se selecciona con un clic y funciona sin nada más.
 *
 * @var object                     $tenant
 * @var string                     $name       nombre real, o el slug si no hay ninguno guardado
 * @var string                     $url        la dirección pública del negocio
 * @var bool                       $adopted
 * @var array                      $credential state/username/password/set_at
 * @var bool                       $reveal
 * @var array<string, string|null> $settings   clave => valor que el negocio tiene HOY, o [] si no
 *                                             se pudo llegar a él
 * @var array<string, string>      $wiring     las tres claves de cableado y su valor obligatorio
 * @var string                     $profile_id
 */
$this->extend('platform/console_layout');
$this->section('content');

$when = static fn (?string $value): ?string => $value === null || $value === ''
    ? null
    : date('Y-m-d H:i', (int) strtotime($value));

$state    = (string) ($credential['state'] ?? '');
$username = (string) ($credential['username'] ?? '');
$showable = $state === \App\Libraries\TenantProvisioner::CREDENTIAL_AVAILABLE;
?>

<p>
    <a href="<?= base_url('platform/admin') ?>">&larr; <?= esc(lang('Platform.business_back')) ?></a>
</p>

<h2 class="h5 mt-4"><?= esc(lang('Platform.business_identity')) ?></h2>

<table class="table table-sm table-bordered bg-body" style="max-width: 44rem;">
    <tbody>
        <tr>
            <th scope="row" class="w-25"><?= esc(lang('Platform.business_name')) ?></th>
            <td>
                <?= esc($name) ?>
                <?php if (trim((string) ($tenant->company_name ?? '')) === ''): ?>
                    <span class="text-body-secondary small">(<?= esc(lang('Platform.business_name_unknown')) ?>)</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><?= esc(lang('Platform.slug')) ?></th>
            <td><code><?= esc($tenant->slug) ?></code></td>
        </tr>
        <tr>
            <th scope="row"><?= esc(lang('Platform.business_address')) ?></th>
            <td>
                <a href="<?= esc($url, 'attr') ?>" rel="noopener noreferrer" target="_blank"><?= esc($url) ?></a>
                <?php
                    // El enlace de arriba lleva al FORMULARIO de entrada del negocio; este entra.
                    // Van los dos porque no son lo mismo: uno es la dirección que se le da al
                    // cliente, el otro es nuestra puerta de servicio.
                ?>
                <div class="mt-2">
                    <a class="btn btn-sm btn-outline-primary"
                       href="<?= base_url('platform/admin/' . rawurlencode((string) $tenant->slug) . '/enter') ?>">
                        <?= esc(lang('Platform.enter_business')) ?>
                    </a>
                    <div class="text-body-secondary small mt-1"><?= esc(lang('Platform.enter_business_help')) ?></div>
                </div>
            </td>
        </tr>
        <tr>
            <th scope="row"><?= esc(lang('Platform.database')) ?></th>
            <td>
                <code><?= esc($tenant->db_name) ?></code>
                <?php if ($adopted): ?>
                    <span class="badge text-bg-light border"><?= esc(lang('Platform.adopted')) ?></span>
                    <div class="text-body-secondary small"><?= esc(lang('Platform.adopted_explained', [$tenant->db_name])) ?></div>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><?= esc(lang('Platform.status')) ?></th>
            <td>
                <span class="badge <?= $tenant->status === 'active' ? 'text-bg-success' : 'text-bg-secondary' ?>">
                    <?= esc($tenant->status) ?>
                </span>
            </td>
        </tr>
        <tr>
            <th scope="row"><?= esc(lang('Platform.created_at')) ?></th>
            <td><?= esc($when($tenant->created_at ?? null) ?? lang('Platform.never')) ?></td>
        </tr>
    </tbody>
</table>

<h2 class="h5 mt-4"><?= esc(lang('Platform.credential_title')) ?></h2>

<?php
// Cada estado dice una cosa distinta y las cuatro llevan color Y frase. Un aviso que solo es color
// no lo lee ni quien no distingue ese color ni quien imprime la pantalla.
$notices = [
    \App\Libraries\TenantProvisioner::CREDENTIAL_AVAILABLE   => ['alert-success', 'Platform.credential_available'],
    \App\Libraries\TenantProvisioner::CREDENTIAL_CHANGED     => ['alert-warning', 'Platform.credential_changed'],
    \App\Libraries\TenantProvisioner::CREDENTIAL_NONE        => ['alert-secondary', 'Platform.credential_none'],
    \App\Libraries\TenantProvisioner::CREDENTIAL_UNREADABLE  => ['alert-danger', 'Platform.credential_unreadable'],
    \App\Libraries\TenantProvisioner::CREDENTIAL_UNREACHABLE => ['alert-warning', 'Platform.credential_unreachable'],
];
[$class, $key] = $notices[$state] ?? ['alert-secondary', 'Platform.credential_none'];
?>

<div class="alert <?= esc($class, 'attr') ?>" role="status" style="max-width: 44rem;">
    <?= esc(lang($key)) ?>
</div>

<table class="table table-sm table-bordered bg-body" style="max-width: 44rem;">
    <tbody>
        <tr>
            <th scope="row" class="w-25"><?= esc(lang('Platform.credential_username')) ?></th>
            <td><code><?= $username === '' ? '&mdash;' : esc($username) ?></code></td>
        </tr>
        <?php if ($showable): ?>
            <tr>
                <th scope="row"><?= esc(lang('Platform.credential_password')) ?></th>
                <td>
                    <?php if ($reveal): ?>
                        <code><?= esc((string) $credential['password']) ?></code>
                        <a class="btn btn-sm btn-outline-secondary ms-2"
                            href="<?= base_url('platform/admin/' . rawurlencode((string) $tenant->slug)) ?>"><?= esc(lang('Platform.credential_hide')) ?></a>
                    <?php else: ?>
                        <a class="btn btn-sm btn-outline-primary"
                            href="<?= base_url('platform/admin/' . rawurlencode((string) $tenant->slug)) ?>?reveal=1"><?= esc(lang('Platform.credential_reveal')) ?></a>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?= esc(lang('Platform.credential_set_at')) ?></th>
                <td><?= esc($when($credential['set_at'] ?? null) ?? lang('Platform.never')) ?></td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<?php if ($showable && $reveal): ?>
    <div class="mb-3" style="max-width: 44rem;">
        <label class="form-label fw-bold" for="delivery"><?= esc(lang('Platform.credential_delivery')) ?></label>
        <textarea class="form-control font-monospace" id="delivery" rows="4" readonly
            aria-describedby="delivery_help"><?= esc(implode("\n", [
                $url,
                lang('Platform.credential_username') . ': ' . $username,
                lang('Platform.credential_password') . ': ' . (string) $credential['password'],
            ])) ?></textarea>
        <div class="form-text" id="delivery_help">
            <?= esc(lang('Platform.credential_delivery_help')) ?>
            <?= esc(lang('Platform.credential_never_logged')) ?>
        </div>
    </div>
<?php endif; ?>

<p>
    <a class="btn btn-outline-danger"
        href="<?= base_url('platform/admin/' . rawurlencode((string) $tenant->slug) . '/reset-password') ?>"><?= esc(lang('Platform.reset_password')) ?></a>
</p>

<h2 class="h5 mt-4"><?= esc(lang('Platform.settings_title')) ?></h2>

<p class="text-body-secondary" style="max-width: 44rem;">
    <?= esc(lang('Platform.settings_intro', [$profile_id])) ?>
    <?= esc(lang('Platform.settings_not_editable_here')) ?>
</p>

<?php if ($settings === []): ?>
    <div class="alert alert-warning" role="status" style="max-width: 44rem;">
        <?= esc(lang('Platform.settings_unreachable')) ?>
    </div>
<?php else: ?>
    <table class="table table-sm table-bordered bg-body" style="max-width: 44rem;">
        <thead>
            <tr>
                <th scope="col"><?= esc(lang('Platform.settings_key')) ?></th>
                <th scope="col"><?= esc(lang('Platform.settings_value')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($settings as $key => $value): ?>
                <?php $expected = $wiring[$key] ?? null; ?>
                <tr>
                    <th scope="row" class="fw-normal">
                        <code><?= esc($key) ?></code>
                        <?php if ($expected !== null): ?>
                            <span class="badge text-bg-light border"><?= esc(lang('Platform.settings_wired')) ?></span>
                        <?php endif; ?>
                    </th>
                    <td>
                        <?php if ($value === null): ?>
                            <span class="text-body-secondary"><?= esc(lang('Platform.settings_missing')) ?></span>
                        <?php else: ?>
                            <code><?= esc($value) ?></code>
                        <?php endif; ?>
                        <?php
                            // La comparación va por `Wiring_lock` y no con `!==` a mano: era una
                            // TERCERA copia de la misma regla, y la que le pintaba a un negocio con
                            // el valor histórico `number` una alarma roja permanente sobre un ajuste
                            // que ya era el correcto.
                        ?>
                        <?php if ($expected !== null && ! Wiring_lock::matches_wiring($key, (string)$value)): ?>
                            <div class="text-danger small"><?= esc(lang('Platform.settings_wired_help', [$expected])) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?= $this->endSection() ?>
