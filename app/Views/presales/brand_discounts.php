<?php
/**
 * The discounts the brand pays back (docs/Funcional/venta-anticipada.md §4.16): every open or delivered
 * presale whose discount was marked reimbursable, and what the brand owes in total, split into what is
 * already delivered and what is still open. Read only. Same layout as portfolio.php.
 *
 * @var list<array<string, mixed>> $campaigns
 * @var int|null                   $campaign_id
 * @var array<string, mixed>       $report      Presale_report::brand_discounts(), each row with its number
 */
$totals = $report['totals'];
?>

<?= view('partial/header', [], ['saveData' => false]) ?>

<script type="text/javascript">
    $(document).ready(function() {
        dialog_support.init("a.modal-dlg");
    });
</script>

<div id="page_title"><?= esc(lang('Presale_reports.brand_discounts')) ?></div>

<form method="get" action="<?= esc(site_url('presales/brandDiscounts'), 'attr') ?>" class="form-inline print_hide" style="margin-bottom: 15px;">
    <label for="campaign_id"><?= esc(lang('Presale_reports.campaign')) ?></label>
    <select name="campaign_id" id="campaign_id" class="form-control input-sm">
        <option value=""><?= esc(lang('Presale_reports.all_campaigns')) ?></option>
        <?php foreach ($campaigns as $campaign) { ?>
            <option value="<?= (int) $campaign['campaign_id'] ?>"<?= (int) $campaign['campaign_id'] === (int) $campaign_id ? ' selected' : '' ?>><?= esc($campaign['name']) ?></option>
        <?php } ?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm"><?= esc(lang('Presale_reports.show')) ?></button>
    <a class="btn btn-default btn-sm" href="<?= esc(site_url('presales/brandDiscounts/csv') . ($campaign_id === null ? '' : '?campaign_id=' . (int) $campaign_id), 'attr') ?>"><?= esc(lang('Presale_reports.download_csv')) ?></a>
</form>

<p><?= esc(lang('Presale_reports.brand_discounts_note')) ?></p>

<?php if ($report['rows'] === []) { ?>
    <div class="alert alert-info" role="status"><?= esc(lang('Presale_reports.no_brand_discounts')) ?></div>
<?php } else { ?>
    <div class="table-responsive">
        <table id="brand_discount_totals" class="table table-bordered">
            <thead>
                <tr>
                    <th class="text-right"><?= esc(lang('Presale_reports.presales_count')) ?></th>
                    <th class="text-right"><?= esc(lang('Presale_reports.brand_owes_delivered')) ?></th>
                    <th class="text-right"><?= esc(lang('Presale_reports.brand_owes_open')) ?></th>
                    <th class="text-right"><?= esc(lang('Presale_reports.brand_owes')) ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="text-right"><?= (int) $totals['count'] ?></td>
                    <td class="text-right"><?= esc(to_currency($totals['delivered'])) ?></td>
                    <td class="text-right"><?= esc(to_currency($totals['open'])) ?></td>
                    <td class="text-right"><strong><?= esc(to_currency($totals['discount'])) ?></strong></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="table-responsive">
        <table id="brand_discount_rows" class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th><?= esc(lang('Presale_reports.number')) ?></th>
                    <th><?= esc(lang('Presale_reports.created_at')) ?></th>
                    <th><?= esc(lang('Presale_reports.customer')) ?></th>
                    <th><?= esc(lang('Presale_reports.campaign')) ?></th>
                    <th><?= esc(lang('Presale_reports.state')) ?></th>
                    <th><?= esc(lang('Presale_reports.sold_by')) ?></th>
                    <th class="text-right"><?= esc(lang('Presale_reports.discount_percent')) ?></th>
                    <th class="text-right"><?= esc(lang('Presale_reports.gross_total')) ?></th>
                    <th class="text-right"><?= esc(lang('Presale_reports.total')) ?></th>
                    <th class="text-right"><?= esc(lang('Presale_reports.discount_amount')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report['rows'] as $row) { ?>
                    <tr>
                        <td><a class="modal-dlg modal-dlg-wide" href="<?= esc(site_url('presales/view/' . (int) $row['presale_id']), 'attr') ?>"><?= esc($row['number']) ?></a></td>
                        <td><?= esc(to_date((int) strtotime($row['created_at']))) ?></td>
                        <td><?= esc(trim($row['first_name'] . ' ' . $row['last_name'])) ?></td>
                        <td><?= esc((string) $row['campaign_name']) ?></td>
                        <td><?= esc(lang('Presale_reports.status_' . $row['status'])) ?></td>
                        <td><?= esc(trim($row['employee_first_name'] . ' ' . $row['employee_last_name'])) ?></td>
                        <td class="text-right"><?= esc(rtrim(rtrim((string) $row['discount_percent'], '0'), '.')) ?>%</td>
                        <td class="text-right"><?= esc(to_currency((string) $row['gross_total'])) ?></td>
                        <td class="text-right"><?= esc(to_currency((string) $row['total'])) ?></td>
                        <td class="text-right"><?= esc(to_currency($row['discount'])) ?></td>
                    </tr>
                <?php } ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="7" class="text-right"><?= esc(lang('Presale_reports.total')) ?></th>
                    <th class="text-right"><?= esc(to_currency($totals['gross'])) ?></th>
                    <th class="text-right"><?= esc(to_currency($totals['total'])) ?></th>
                    <th class="text-right"><?= esc(to_currency($totals['discount'])) ?></th>
                </tr>
            </tfoot>
        </table>
    </div>
<?php } ?>

<?= view('partial/footer', [], ['saveData' => false]) ?>
