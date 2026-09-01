<?php
/**
 * Carga masiva de artículos: descargar el catálogo, corregirlo, volver a subirlo.
 *
 * ESTA VISTA ES EL ESQUELETO DE LA FASE 0. El Carril B la completa con la vista previa.
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
 */
?>

<?= view('partial/header') ?>

<div id="page_title"><?= lang('Items.bulk_upload_title') ?></div>

<?php if (!empty($error)) { ?>
    <div class="alert alert-dismissible alert-danger"><?= esc($error) ?></div>
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

<?php if (!empty($preview)) { ?>
    <?= view('items/bulk_import_preview', ['preview' => $preview]) ?>
<?php } ?>

<?= view('partial/footer') ?>
