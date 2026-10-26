<?php
/**
 * The presales portfolio: what the open presales sold, collected and still owe, the overdue by age,
 * what falls due in the next seven days and who is late, with their phone and email to call them.
 * Read only. Same layout as committed.php: the shared header, a filter form and plain tables.
 *
 * Each presale number opens the presale's detail in a dialog, as in the list, to take a payment there.
 *
 * @var list<array<string, mixed>> $campaigns
 * @var list<string>               $dates         Y-m-d
 * @var int|null                   $campaign_id
 * @var string|null                $delivery_date Y-m-d
 * @var array<string, mixed>       $portfolio     Presale_report::portfolio(), each row with its number
 * @var string                     $csv_query
 */
$presale_link = static fn (array $row): string => '<a class="modal-dlg modal-dlg-wide"'
    . ' href="' . esc(site_url('presales/view/' . (int) $row['presale_id']), 'attr') . '"'
    . ' title="' . esc(lang('Presale_reports.view_presale') . ' ' . $row['number'], 'attr') . '">'
    . esc($row['number']) . '</a>';
$customer_name = static fn (array $row): string => trim($row['first_name'] . ' ' . $row['last_name']);
$totals        = $portfolio['totals'];
?>

<?= view('partial/header', [], ['saveData' => false]) ?>

<script type="text/javascript">
    $(document).ready(function() {
        dialog_support.init("a.modal-dlg");
    });
</script>

<div id="page_title"><?= esc(lang('Presale_reports.portfolio')) ?></div>

<form method="get" action="<?= esc(site_url('presales/portfolio'), 'attr') ?>" class="form-inline print_hide" style="margin-bottom: 15px;">
    <label for="campaign_id"><?= esc(lang('Presale_reports.campaign')) ?></label>
    <select name="campaign_id" id="campaign_id" class="form-control input-sm">
        <option value=""><?= esc(lang('Presale_reports.all_campaigns')) ?></option>
        <?php foreach ($campaigns as $campaign) { ?>
            <option value="<?= (int) $campaign['campaign_id'] ?>"<?= (int) $campaign['campaign_id'] === (int) $campaign_id ? ' selected' : '' ?>><?= esc($campaign['name']) ?></option>
        <?php } ?>
    </select>
    <label for="delivery_date"><?= esc(lang('Presale_reports.delivery_date')) ?></label>
    <select name="delivery_date" id="delivery_date" class="form-control input-sm">
        <option value=""><?= esc(lang('Presale_reports.all_dates')) ?></option>
        <?php foreach ($dates as $date) { ?>
            <option value="<?= esc($date, 'attr') ?>"<?= $date === $delivery_date ? ' selected' : '' ?>><?= esc(to_date((int) strtotime($date))) ?></option>
        <?php } ?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm"><?= esc(lang('Presale_reports.show')) ?></button>
    <a class="btn btn-default btn-sm" href="<?= esc(site_url('presales/portfolio/csv') . ($csv_query === '' ? '' : '?' . $csv_query), 'attr') ?>"><?= esc(lang('Presale_reports.download_csv')) ?></a>
</form>

<?php if ($portfolio['rows'] === []) { ?>
    <div class="alert alert-info" role="status"><?= esc(lang('Presale_reports.no_open_presales')) ?></div>
<?php } else { ?>
    <div class="table-responsive">
        <table id="portfolio_totals" class="table table-bordered">
            <thead>
                <tr>
                    <th class="text-right"><?= esc(lang('Presale_reports.sold')) ?></th>
                    <th class="text-right"><?= esc(lang('Presale_reports.collected')) ?></th>
                    <th class="text-right"><?= esc(lang('Presale_reports.receivable')) ?></th>
                    <th class="text-right"><?= esc(lang('Presale_reports.overdue')) ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="text-right"><strong><?= esc(to_currency($totals['sold'])) ?></strong></td>
                    <td class="text-right"><strong><?= esc(to_currency($totals['collected'])) ?></strong></td>
                    <td class="text-right"><strong><?= esc(to_currency($totals['receivable'])) ?></strong></td>
                    <td class="text-right<?= bccomp($totals['overdue'], '0', 2) > 0 ? ' danger' : '' ?>"><strong><?= esc(to_currency($totals['overdue'])) ?></strong></td>
                </tr>
            </tbody>
        </table>
    </div>
    <p class="help-block"><?= esc(lang('Presale_reports.overdue_note')) ?></p>

    <div class="row">
        <div class="col-sm-5">
            <h4><?= esc(lang('Presale_reports.aging')) ?></h4>
            <table id="portfolio_aging" class="table table-striped table-bordered">
                <thead>
                    <tr>
                        <th><?= esc(lang('Presale_reports.days_late')) ?></th>
                        <th class="text-right"><?= esc(lang('Presale_reports.amount')) ?></th>
                        <th class="text-right"><?= esc(lang('Presale_reports.presales_count')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($portfolio['aging'] as $bracket => $figures) { ?>
                        <tr>
                            <td><?= esc(lang('Presale_reports.aging_' . $bracket)) ?></td>
                            <td class="text-right"><?= esc(to_currency($figures['amount'])) ?></td>
                            <td class="text-right"><?= (int) $figures['count'] ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <div class="col-sm-7">
            <h4><?= esc(lang('Presale_reports.upcoming')) ?>: <?= esc(to_currency($portfolio['upcoming']['amount'])) ?></h4>
            <?php if ($portfolio['upcoming']['rows'] === []) { ?>
                <p class="text-muted"><?= esc(lang('Presale_reports.nothing_upcoming')) ?></p>
            <?php } else { ?>
                <div class="table-responsive">
                    <table id="portfolio_upcoming" class="table table-striped table-bordered table-hover">
                        <thead>
                            <tr>
                                <th><?= esc(lang('Presale_reports.number')) ?></th>
                                <th><?= esc(lang('Presale_reports.customer')) ?></th>
                                <th><?= esc(lang('Presale_reports.phone')) ?></th>
                                <th><?= esc(lang('Presale_reports.due_date')) ?></th>
                                <th class="text-right"><?= esc(lang('Presale_reports.amount')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($portfolio['upcoming']['rows'] as $row) { ?>
                                <tr>
                                    <td><?= $presale_link($row) ?></td>
                                    <td><?= esc($customer_name($row)) ?></td>
                                    <td><?= esc($row['phone_number']) ?></td>
                                    <td><?= esc(to_date((int) strtotime($row['due_date']))) ?></td>
                                    <td class="text-right"><?= esc(to_currency($row['amount'])) ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } ?>
        </div>
    </div>

    <h4><?= esc(lang('Presale_reports.late_list')) ?></h4>
    <?php if ($portfolio['late'] === []) { ?>
        <div class="alert alert-success" role="status"><?= esc(lang('Presale_reports.nothing_late')) ?></div>
    <?php } else { ?>
        <div class="table-responsive">
            <table id="portfolio_late" class="table table-striped table-bordered table-hover">
                <thead>
                    <tr>
                        <th><?= esc(lang('Presale_reports.number')) ?></th>
                        <th><?= esc(lang('Presale_reports.customer')) ?></th>
                        <th><?= esc(lang('Presale_reports.phone')) ?></th>
                        <th><?= esc(lang('Presale_reports.email')) ?></th>
                        <th><?= esc(lang('Presale_reports.campaign')) ?></th>
                        <th><?= esc(lang('Presale_reports.delivery_date')) ?></th>
                        <th class="text-right"><?= esc(lang('Presale_reports.overdue')) ?></th>
                        <th class="text-right"><?= esc(lang('Presale_reports.days_late')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($portfolio['late'] as $row) { ?>
                        <tr>
                            <td><?= $presale_link($row) ?></td>
                            <td><?= esc($customer_name($row)) ?></td>
                            <td><?= esc($row['phone_number']) ?></td>
                            <td><?= esc($row['email']) ?></td>
                            <td><?= esc($row['campaign_name']) ?></td>
                            <td><?= esc(to_date((int) strtotime($row['delivery_date']))) ?></td>
                            <td class="text-right"><strong><?= esc(to_currency($row['overdue'])) ?></strong></td>
                            <td class="text-right"><?= (int) $row['days_late'] ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
<?php } ?>

<?= view('partial/footer', [], ['saveData' => false]) ?>
