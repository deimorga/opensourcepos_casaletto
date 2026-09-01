<?php
/**
 * El reto del segundo factor, al entrar (D11).
 *
 * Mismo marco que platform/login.php a propósito: quien llega aquí acaba de escribir su contraseña
 * en esa pantalla y esto es el segundo paso de lo mismo, no otro sitio.
 *
 * UN SOLO CAMPO. El código de la aplicación y el de rescate se escriben en el mismo hueco, porque
 * quien llega a necesitar el de rescate ya perdió su forma habitual de entrar y no le hace falta
 * una decisión más antes de poder intentarlo.
 *
 * NO HAY «volver» NI «usar otro método». Lo único que hay es salir, que retira la marca de a
 * medias. Un enlace de escape en esta pantalla sería, literalmente, la forma de saltarse el factor.
 *
 * @var string|null $error
 */
?>
<!doctype html>
<html lang="<?= esc(service('request')->getLocale()) ?>">

<head>
    <meta charset="utf-8">
    <base href="<?= base_url() ?>">
    <title><?= esc(lang('Platform.totp_challenge_title')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="resources/bootswatch5/flatly/bootstrap.min.css">
    <link rel="stylesheet" href="css/login.css">
</head>

<body class="bg-secondary-subtle d-flex flex-column">
    <main class="d-flex justify-content-around align-items-center flex-grow-1">
        <div class="container-fluid" style="max-width: 420px;">
            <div class="bg-body shadow rounded p-4">
                <h3 class="text-center mb-3"><?= esc(lang('Platform.totp_challenge_title')) ?></h3>

                <p class="text-body-secondary small"><?= esc(lang('Platform.totp_challenge_intro')) ?></p>

                <?php if ($error !== null): ?>
                    <div class="alert alert-danger"><?= esc($error) ?></div>
                <?php endif; ?>

                <?= form_open('platform/login/totp') ?>
                <div class="mb-3">
                    <label class="form-label" for="code"><?= esc(lang('Platform.totp_challenge_field')) ?></label>
                    <?php /* inputmode numérico pero type=text: los códigos de rescate llevan letras y guiones. */ ?>
                    <input class="form-control form-control-lg font-monospace text-center"
                           type="text"
                           id="code"
                           name="code"
                           inputmode="numeric"
                           autocomplete="one-time-code"
                           autocapitalize="characters"
                           spellcheck="false"
                           required
                           autofocus>
                </div>
                <button class="btn btn-primary w-100" type="submit"><?= esc(lang('Platform.totp_challenge_go')) ?></button>
                <?= form_close() ?>

                <p class="text-body-secondary small mt-3 mb-0"><?= esc(lang('Platform.totp_clock_note')) ?></p>

                <a class="btn btn-link px-0 mt-2" href="<?= base_url('platform/logout') ?>"><?= esc(lang('Platform.logout')) ?></a>
            </div>
        </div>
    </main>
</body>

</html>
