<?php

use Config\OSPOS;

/**
 * Table headers and rows of the presale campaigns screen.
 *
 * Kept out of tabular_helper.php on purpose: that file is shared by every module and is in the Coding
 * Standards baseline. The shape is the one of cashup_headers() / get_cash_up_data_row().
 */

/**
 * Columns of the campaigns list. The keys are the fields of the rows below.
 *
 * @return list<array<string, mixed>>
 */
function presale_campaign_headers(): array
{
    return [
        ['campaign_id' => lang('Common.id')],
        ['name'                => lang('Presale_campaigns.name')],
        ['sale_period'         => lang('Presale_campaigns.sale_period')],
        ['discount_percent'    => lang('Presale_campaigns.discount_percent')],
        ['min_initial_percent' => lang('Presale_campaigns.min_initial_percent')],
        ['active'              => lang('Presale_campaigns.active')],
        ['products'            => lang('Presale_campaigns.products')],
        ['dates'               => lang('Presale_campaigns.dates')],
        // Icon-only action: no title, the icon carries its own screen reader label.
        ['detail' => '', 'sortable' => false, 'escape' => false],
    ];
}

function get_presale_campaigns_manage_table_headers(): string
{
    return transform_headers(presale_campaign_headers());
}

/**
 * A stored Y-m-d date in the business's format.
 */
function presale_campaign_date(string $ymd): string
{
    $time = strtotime($ymd);

    return $time === false ? $ymd : date(config(OSPOS::class)->settings['dateformat'], $time);
}

/**
 * A percentage the way the business writes numbers: "12,5" or "12.5", never more than two decimals.
 */
function presale_campaign_percent(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    return to_decimals($value) . ' %';
}

/**
 * One row of the list. Plain values: bootstrap-table escapes every column except the ones declared
 * with 'escape' => false (the edit and detail anchors, built here from numbers only).
 *
 * @param array<string, mixed> $campaign a presale_campaigns row
 */
function get_presale_campaign_data_row(array $campaign, int $products, int $dates): array
{
    $id = (int) $campaign['campaign_id'];

    return [
        'campaign_id'         => $id,
        'name'                => $campaign['name'],
        'sale_period'         => presale_campaign_date((string) $campaign['sale_starts']) . ' - ' . presale_campaign_date((string) $campaign['sale_ends']),
        'discount_percent'    => presale_campaign_percent((string) $campaign['discount_percent']),
        'min_initial_percent' => presale_campaign_percent((string) $campaign['min_initial_percent']),
        'active'              => (int) $campaign['active'] === 1 ? lang('Presale_campaigns.yes') : lang('Presale_campaigns.no'),
        'products'            => $products,
        'dates'               => $dates,
        'detail'              => anchor(
            "presales/campaigns/detail/{$id}",
            '<span class="glyphicon glyphicon-list-alt"></span><span class="sr-only">' . esc(lang('Presale_campaigns.detail')) . '</span>',
            ['title' => lang('Presale_campaigns.detail')],
        ),
        'edit' => anchor(
            "presales/campaigns/view/{$id}",
            '<span class="glyphicon glyphicon-edit"></span>',
            ['class' => 'modal-dlg', 'data-btn-submit' => lang('Common.submit'), 'title' => lang('Presale_campaigns.update')],
        ),
    ];
}
