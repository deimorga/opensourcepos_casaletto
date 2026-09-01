<?php
/**
 * «Esto es lo que se va a hacer»: el plan, antes de escribir nada.
 *
 * LO QUE ESTA PANTALLA TIENE QUE CONSEGUIR
 *
 * Que alguien que subió 1.184 filas sepa, de un vistazo y sin desplazarse, **si lo que va a pasar es
 * lo que quería**. De ahí las tres decisiones de esta vista:
 *
 * 1. **Los tres números van arriba y grandes.** Son la respuesta a la única pregunta que importa. El
 *    caso que el funcional §4.5 pone como ejemplo --«creía estar actualizando precios y dice que va a
 *    crear 12»-- se resuelve leyendo un número, no una tabla.
 * 2. **Las listas largas se desplazan dentro de su caja, no dentro de la página.** Con mil errores,
 *    una lista que crece hacia abajo empuja el botón de aplicar fuera de la pantalla y obliga a bajar
 *    mil filas para llegar a él. Cada caja tiene su propio alto y su propio desplazamiento, y los
 *    botones se quedan donde estaban.
 * 3. **Se enumera lo que se va a CREAR, y no lo que se va a actualizar.** Crear un artículo que ya
 *    existía es el error que este archivo puede cometer sin que nadie lo note --un código mal escrito
 *    se lee como un artículo nuevo--, así que esa lista se puede revisar. La de actualizaciones son
 *    1.184 filas que dicen lo que el cliente ya sabe.
 *
 * Sin JavaScript propio a propósito: `<details>` es HTML y no depende de que un paquete se haya
 * construido. Ver la nota de `gulp-inject` en la memoria del proyecto.
 *
 * Bootstrap 3, como todo el punto de venta.
 *
 * @var array{to_create: list<array>, to_update: list<array>, errors: list<array>, warnings: list<array>} $preview
 */

$aCrear      = $preview['to_create'] ?? [];
$aActualizar = $preview['to_update'] ?? [];
$errores     = $preview['errors'] ?? [];
$avisos      = $preview['warnings'] ?? [];

$hayAlgoQueAplicar = $aCrear !== [] || $aActualizar !== [];
?>

<div class="panel panel-primary" id="bulk_preview">
    <div class="panel-heading"><strong><?= lang('Items.bulk_preview_title') ?></strong></div>

    <div class="panel-body">
        <div class="alert alert-info" role="status">
            <span class="glyphicon glyphicon-info-sign">&nbsp;</span><?= lang('Items.bulk_preview_nothing_written') ?>
        </div>

        <?php
        // Los tres números. `col-xs-4` y no `col-md-4`: en una tableta detrás del mostrador tienen que
        // seguir leyéndose los tres juntos, que es lo que los hace comparables.
        ?>
        <div class="row text-center" style="margin-bottom:15px;">
            <div class="col-xs-4">
                <div class="well well-sm" style="margin-bottom:0;">
                    <div style="font-size:32px; line-height:1.1;"><?= count($aCrear) ?></div>
                    <div class="text-muted"><?= lang('Items.bulk_preview_to_create') ?></div>
                </div>
            </div>
            <div class="col-xs-4">
                <div class="well well-sm" style="margin-bottom:0;">
                    <div style="font-size:32px; line-height:1.1;"><?= count($aActualizar) ?></div>
                    <div class="text-muted"><?= lang('Items.bulk_preview_to_update') ?></div>
                </div>
            </div>
            <div class="col-xs-4">
                <div class="well well-sm<?= $errores === [] ? '' : ' bg-danger' ?>" style="margin-bottom:0;">
                    <div style="font-size:32px; line-height:1.1;<?= $errores === [] ? '' : ' color:#a94442;' ?>"><?= count($errores) ?></div>
                    <div class="text-muted"><?= lang('Items.bulk_preview_with_errors') ?></div>
                </div>
            </div>
        </div>

        <?php if ($errores !== []) { ?>
            <?php
            // `tabindex="0"` para que la caja que se desplaza se pueda recorrer con el teclado: sin
            // eso, quien no usa ratón no llega al error 300.
            ?>
            <h4><?= lang('Items.bulk_preview_with_errors') ?></h4>
            <div style="max-height:280px; overflow-y:auto; border:1px solid #ddd;"
                 tabindex="0" role="region" aria-label="<?= esc(lang('Items.bulk_preview_with_errors'), 'attr') ?>">
                <table class="table table-condensed table-striped" style="margin-bottom:0;">
                    <thead>
                        <tr>
                            <th scope="col" style="width:110px;"><?= lang('Items.bulk_preview_row', ['#']) ?></th>
                            <th scope="col"><?= lang('Items.bulk_preview_with_errors') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($errores as $error) { ?>
                            <tr class="danger">
                                <td><?= esc(lang('Items.bulk_preview_row', [$error['line']])) ?></td>
                                <td><?= esc($error['message']) ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>

        <?php if ($avisos !== []) { ?>
            <?php
            // Un aviso no impide aplicar: dice lo que se va a aplicar de otra forma. Van juntos y en
            // amarillo para que no se confundan con los errores, que sí dejan la fila fuera.
            ?>
            <div class="alert alert-warning" style="margin-top:15px;" role="status">
                <ul style="margin-bottom:0; padding-left:20px;">
                    <?php foreach ($avisos as $aviso) { ?>
                        <li>
                            <strong><?= esc(lang('Items.bulk_preview_row', [$aviso['line']])) ?>:</strong>
                            <?= esc($aviso['message']) ?>
                        </li>
                    <?php } ?>
                </ul>
            </div>
        <?php } ?>

        <?php if ($aCrear !== []) { ?>
            <details style="margin-top:15px;">
                <summary style="cursor:pointer;">
                    <strong><?= lang('Items.bulk_preview_to_create') ?>:</strong> <?= count($aCrear) ?>
                </summary>
                <div style="max-height:240px; overflow-y:auto; border:1px solid #ddd; margin-top:8px;"
                     tabindex="0" role="region" aria-label="<?= esc(lang('Items.bulk_preview_to_create'), 'attr') ?>">
                    <table class="table table-condensed table-striped" style="margin-bottom:0;">
                        <thead>
                            <tr>
                                <th scope="col" style="width:110px;"><?= lang('Items.bulk_preview_row', ['#']) ?></th>
<?php
                                // `Items.item` y no `Items.name`: en es-MX «name» está en blanco, y
                                // una cadena vacía no cae al inglés como una que falta -- la columna
                                // saldría sin título y sin dar error. La app corre en es-MX.
                                ?>
                                <th scope="col"><?= lang('Items.item') ?></th>
                                <th scope="col"><?= lang('Items.item_number') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($aCrear as $fila) { ?>
                                <tr>
                                    <td><?= esc(lang('Items.bulk_preview_row', [$fila['line']])) ?></td>
                                    <td><?= esc($fila['label']) ?></td>
                                    <td><?= esc($fila['item_number']) ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </details>
        <?php } ?>

        <?php if (!$hayAlgoQueAplicar) { ?>
            <p class="text-muted" style="margin-top:15px;"><?= lang('Items.bulk_preview_nothing_to_do') ?></p>
        <?php } ?>
    </div>

    <div class="panel-footer">
        <?= form_open('items/bulk/apply', ['id' => 'bulk_apply_form', 'class' => 'form-inline']) ?>
            <button class="btn btn-primary" type="submit" <?= $hayAlgoQueAplicar ? '' : 'disabled' ?>>
                <span class="glyphicon glyphicon-ok">&nbsp;</span><?= lang('Items.bulk_preview_apply') ?>
            </button>
            <a class="btn btn-default" href="<?= site_url('items/bulk') ?>"><?= lang('Items.bulk_preview_cancel') ?></a>
        <?= form_close() ?>
    </div>
</div>
