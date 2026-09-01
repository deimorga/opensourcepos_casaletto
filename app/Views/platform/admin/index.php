<?php

/**
 * Listado de negocios.
 *
 * Los adoptados (Casaletto) aparecen igual que los demás -- se gestionan como
 * los demás -- pero sin enlace de eliminar, y con el motivo escrito al lado.
 * Esconder la acción sin explicarla solo produce la pregunta de por qué no
 * está.
 *
 * ENTREGA 2: DOS CAMBIOS, LOS DOS PEQUEÑOS
 *
 * La pantalla pasa a usar la envoltura común de la consola, que es lo que le da la barra de
 * navegación. Sin ella, las pantallas nuevas -- superadministradores y registro de actividad --
 * solo se alcanzarían tecleando su dirección.
 *
 * Y la etiqueta «Adoptado» deja de ser `bg-info text-dark`. En Flatly eso es gris #7b8a8b sobre
 * azul #3498db: 1,14:1 de contraste, o sea prácticamente invisible, y hace falta 4,5:1. Ahora es
 * texto negro sobre el gris claro del tema, con borde para que se siga leyendo como etiqueta.
 *
 * @var array               $tenants
 * @var array<string, bool> $adopted slug => adoptado
 */
$this->extend('platform/console_layout');
$this->section('content');
?>

<div class="mb-3">
    <a class="btn btn-primary" href="<?= base_url('platform/admin/new') ?>"><?= esc(lang('Platform.new_business')) ?></a>
</div>

<div class="table-responsive">
    <table class="table table-bordered align-middle bg-body">
        <thead>
            <tr>
                <th scope="col"><?= esc(lang('Platform.slug')) ?></th>
                <th scope="col"><?= esc(lang('Platform.database')) ?></th>
                <th scope="col"><?= esc(lang('Platform.status')) ?></th>
                <th scope="col"><?= esc(lang('Platform.actions')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tenants as $tenant): ?>
                <tr>
                    <th scope="row" class="fw-normal">
                        <?= esc($tenant->slug) ?>
                        <?php if ($adopted[$tenant->slug] ?? false): ?>
                            <span class="badge text-bg-light border"><?= esc(lang('Platform.adopted')) ?></span>
                        <?php endif; ?>
                    </th>
                    <td><?= esc($tenant->db_name) ?></td>
                    <td>
                        <span class="badge <?= $tenant->status === 'active' ? 'text-bg-success' : 'text-bg-secondary' ?>">
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
                        <?php if ($adopted[$tenant->slug] ?? false): ?>
                            <span class="text-body-secondary small ms-1" title="<?= esc(lang('Platform.adopted_explained', [$tenant->db_name])) ?>">
                                <?= esc(lang('Platform.adopted_not_deletable')) ?>
                            </span>
                        <?php else: ?>
                            <a class="btn btn-sm btn-outline-danger" href="<?= base_url('platform/admin/' . esc($tenant->slug, 'url') . '/delete') ?>"><?= esc(lang('Platform.delete')) ?></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?= $this->endSection() ?>
