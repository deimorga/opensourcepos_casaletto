<?php
/**
 * El segundo factor, del lado del punto de venta.
 *
 * Vive aparte de `login.php` y no dentro de él a propósito: aquella pantalla ya carga la migración,
 * el captcha y tres variantes de formulario, y esta no necesita nada de eso. Mezclarlas obligaría a
 * que cada rama de aquella supiera si además hay un factor pendiente.
 *
 * DELIBERADAMENTE NO DICE DE QUIÉN ES LA CUENTA. Está servida desde la dirección pública de un
 * negocio; enseñar ahí el correo del superadministrador que está entrando sería regalar la mitad de
 * la credencial a cualquiera que llegue con la sesión a medias.
 *
 * @var array $config
 * @var string|null $error
 */

use App\Libraries\PlatformContext;
?>

<!doctype html>
<html lang="<?= esc(PlatformContext::LOCALE) ?>">

<head>
    <meta charset="utf-8">
    <base href="<?= base_url() ?>">
    <title><?= esc(lang('Login.platform_second_factor_title')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="shortcut icon" type="image/x-icon" href="images/favicon.ico">
    <?php
    $theme = (empty($config['theme'])
        || 'paper' == $config['theme']
        || 'readable' == $config['theme']
        ? 'flatly'
        : $config['theme']);
    ?>
    <link rel="stylesheet" href="resources/bootswatch5/<?= $theme ?>/bootstrap.min.css">
    <link rel="stylesheet" href="css/login.css">
    <meta name="theme-color" content="#2c3e50">
</head>

<body class="bg-secondary-subtle d-flex flex-column">
    <main class="d-flex justify-content-around align-items-center flex-grow-1">
        <div class="container-login container-fluid d-flex flex-column bg-body shadow rounded m-3 p-4">
            <section class="box-login d-flex flex-column justify-content-center align-items-center">
                <?= form_open('login/totp', ['id' => 'totp-form', 'autocomplete' => 'off']) ?>

                <h3 class="text-center m-0"><?= esc(lang('Login.platform_second_factor_title')) ?></h3>

                <p class="text-body-secondary mt-3 mb-0">
                    <?= esc(lang('Login.platform_second_factor_help')) ?>
                </p>

                <?php if ($error !== null): ?>
                    <div class="alert alert-danger mt-3 w-100" role="alert"><?= esc($error) ?></div>
                <?php endif; ?>

                <div class="form-floating mt-3 w-100">
                    <?php
                    // inputmode numérico y autocomplete de un solo uso: en el celular sale el teclado
                    // de números, y el gestor de contraseñas no intenta rellenar esto con nada.
                    ?>
                    <input class="form-control" id="input-code" name="code" type="text"
                           inputmode="numeric" autocomplete="one-time-code" maxlength="32"
                           placeholder="<?= esc(lang('Login.platform_second_factor_code')) ?>" autofocus>
                    <label for="input-code"><?= esc(lang('Login.platform_second_factor_code')) ?></label>
                </div>

                <button class="btn btn-primary w-100 mt-3" type="submit">
                    <?= esc(lang('Login.platform_second_factor_submit')) ?>
                </button>

                <a class="btn btn-link mt-2" href="<?= base_url('login') ?>">
                    <?= esc(lang('Login.platform_second_factor_cancel')) ?>
                </a>

                <?= form_close() ?>
            </section>
        </div>
    </main>
</body>

</html>
