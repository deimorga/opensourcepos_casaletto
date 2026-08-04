<?php
/**
 * @var array $tenants
 */
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <base href="<?= base_url() ?>">
    <title><?= esc(lang('Platform.admin_panel_title')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="resources/bootswatch5/flatly/bootstrap.min.css">
</head>

<body class="bg-secondary-subtle">
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="m-0"><?= esc(lang('Platform.admin_panel_title')) ?></h3>
            <div>
                <a class="btn btn-primary" href="<?= base_url('platform/admin/new') ?>"><?= esc(lang('Platform.new_business')) ?></a>
                <a class="btn btn-link" href="<?= base_url('platform/logout') ?>"><?= esc(lang('Platform.logout')) ?></a>
            </div>
        </div>

        <?php if (session()->getFlashdata('message')): ?>
            <div class="alert alert-success"><?= esc(session()->getFlashdata('message')) ?></div>
        <?php endif; ?>
        <?php if (session()->getFlashdata('error')): ?>
            <div class="alert alert-danger"><?= esc(session()->getFlashdata('error')) ?></div>
        <?php endif; ?>

        <table class="table table-bordered bg-body">
            <thead>
                <tr>
                    <th>Slug</th>
                    <th>DB</th>
                    <th><?= esc(lang('Platform.status')) ?></th>
                    <th><?= esc(lang('Platform.actions')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tenants as $tenant): ?>
                    <tr>
                        <td><?= esc($tenant->slug) ?></td>
                        <td><?= esc($tenant->db_name) ?></td>
                        <td>
                            <span class="badge <?= $tenant->status === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                                <?= esc($tenant->status) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($tenant->status === 'active'): ?>
                                <?= form_open('platform/admin/' . esc($tenant->slug, 'url') . '/suspend', ['class' => 'd-inline']) ?>
                                <button class="btn btn-sm btn-outline-warning" type="submit"><?= esc(lang('Platform.suspend')) ?></button>
                                <?= form_close() ?>
                            <?php else: ?>
                                <?= form_open('platform/admin/' . esc($tenant->slug, 'url') . '/activate', ['class' => 'd-inline']) ?>
                                <button class="btn btn-sm btn-outline-success" type="submit"><?= esc(lang('Platform.activate')) ?></button>
                                <?= form_close() ?>
                            <?php endif; ?>
                            <a class="btn btn-sm btn-outline-danger" href="<?= base_url('platform/admin/' . esc($tenant->slug, 'url') . '/delete') ?>"><?= esc(lang('Platform.delete')) ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>

</html>
