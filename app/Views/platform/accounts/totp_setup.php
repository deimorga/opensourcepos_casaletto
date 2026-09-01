<?php
/**
 * Alta del segundo factor: la clave que hay que meter en el teléfono, y el código que demuestra
 * que llegó bien.
 *
 * ------------------------------------------------------------------------------------------------
 * NO HAY CÓDIGO QR, Y ESO CAMBIA CÓMO SE DISEÑA ESTA PANTALLA
 * ------------------------------------------------------------------------------------------------
 *
 * No hay librería de QR en el repositorio y no se añade una. Así que la clave se TECLEA, y esta
 * pantalla es un ejercicio de legibilidad, no de adorno:
 *
 *   - La clave sale grande, en monoespaciada y en grupos de cuatro. Son 32 caracteres base32, y su
 *     alfabeto no tiene 0, 1 ni 8, así que las confusiones clásicas (O/0, I/1, B/8) no pueden
 *     darse -- pero solo si la tipografía deja distinguir lo que sí hay, y por eso es
 *     monoespaciada y grande.
 *   - El NOMBRE DE LA ENTRADA sale escrito tal como quedará en el teléfono. Quien se dé de alta en
 *     staging y en producción acabará con dos entradas que se llaman igual, y eso hay que saberlo
 *     al darse de alta, no el día que necesite entrar.
 *   - La URI `otpauth://` va entera, seleccionable, y además como enlace: abierta en el propio
 *     teléfono, deja la entrada rellena y no hay nada que teclear.
 *
 * Los dos campos se seleccionan enteros con un clic, que es todo lo que hace falta para copiarlos.
 * No hay botón de «copiar» y no hace falta: quien registra el factor lo hace en su teléfono, y ahí
 * lo que sirve no es copiar en el escritorio sino abrir la URI, que deja la entrada rellena sola.
 *
 * Las cadenas `totp_enroll_intro` y `totp_secret_help` prometían escanear una imagen que aquí no
 * existe; se reescribieron el 2026-09-01 para que digan lo que la pantalla hace de verdad, y
 * `totp_qr_alt` se retiró. Si algún día se añade la librería de QR, hay que volver a tocarlas.
 *
 * @var string      $secret_display la clave en grupos de cuatro. La forma sin espacios va dentro
 *                                  de la URI, que es el camino de copiar y pegar.
 * @var string      $uri            otpauth://...
 * @var string      $issuer
 * @var string      $email
 * @var string|null $error
 */
$this->extend('platform/console_layout');
?>

<?= $this->section('content') ?>

<?php if ($error !== null): ?>
    <div class="alert alert-danger" role="alert"><?= esc($error) ?></div>
<?php endif; ?>

<div class="bg-body shadow-sm rounded p-4">
    <p><?= esc(lang('Platform.totp_intro')) ?></p>
    <p class="text-body-secondary small"><?= esc(lang('Platform.totp_apps_help')) ?></p>

    <hr>

    <h2 class="h5 mb-1">1. <?= esc(lang('Platform.totp_secret_label')) ?></h2>

    <p class="text-body-secondary small mb-2">
        <strong><?= esc($issuer) ?></strong> &middot; <?= esc($email) ?>
    </p>

    <input class="form-control form-control-lg font-monospace fs-4 mb-3"
           id="totp-secret"
           type="text"
           value="<?= esc($secret_display, 'attr') ?>"
           readonly
           spellcheck="false"
           onclick="this.select()"
           onfocus="this.select()">

    <label class="form-label text-body-secondary small" for="totp-uri">otpauth://</label>
    <input class="form-control font-monospace mb-2"
           id="totp-uri"
           type="text"
           value="<?= esc($uri, 'attr') ?>"
           readonly
           spellcheck="false"
           onclick="this.select()"
           onfocus="this.select()">
    <p class="small text-break mb-4"><a href="<?= esc($uri, 'attr') ?>"><?= esc($uri) ?></a></p>

    <hr>

    <h2 class="h5 mb-3">2. <?= esc(lang('Platform.totp_code')) ?></h2>

    <?= form_open('platform/accounts/totp/confirm') ?>
    <div class="row g-2 align-items-end">
        <div class="col-sm-5">
            <label class="form-label" for="code"><?= esc(lang('Platform.totp_code')) ?></label>
            <input class="form-control form-control-lg font-monospace text-center"
                   type="text"
                   id="code"
                   name="code"
                   inputmode="numeric"
                   autocomplete="one-time-code"
                   spellcheck="false"
                   maxlength="7"
                   required
                   autofocus>
            <div class="form-text"><?= esc(lang('Platform.totp_code_help')) ?></div>
        </div>
        <div class="col-sm-7">
            <button class="btn btn-primary btn-lg" type="submit"><?= esc(lang('Platform.totp_confirm')) ?></button>
            <a class="btn btn-link" href="<?= base_url('platform/accounts/totp') ?>"><?= esc(lang('Platform.cancel')) ?></a>
        </div>
    </div>
    <?= form_close() ?>

    <p class="text-body-secondary small mt-4 mb-0"><?= esc(lang('Platform.totp_clock_note')) ?></p>
</div>

<?= $this->endSection() ?>
