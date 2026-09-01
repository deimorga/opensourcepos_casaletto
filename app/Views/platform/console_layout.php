<?php

use App\Libraries\PlatformContext;

/**
 * La envoltura común de la consola de plataforma: cabecera, navegación, avisos y pie.
 *
 * POR QUÉ EXISTE
 *
 * Hasta la Entrega 1 la consola era una sola pantalla, así que cada vista repetía su propio
 * <head> y no navegaba a ninguna parte. Con la Entrega 2 son cinco, y sin una barra común la
 * pantalla de superadministradores solo se alcanzaría tecleando su dirección a mano -- que es
 * exactamente el problema que este módulo vino a eliminar.
 *
 * NO ES UNA PLANTILLA BONITA, Y ESO ES DELIBERADO
 *
 * La usan una o dos personas y siempre desde un escritorio. Densa y sobria: sin héroes, sin
 * tarjetas, sin sombras. Bootstrap 5 (Bootswatch Flatly), el mismo de la Entrega 1 -- el punto de
 * venta corre en Bootstrap 3 y los dos mundos no se mezclan hasta la Entrega 4.
 *
 * @var string      $title lo que va en la pestaña del navegador y en el <h1>
 * @var string|null $nav   qué apartado se marca como activo
 */
$nav ??= '';

$items = [
    'businesses' => ['platform/admin', lang('Platform.nav_businesses')],
    'accounts'   => ['platform/accounts', lang('Platform.nav_accounts')],
    'activity'   => ['platform/activity', lang('Platform.nav_activity')],
    'password'   => ['platform/accounts/password', lang('Platform.nav_my_password')],
    'totp'       => ['platform/accounts/totp', lang('Platform.nav_second_factor')],
];
?>
<!doctype html>
<?php
    // El idioma que DECLARA la página sale de la misma constante que el que HABLA.
    //
    // `service('request')->getLocale()` no lo fija nadie en la consola, así que caía al
    // `defaultLocale` de la aplicación: la página salía marcada como inglés con todo el contenido en
    // español. Un lector de pantalla la lee con la voz equivocada y el traductor del navegador se
    // ofrece a traducir de un idioma al mismo. `Platform_Controller` ya fija el idioma del texto
    // desde `PlatformContext::LOCALE`; esto es la otra mitad, y desde la misma fuente para que no
    // puedan separarse.
?>
<html lang="<?= esc(PlatformContext::LOCALE) ?>">

<head>
    <meta charset="utf-8">
    <base href="<?= base_url() ?>">
    <title><?= esc($title) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="resources/bootswatch5/flatly/bootstrap.min.css">
</head>

<body class="bg-secondary-subtle">
    <nav class="navbar navbar-expand navbar-dark bg-primary" aria-label="<?= esc(lang('Platform.admin_panel_title')) ?>">
        <div class="container">
            <span class="navbar-brand"><?= esc(lang('Platform.admin_panel_title')) ?></span>
            <ul class="navbar-nav me-auto">
                <?php foreach ($items as $key => [$href, $label]): ?>
                    <li class="nav-item">
                        <a class="nav-link<?= $key === $nav ? ' active fw-bold' : '' ?>"
                            href="<?= base_url($href) ?>"
                            <?= $key === $nav ? 'aria-current="page"' : '' ?>><?= esc($label) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <a class="nav-link" href="<?= base_url('platform/logout') ?>"><?= esc(lang('Platform.logout')) ?></a>
        </div>
    </nav>

    <main class="container py-4">
        <h1 class="h4 mb-3"><?= esc($title) ?></h1>

        <?php if (session()->getFlashdata('message')): ?>
            <div class="alert alert-success" role="status"><?= esc(session()->getFlashdata('message')) ?></div>
        <?php endif; ?>
        <?php if (session()->getFlashdata('error')): ?>
            <div class="alert alert-danger" role="alert"><?= esc(session()->getFlashdata('error')) ?></div>
        <?php endif; ?>

        <?= $this->renderSection('content') ?>
    </main>
</body>

</html>
