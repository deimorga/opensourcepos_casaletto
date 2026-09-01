<?php

/**
 * Superadministradores.
 *
 * La pregunta que contesta esta tabla no es «qué cuentas hay» sino «cuál de estas no debería
 * existir». Por eso las dos columnas que parecen decorativas -- quién la creó y último ingreso --
 * son las importantes: una cuenta que nadie creó desde aquí y con la que nadie ha entrado nunca es
 * la huérfana, y se ve sin tener que compararla con nada.
 *
 * La fila marcada lleva SIEMPRE las dos frases escritas además del color. Un semáforo que solo es
 * color no lo lee ni quien no distingue ese color ni quien imprime la pantalla.
 *
 * @var list<array{row: object, locked: bool, orphan: bool, is_self: bool, deletable: bool}> $accounts
 * @var int                                                                                  $admin_count
 */
$this->extend('platform/console_layout');
$this->section('content');

$when = static fn (?string $value): ?string => $value === null || $value === ''
    ? null
    : date('Y-m-d H:i', (int) strtotime($value));
?>

<p class="text-body-secondary"><?= esc(lang('Platform.accounts_intro')) ?></p>

<div class="mb-3">
    <a class="btn btn-primary" href="<?= base_url('platform/accounts/new') ?>"><?= esc(lang('Platform.new_account')) ?></a>
</div>

<?php if ($admin_count <= 1): ?>
    <div class="alert alert-warning" role="alert"><?= esc(lang('Platform.accounts_only_one_admin')) ?></div>
<?php endif; ?>

<?php if ($accounts === []): ?>
    <p class="text-body-secondary" role="status"><?= esc(lang('Platform.accounts_empty')) ?></p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle bg-body">
            <thead>
                <tr>
                    <th scope="col"><?= esc(lang('Platform.account_email')) ?></th>
                    <th scope="col"><?= esc(lang('Platform.account_role')) ?></th>
                    <th scope="col"><?= esc(lang('Platform.account_created_at')) ?></th>
                    <th scope="col"><?= esc(lang('Platform.account_created_by')) ?></th>
                    <th scope="col"><?= esc(lang('Platform.account_last_login')) ?></th>
                    <th scope="col"><?= esc(lang('Platform.account_second_factor')) ?></th>
                    <th scope="col"><?= esc(lang('Platform.status')) ?></th>
                    <th scope="col"><?= esc(lang('Platform.actions')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($accounts as $entry): ?>
                    <?php $account = $entry['row']; ?>
                    <tr<?= $entry['orphan'] ? ' class="table-warning"' : '' ?>>
                        <th scope="row" class="fw-normal">
                            <?= esc($account->email) ?>
                            <?php if ($entry['is_self']): ?>
                                <span class="badge text-bg-light border"><?= esc(lang('Platform.account_you')) ?></span>
                            <?php endif; ?>
                        </th>
                        <td>
                            <?= esc((bool) $account->is_platform_admin
                                ? lang('Platform.account_role_admin')
                                : lang('Platform.account_role_owner')) ?>
                        </td>
                        <td><?= esc($when($account->created_at) ?? '—') ?></td>
                        <td>
                            <?php if ($account->created_by_email !== null): ?>
                                <?= esc($account->created_by_email) ?>
                            <?php else: ?>
                                <span class="fw-bold" title="<?= esc(lang('Platform.account_created_from_cli_help')) ?>">
                                    <?= esc(lang('Platform.account_created_from_cli')) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($account->last_login_at !== null): ?>
                                <?= esc($when($account->last_login_at)) ?>
                            <?php else: ?>
                                <span class="fw-bold"><?= esc(lang('Platform.account_never_logged_in')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= esc($account->totp_enabled_at !== null
                                ? lang('Platform.account_second_factor_on')
                                : lang('Platform.account_second_factor_off')) ?>
                        </td>
                        <td>
                            <?php if ($entry['locked']): ?>
                                <span class="badge text-bg-danger"
                                    title="<?= esc(lang('Platform.account_locked_explained')) ?>">
                                    <?= esc(lang('Platform.account_locked')) ?>
                                </span>
                                <div class="small text-body-secondary">
                                    <?= esc(lang('Platform.account_locked_since', [$when($account->failed_login_first_at)])) ?>
                                </div>
                                <div class="small text-body-secondary">
                                    <?= esc(lang('Platform.account_failed_attempts', [(int) $account->failed_login_count])) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-nowrap">
                            <?php if ($entry['locked'] && ! $entry['is_self']): ?>
                                <?= form_open('platform/accounts/' . (int) $account->id . '/unlock', ['class' => 'd-inline']) ?>
                                <button class="btn btn-sm btn-outline-success" type="submit">
                                    <?= esc(lang('Platform.account_unlock')) ?>
                                </button>
                                <?= form_close() ?>
                            <?php endif; ?>

                            <?php if ($entry['deletable']): ?>
                                <a class="btn btn-sm btn-outline-danger"
                                    href="<?= base_url('platform/accounts/' . (int) $account->id . '/delete') ?>">
                                    <?= esc(lang('Platform.account_delete')) ?>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?= $this->endSection() ?>
