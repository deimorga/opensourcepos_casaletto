<?php
/**
 * @var string|null $error
 */
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <base href="<?= base_url() ?>">
    <title><?= esc(lang('Platform.new_business')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="resources/bootswatch5/flatly/bootstrap.min.css">
</head>

<body class="bg-secondary-subtle">
    <div class="container py-4" style="max-width: 480px;">
        <h3 class="mb-3"><?= esc(lang('Platform.new_business')) ?></h3>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= esc($error) ?></div>
        <?php endif; ?>

        <?= form_open('platform/admin/create') ?>
        <div class="mb-3">
            <label class="form-label" for="slug"><?= esc(lang('Platform.slug')) ?></label>
            <input class="form-control" type="text" id="slug" name="slug" pattern="[a-z0-9-]{1,20}" required>
        </div>
        <div class="mb-3">
            <label class="form-label" for="company_name"><?= esc(lang('Platform.company_name')) ?></label>
            <input class="form-control" type="text" id="company_name" name="company_name">
        </div>
        <button class="btn btn-primary" type="submit"><?= esc(lang('Platform.create')) ?></button>
        <a class="btn btn-link" href="<?= base_url('platform/admin') ?>"><?= esc(lang('Platform.cancel')) ?></a>
        <?= form_close() ?>
    </div>
</body>

</html>
