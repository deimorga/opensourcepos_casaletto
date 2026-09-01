<?php
/**
 * Carga masiva de artículos: descargar el catálogo, corregirlo, volver a subirlo.
 *
 * EL ORDEN DE LA PÁGINA CAMBIA SEGÚN EN QUÉ PASO SE ESTÉ, Y ES DELIBERADO
 *
 * Sin archivo subido, lo primero son las dos descargas: el viaje empieza bajando el catálogo. Con un
 * archivo ya analizado, lo primero es la vista previa, porque a partir de ese momento la única
 * pregunta es «¿aplico esto?» y hacérsela buscar debajo de dos paneles de descarga sería esconderla.
 * Las descargas y el formulario siguen debajo, que es donde hacen falta si decide cancelar y corregir.
 *
 * Es una PÁGINA y no un modal, y eso es una decisión: el diálogo de BootstrapDialog fija sus botones
 * al abrirse y no los deja cambiar, así que no puede pasar de «Continuar» a «Aplicar / Cancelar»; una
 * vista previa de mil filas no cabe ahí; y una página sobrevive a un F5 y a que el cliente se vaya a
 * Excel a corregir el archivo.
 *
 * Bootstrap 3, como todo el punto de venta. La consola de plataforma es Bootstrap 5 y no se mezclan.
 *
 * @var array       $config
 * @var array|null  $preview  el plan calculado, cuando ya se subió un archivo
 * @var string|null $error
 * @var array|null  $result              lo que de verdad ocurrió, cuando ya se aplicó
 * @var bool|null   $previous_available  si la foto del «cómo estaba antes» se pudo guardar
 */

// `$result` no lo pasa `getIndex()`, que es el camino de llegada normal a esta pantalla: solo aparece
// al volver de aplicar. Se lee con `??` en vez de exigirlo para no obligar a tocar ese método.
$result = $result ?? null;

// Y el error puede venir por dos caminos. `getPrevious()` redirige con `with('error', ...)`, que es
// flashdata, mientras que los dos POST lo pasan como variable de la vista. Sin esta línea, el aviso
// de «el archivo ya no está» de la descarga del «cómo estaba antes» se perdería en silencio.
$error = $error ?? session()->getFlashdata('error');
?>

<?= view('partial/header') ?>

<div id="page_title"><?= lang('Items.bulk_upload_title') ?></div>

<?php if (!empty($error)) { ?>
    <div class="alert alert-dismissible alert-danger" role="alert"><?= esc($error) ?></div>
<?php } ?>

<?php if ($result !== null) { ?>
    <?php
    // EL RESULTADO VA ARRIBA DEL TODO, Y CON LA RED AL LADO
    //
    // Quien acaba de cambiar 1.184 precios de un golpe tiene una sola pregunta -- «¿cuántos?» -- y una
    // sola necesidad si la respuesta no le cuadra: el archivo de cómo estaba antes. Las dos cosas
    // juntas y antes que nada más, no al final de la página.
    ?>
    <div class="panel panel-success">
        <div class="panel-body">
            <p style="font-size:16px; margin-bottom:10px;">
                <span class="glyphicon glyphicon-ok-circle">&nbsp;</span>
                <strong><?= esc(lang('Items.bulk_applied_counts', [$result['created'], $result['updated']])) ?></strong>
            </p>
            <?php if (!empty($previous_available)) { ?>
                <p class="text-muted"><?= lang('Items.bulk_download_previous_help') ?></p>
                <a class="btn btn-default" href="<?= site_url('items/bulk/previous') ?>">
                    <span class="glyphicon glyphicon-download-alt">&nbsp;</span><?= lang('Items.bulk_download_previous') ?>
                </a>
            <?php } ?>
        </div>
    </div>
<?php } ?>

<?php if (!empty($preview)) { ?>
    <?= view('items/bulk_import_preview', ['preview' => $preview]) ?>
<?php } ?>

<div class="row">
    <div class="col-xs-12 col-md-6">
        <div class="panel panel-default">
            <div class="panel-heading"><strong><?= lang('Items.bulk_download_catalog') ?></strong></div>
            <div class="panel-body">
                <p class="text-muted"><?= lang('Items.bulk_download_catalog_help') ?></p>
                <a class="btn btn-primary" href="<?= site_url('items/bulk/catalog') ?>">
                    <span class="glyphicon glyphicon-download-alt">&nbsp;</span><?= lang('Items.bulk_download_catalog') ?>
                </a>
            </div>
        </div>
    </div>

    <div class="col-xs-12 col-md-6">
        <div class="panel panel-default">
            <div class="panel-heading"><strong><?= lang('Items.bulk_download_template') ?></strong></div>
            <div class="panel-body">
                <p class="text-muted"><?= lang('Items.bulk_download_template_help') ?></p>
                <a class="btn btn-default" href="<?= site_url('items/bulk/template') ?>">
                    <span class="glyphicon glyphicon-download-alt">&nbsp;</span><?= lang('Items.bulk_download_template') ?>
                </a>
            </div>
        </div>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-body">
        <?php
        // Las tres reglas del archivo, dichas ANTES de subirlo. La de la celda vacía es la más
        // peligrosa de todas: si alguien creyera que vacío borra, subiría un archivo con solo los
        // precios llenos y se quedaría sin nombres.
        ?>
        <ul class="text-muted">
            <li><?= lang('Items.bulk_upload_how_it_decides') ?></li>
            <li><strong><?= lang('Items.bulk_upload_empty_means') ?></strong></li>
            <li><?= lang('Items.bulk_upload_stock_ignored') ?></li>
        </ul>

        <?= form_open_multipart('items/bulk/preview', ['id' => 'bulk_import_form', 'class' => 'form-inline']) ?>
            <div class="form-group">
                <input type="file" name="file_path" id="file_path" accept=".csv,text/csv" required>
            </div>
            <button class="btn btn-primary" type="submit">
                <span class="glyphicon glyphicon-upload">&nbsp;</span><?= lang('Common.import_csv') ?>
            </button>
        <?= form_close() ?>
    </div>
</div>

<?= view('partial/footer') ?>
