<?php

use App\Libraries\PlatformContext;
/**
 * @var array $tenants
 */
?>
<!doctype html>
<html lang="<?= esc(PlatformContext::LOCALE) ?>">

<head>
    <meta charset="utf-8">
    <base href="<?= base_url() ?>">
    <title><?= esc(lang('Platform.select_business')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="resources/bootswatch5/flatly/bootstrap.min.css">
</head>

<body class="bg-secondary-subtle d-flex flex-column">
    <main class="d-flex justify-content-around align-items-center flex-grow-1">
        <div class="container-fluid" style="max-width: 420px;">
            <div class="bg-body shadow rounded p-4">
                <h3 class="text-center mb-3"><?= esc(lang('Platform.select_business')) ?></h3>

                <div class="list-group">
                    <?php foreach ($tenants as $tenant): ?>
                        <a class="list-group-item list-group-item-action" href="<?= base_url('platform/select/' . esc($tenant->slug, 'url')) ?>">
                            <?= esc($tenant->slug) ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <a class="btn btn-link mt-3" href="<?= base_url('platform/logout') ?>"><?= esc(lang('Platform.logout')) ?></a>
            </div>
        </div>
    </main>
</body>

</html>
