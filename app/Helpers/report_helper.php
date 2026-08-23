<?php

/**
 * @param string $report_name
 * @param string $report_prefix
 * @param string $lang_key
 * @return array
 */
function get_report_link(string $report_name, string $report_prefix = '', string $lang_key = ''): array
{
    $path = 'reports/';
    if ($report_prefix !== '') {
        $path .= $report_prefix . '_';
    }

    /**
     * Sanitize the report name in case it has come from the permissions table.
     */
    $report_name = str_replace('reports_', '', $report_name);
    $path .= $report_name;

    if ($lang_key === '') {
        $lang_key = 'Reports.' . $report_name;
    }

    return [
        'path'  => site_url($path),
        'label' => lang($lang_key),
    ];
}

/**
 * @param string $permission_id
 * @param string[] $restrict_views
 *
 * @return bool
 */
function can_show_report(string $permission_id, array $restrict_views = []): bool
{
    if (!str_contains($permission_id, 'reports_')) {
        return false;
    }

    // The graphical and summary panels build their links by iterating every reports_* permission, so
    // a permission without a matching reports/graphical_x and reports/summary_x route would appear
    // there on its own, pointing at nothing. Analytical reports have their own panel and their own
    // route, so they are excluded here the way inventory and receiving already are.
    if (str_contains($permission_id, 'analytics')) {
        return false;
    }

    foreach ($restrict_views as $restrict_view) {
        if (str_contains($permission_id, $restrict_view)) {
            return false;
        }
    }

    return true;
}
