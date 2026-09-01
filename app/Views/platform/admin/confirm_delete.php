<?php
/**
 * Confirmación de baja de un negocio.
 *
 * Dos decisiones, deliberadamente desiguales: dar de baja el negocio (se
 * escribe su slug) y destruir su base de datos (se escribe, aparte, el nombre
 * de la base). La segunda pesa más y se ve más: para Casaletto esa palabra
 * sería `ospos`, que avisa mucho más que `casaletto`.
 *
 * @var object $tenant
 * @var bool   $adopted
 */
?>
<!doctype html>
<html lang="<?= esc(service('request')->getLocale()) ?>">

<head>
    <meta charset="utf-8">
    <base href="<?= base_url() ?>">
    <title><?= esc(lang('Platform.confirm_delete_title')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="resources/bootswatch5/flatly/bootstrap.min.css">
</head>

<body class="bg-secondary-subtle">
    <div class="container py-4" style="max-width: 640px;">
        <h4 class="mb-3"><?= esc(lang('Platform.confirm_delete_title')) ?>: <code><?= esc($tenant->slug) ?></code></h4>

        <?php if (session()->getFlashdata('error')): ?>
            <div class="alert alert-danger"><?= esc(session()->getFlashdata('error')) ?></div>
        <?php endif; ?>

        <table class="table table-sm table-bordered bg-body mb-4">
            <tbody>
                <tr>
                    <th class="w-25"><?= esc(lang('Platform.slug')) ?></th>
                    <td><code><?= esc($tenant->slug) ?></code></td>
                </tr>
                <tr>
                    <th><?= esc(lang('Platform.database')) ?></th>
                    <td><code><?= esc($tenant->db_name) ?></code></td>
                </tr>
                <tr>
                    <th><?= esc(lang('Platform.status')) ?></th>
                    <td><?= esc($tenant->status) ?></td>
                </tr>
            </tbody>
        </table>

        <?php if ($adopted): ?>
            <div class="alert alert-warning">
                <strong><?= esc(lang('Platform.adopted_not_deletable')) ?>.</strong>
                <?= esc(lang('Platform.adopted_explained', [$tenant->db_name])) ?>
            </div>
            <a class="btn btn-secondary" href="<?= base_url('platform/admin') ?>"><?= esc(lang('Platform.cancel')) ?></a>
        <?php else: ?>
            <p><?= esc(lang('Platform.confirm_delete_body')) ?></p>

            <?= form_open('platform/admin/' . esc($tenant->slug, 'url') . '/delete') ?>

            <div class="mb-4">
                <label class="form-label fw-bold" for="confirm_slug"><?= esc(lang('Platform.confirm_slug_label')) ?></label>
                <input class="form-control" type="text" id="confirm_slug" name="confirm_slug" value=""
                    autocomplete="off" autocapitalize="none" spellcheck="false" aria-describedby="confirm_slug_help">
                <div class="form-text" id="confirm_slug_help"><?= esc(lang('Platform.confirm_slug_help', [$tenant->slug])) ?></div>
            </div>

            <div class="card border-danger mb-4">
                <div class="card-header bg-danger text-white fw-bold">
                    <?= esc(lang('Platform.drop_schema_title')) ?>
                </div>
                <div class="card-body">
                    <p class="mb-3"><?= esc(lang('Platform.drop_schema_warning', [$tenant->db_name])) ?></p>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="drop_schema" name="drop_schema" value="1">
                        <label class="form-check-label text-danger" for="drop_schema"><?= esc(lang('Platform.drop_schema')) ?></label>
                    </div>

                    <label class="form-label" for="confirm_db_name"><?= esc(lang('Platform.confirm_db_name_label')) ?></label>
                    <input class="form-control" type="text" id="confirm_db_name" name="confirm_db_name" value=""
                        autocomplete="off" autocapitalize="none" spellcheck="false" aria-describedby="confirm_db_name_help">
                    <div class="form-text" id="confirm_db_name_help"><?= esc(lang('Platform.confirm_db_name_help', [$tenant->db_name])) ?></div>
                </div>
            </div>

            <button class="btn btn-danger" type="submit"><?= esc(lang('Platform.delete_business')) ?></button>
            <a class="btn btn-secondary" href="<?= base_url('platform/admin') ?>"><?= esc(lang('Platform.cancel')) ?></a>

            <?= form_close() ?>
        <?php endif; ?>
    </div>
</body>

</html>
