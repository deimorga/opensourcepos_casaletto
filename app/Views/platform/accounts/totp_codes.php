<?php
/**
 * Los códigos de rescate, la única vez que son legibles.
 *
 * Se guardan con hash, así que esta pantalla no se puede volver a pedir ni reconstruir: cerrarla
 * sin copiarlos los pierde para siempre, y con el factor encendido eso deja la cuenta con una sola
 * llave -- el teléfono -- y sin ningún canal por donde recuperarla.
 *
 * SOBRE LA NAVEGACIÓN. Esta pantalla se escribió primero sin ninguna, porque toda salida que no sea
 * «ya los guardé» es una salida que alguien toma sin leer. Extiende la envoltura común igualmente,
 * por decisión de integración: dos maquetaciones distintas en la misma consola cuestan más que este
 * riesgo. Lo que la envoltura no puede dar es el aviso, así que el aviso va aquí, en amarillo y
 * antes de los códigos, y la única acción de la página sigue siendo una sola.
 *
 * Se imprimen en una lista numerada y monoespaciada, en dos columnas, tal como se copian a mano o
 * se pegan en un gestor de contraseñas.
 *
 * @var list<string> $codes   en claro. No los registre, no los escriba en el log, no los guarde.
 * @var string       $message qué acaba de pasar: se encendió el factor, o se regeneró la tanda
 */
$this->extend('platform/console_layout');
?>

<?= $this->section('content') ?>

<div class="alert alert-success" role="status"><?= esc($message) ?></div>

<div class="bg-body shadow-sm rounded p-4">
    <p><?= esc(lang('Platform.recovery_codes_intro')) ?></p>
    <div class="alert alert-warning" role="alert"><?= esc(lang('Platform.recovery_codes_shown_once')) ?></div>

    <ol class="row row-cols-1 row-cols-sm-2 g-1 font-monospace fs-5 mb-4 ps-4">
        <?php foreach ($codes as $code): ?>
            <li class="col"><?= esc($code) ?></li>
        <?php endforeach; ?>
    </ol>

    <?php /* El botón lleva a la pantalla de estado del factor y no al panel: quien acaba de
             encenderlo debería ver, ahí mismo, que quedó encendido y cuántos códigos tiene. */ ?>
    <a class="btn btn-primary btn-lg" href="<?= base_url('platform/accounts/totp') ?>">
        <?= esc(lang('Platform.recovery_codes_saved')) ?>
    </a>
</div>

<?= $this->endSection() ?>
