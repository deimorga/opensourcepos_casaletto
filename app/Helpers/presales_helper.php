<?php

/**
 * The presales list: its columns and how one row is drawn.
 *
 * Kept out of tabular_helper.php on purpose: that file is upstream's and every module's, and the
 * presales module must be removable without touching it.
 *
 * Every value is escaped here or by bootstrap-table (`escape: true` on every column but the ones
 * marked otherwise). The state label is the one column that carries markup, so its text is escaped
 * before it is wrapped.
 */

use App\Models\Presale;

/**
 * The columns of the list. Only stored values sort; the derived ones (state, days late, next
 * instalment) are worked out per row and cannot be sorted by the database.
 *
 * @return list<array<string, mixed>>
 */
function presales_headers(): array
{
    return [
        ['number' => lang('Presales.number')],
        ['customer'      => lang('Presales.customer')],
        ['phone'         => lang('Presales.phone'), 'sortable' => false],
        ['campaign'      => lang('Presales.campaign')],
        ['delivery_date' => lang('Presales.delivery_date')],
        ['total'         => lang('Presales.total')],
        ['paid'          => lang('Presales.paid')],
        ['balance'       => lang('Presales.balance')],
        ['state'         => lang('Presales.state'), 'sortable' => false, 'escape' => false],
        ['days_late'     => lang('Presales.days_late'), 'sortable' => false],
        ['next_due'      => lang('Presales.next_due'), 'sortable' => false],
    ];
}

/**
 * The headers as bootstrap-table reads them: no selection checkbox, because the list has no bulk
 * action, and the usual right-most column with the link that opens a presale.
 */
function get_presales_manage_table_headers(): string
{
    return transform_headers(presales_headers(), true);
}

/**
 * The label of a derived state, already escaped and wrapped so it reads on screen and in print.
 * Never colour alone: the word is always there.
 */
function presale_state_label(string $state): string
{
    $class = match ($state) {
        Presale::STATE_LATE       => 'label-danger',
        Presale::STATE_PAID       => 'label-success',
        Presale::STATUS_CANCELED  => 'label-default',
        Presale::STATUS_DELIVERED => 'label-primary',
        default                   => 'label-info',
    };

    return '<span class="label ' . $class . '">' . esc(lang('Presales.state_' . $state)) . '</span>';
}

/**
 * One row of the list.
 *
 * @param array<string, mixed> $row     The presale as the search returns it, with its customer and campaign.
 * @param array<string, mixed> $derived What Presale::derive() answered for it today.
 */
function get_presale_data_row(array $row, array $derived, string $number): array
{
    $id = (int) $row['presale_id'];

    $next_due = '';

    if ($derived['next_due_date'] !== null && in_array($derived['state'], [Presale::STATE_LATE, Presale::STATE_UP_TO_DATE], true)) {
        $next_due = to_date(strtotime((string) $derived['next_due_date'])) . ' · ' . to_currency($derived['next_due_amount']);
    }

    return [
        'presale_id'    => $id,
        'number'        => $number,
        'customer'      => trim($row['first_name'] . ' ' . $row['last_name']),
        'phone'         => (string) ($row['phone_number'] ?? ''),
        'campaign'      => (string) $row['campaign_name'],
        'delivery_date' => to_date(strtotime((string) $row['delivery_date'])),
        'total'         => to_currency((string) $row['total']),
        'paid'          => to_currency($derived['paid']),
        'balance'       => $row['status'] === Presale::STATUS_OPEN ? to_currency($derived['balance']) : '',
        'state'         => presale_state_label($derived['state']),
        'days_late'     => $derived['days_late'] > 0 ? (string) $derived['days_late'] : '',
        'next_due'      => $next_due,
        'edit'          => anchor(
            'presales/view/' . $id,
            '<span class="glyphicon glyphicon-eye-open"></span><span class="sr-only">' . esc(lang('Presales.view')) . '</span>',
            // anchor() escapes attribute values itself.
            ['class' => 'modal-dlg modal-dlg-wide', 'title' => lang('Presales.view') . ' ' . $number],
        ),
    ];
}
