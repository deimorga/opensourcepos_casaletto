<?php
/**
 * @var string $slug
 */
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <base href="<?= base_url() ?>">
    <title><?= esc(lang('Platform.confirm_delete_title')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="resources/bootswatch5/flatly/bootstrap.min.css">
</head>

<body class="bg-secondary-subtle">
    <div class="container py-4" style="max-width: 480px;">
        <h3 class="mb-3"><?= esc(lang('Platform.confirm_delete_title')) ?>: <?= esc($slug) ?></h3>

        <p><?= esc(lang('Platform.confirm_delete_body')) ?></p>

        <?= form_open('platform/admin/' . esc($slug, 'url') . '/delete') ?>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="drop_schema" name="drop_schema" value="1">
            <label class="form-check-label text-danger" for="drop_schema"><?= esc(lang('Platform.drop_schema')) ?></label>
        </div>
        <button class="btn btn-danger" type="submit"><?= esc(lang('Platform.delete')) ?></button>
        <a class="btn btn-link" href="<?= base_url('platform/admin') ?>"><?= esc(lang('Platform.cancel')) ?></a>
        <?= form_close() ?>
    </div>
</body>

</html>
