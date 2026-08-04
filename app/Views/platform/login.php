<?php
/**
 * @var bool $has_errors
 * @var string|null $error
 */
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <base href="<?= base_url() ?>">
    <title><?= esc(lang('Platform.login')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="resources/bootswatch5/flatly/bootstrap.min.css">
    <link rel="stylesheet" href="css/login.css">
</head>

<body class="bg-secondary-subtle d-flex flex-column">
    <main class="d-flex justify-content-around align-items-center flex-grow-1">
        <div class="container-fluid" style="max-width: 420px;">
            <div class="bg-body shadow rounded p-4">
                <h3 class="text-center mb-3"><?= esc(lang('Platform.login')) ?></h3>

                <?php if (session()->getFlashdata('error')): ?>
                    <div class="alert alert-danger"><?= esc(session()->getFlashdata('error')) ?></div>
                <?php endif; ?>

                <?php if ($has_errors): ?>
                    <div class="alert alert-danger"><?= esc($error) ?></div>
                <?php endif; ?>

                <?= form_open('platform/login') ?>
                <div class="mb-3">
                    <label class="form-label" for="email"><?= esc(lang('Platform.email')) ?></label>
                    <input class="form-control" type="email" id="email" name="email" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password"><?= esc(lang('Platform.password')) ?></label>
                    <input class="form-control" type="password" id="password" name="password" required>
                </div>
                <button class="btn btn-primary w-100" type="submit"><?= esc(lang('Platform.go')) ?></button>
                <?= form_close() ?>
            </div>
        </div>
    </main>
</body>

</html>
