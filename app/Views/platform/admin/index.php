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
 * ENTREGA 3: LA TABLA PASA A CONTESTAR «QUÉ NEGOCIO ES ESTE»
 *
 * Mostraba el slug, el nombre técnico de la base y el estado. Con dos negocios ya se leía mal y el
 * nombre real no estaba en ninguna parte porque no se guardaba. Ahora la primera columna es el
 * nombre, y el slug pasa a ser lo que es: un detalle técnico, debajo y en pequeño.
 *
 * Las filas dadas de alta antes de que existiera la columna se quedan sin nombre, y lo dicen. No se
 * les inventa uno.
 *
 * @var array                 $tenants
 * @var array<string, bool>   $adopted slug => adoptado
 * @var array<string, string> $names   slug => nombre real, o el slug si no hay ninguno guardado
 * @var array<string, string> $urls    slug => su dirección pública
 */
$this->extend('platform/console_layout');
$this->section('content');

$when = static fn (?string $value): ?string => $value === null || $value === ''
    ? null
    : date('Y-m-d', (int) strtotime($value));
?>

<div class="mb-3">
    <a class="btn btn-primary" href="<?= base_url('platform/admin/new') ?>"><?= esc(lang('Platform.new_business')) ?></a>
</div>

<div class="table-responsive">
    <table class="table table-bordered align-middle bg-body">
        <thead>
            <tr>
                <th scope="col"><?= esc(lang('Platform.business_name')) ?></th>
                <th scope="col"><?= esc(lang('Platform.business_address')) ?></th>
                <th scope="col"><?= esc(lang('Platform.database')) ?></th>
                <th scope="col"><?= esc(lang('Platform.created_at')) ?></th>
                <th scope="col"><?= esc(lang('Platform.status')) ?></th>
                <th scope="col"><?= esc(lang('Platform.actions')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tenants as $tenant): ?>
                <?php $named = trim((string) ($tenant->company_name ?? '')) !== ''; ?>
                <tr>
                    <th scope="row" class="fw-normal">
                        <a href="<?= base_url('platform/admin/' . rawurlencode((string) $tenant->slug)) ?>">
                            <?= esc($names[$tenant->slug] ?? $tenant->slug) ?>
                        </a>
                        <?php if (! $named): ?>
                            <span class="text-body-secondary small">(<?= esc(lang('Platform.business_name_unknown')) ?>)</span>
                        <?php endif; ?>
                        <?php if ($adopted[$tenant->slug] ?? false): ?>
                            <span class="badge text-bg-light border"><?= esc(lang('Platform.adopted')) ?></span>
                        <?php endif; ?>
                        <div class="text-body-secondary small"><code><?= esc($tenant->slug) ?></code></div>
                    </th>
                    <td>
                        <a href="<?= esc($urls[$tenant->slug] ?? '', 'attr') ?>" rel="noopener noreferrer" target="_blank">
                            <?= esc($urls[$tenant->slug] ?? '') ?>
                        </a>
                    </td>
                    <td><code><?= esc($tenant->db_name) ?></code></td>
                    <td><?= esc($when($tenant->created_at ?? null) ?? lang('Platform.never')) ?></td>
                    <td>
                        <span class="badge <?= $tenant->status === 'active' ? 'text-bg-success' : 'text-bg-secondary' ?>">
                            <?= esc($tenant->status) ?>
                        </span>
                    </td>
                    <td>
                        <a class="btn btn-sm btn-outline-primary" href="<?= base_url('platform/admin/' . rawurlencode((string) $tenant->slug)) ?>"><?= esc(lang('Platform.open_business')) ?></a>
                        <?php
                            // «Abrir» lleva a la ficha; esto entra al punto de venta. Va aquí además
                            // de en la ficha porque desde el listado lo único a mano era el enlace de
                            // la dirección, que deja al operador en el FORMULARIO de entrada -- que
                            // es justo lo que el pase de la Entrega 5 vino a evitar.
                            //
                            // Solo para un negocio activo: uno suspendido no resuelve, así que el
                            // pase se gastaría contra una puerta que no abre.
                        ?>
                        <?php if ($tenant->status === 'active'): ?>
                            <a class="btn btn-sm btn-primary"
                               href="<?= base_url('platform/admin/' . rawurlencode((string) $tenant->slug) . '/enter') ?>"><?= esc(lang('Platform.enter_business')) ?></a>
                        <?php endif; ?>
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
