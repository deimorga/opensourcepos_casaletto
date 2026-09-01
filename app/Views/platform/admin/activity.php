<?php

/**
 * El registro de actividad.
 *
 * La columna «Quién» sale de `account_email`, que está desnormalizado A PROPÓSITO: la fila que dice
 * quién eliminó la cuenta huérfana tiene que seguir leyéndose el día en que esa cuenta -- o la que
 * la eliminó -- ya no exista. Por eso aquí no hay ningún join contra `platform_accounts`, y meterlo
 * más adelante «para mostrar el correo actual» volvería ilegibles justamente las filas que más
 * importan.
 *
 * El nombre de cada acción se traduce a partir de la propia acción: `tenant.created` ->
 * `Platform.action_tenant_created`. Una acción que alguien añada al modelo sin traducirla se ve
 * cruda, con su nombre técnico, y no como un hueco en blanco.
 *
 * @var list<object> $entries
 */
$this->extend('platform/console_layout');
$this->section('content');

$actionLabel = static function (string $action): string {
    $key   = 'Platform.action_' . str_replace('.', '_', $action);
    $label = lang($key);

    return $label === $key ? $action : $label;
};

/**
 * El detalle se guardó como JSON. Se muestra como pares clave: valor y no como el JSON crudo, que
 * a partir de tres claves deja de leerse. Las claves son técnicas y se dejan tal cual: esta
 * columna la lee quien opera la consola, no un cliente.
 */
$detailPairs = static function (?string $json): array {
    if ($json === null || $json === '') {
        return [];
    }

    $decoded = json_decode($json, true);

    if (! is_array($decoded)) {
        return ['' => $json];
    }

    $pairs = [];

    foreach ($decoded as $key => $value) {
        if (is_bool($value)) {
            $value = $value ? lang('Platform.yes') : lang('Platform.no');
        } elseif (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        $pairs[(string) $key] = (string) $value;
    }

    return $pairs;
};
?>

<p class="text-body-secondary"><?= esc(lang('Platform.activity_intro')) ?></p>

<?php if ($entries === []): ?>
    <p class="text-body-secondary" role="status"><?= esc(lang('Platform.activity_empty')) ?></p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle bg-body">
            <thead>
                <tr>
                    <th scope="col"><?= esc(lang('Platform.activity_when')) ?></th>
                    <th scope="col"><?= esc(lang('Platform.activity_who')) ?></th>
                    <th scope="col"><?= esc(lang('Platform.activity_action')) ?></th>
                    <th scope="col"><?= esc(lang('Platform.activity_target')) ?></th>
                    <th scope="col"><?= esc(lang('Platform.activity_detail')) ?></th>
                    <th scope="col"><?= esc(lang('Platform.activity_ip')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($entries as $entry): ?>
                    <tr>
                        <td class="text-nowrap">
                            <?= esc(date('Y-m-d H:i:s', (int) strtotime((string) $entry->created_at))) ?>
                        </td>
                        <td>
                            <?php if ($entry->account_email !== null && $entry->account_email !== ''): ?>
                                <?= esc($entry->account_email) ?>
                            <?php else: ?>
                                <span class="text-body-secondary"><?= esc(lang('Platform.activity_from_cli')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= esc($actionLabel((string) $entry->action)) ?></td>
                        <td>
                            <?php if ($entry->target_type === 'tenant'): ?>
                                <?= esc(lang('Platform.activity_target_tenant')) ?>
                                <code><?= esc((string) $entry->target_id) ?></code>
                            <?php elseif ($entry->target_type === 'account'): ?>
                                <?= esc(lang('Platform.activity_target_account')) ?>
                                <code><?= esc((string) $entry->target_id) ?></code>
                            <?php else: ?>
                                <span class="text-body-secondary">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php foreach ($detailPairs($entry->detail) as $key => $value): ?>
                                <div>
                                    <?php if ($key !== ''): ?>
                                        <span class="text-body-secondary"><?= esc($key) ?>:</span>
                                    <?php endif; ?>
                                    <?= esc($value) ?>
                                </div>
                            <?php endforeach; ?>
                        </td>
                        <td class="text-nowrap small"><?= esc((string) $entry->ip_address) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?= $this->endSection() ?>
