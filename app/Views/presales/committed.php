<?php
/**
 * What a campaign has committed: units promised in open presales, by product and delivery date,
 * against current stock. The season's shopping list.
 *
 * @var list<array<string, mixed>>     $campaigns
 * @var int|null                       $campaign_id
 * @var array<string, mixed>|null      $table      dates (formatted) and rows (formatted)
 */
?>

<?= view('partial/header') ?>

<div id="page_title"><?= esc(lang('Presale_reports.committed')) ?></div>

<form method="get" action="<?= esc(site_url('presales/committed'), 'attr') ?>" class="form-inline print_hide" style="margin-bottom: 15px;">
    <label for="campaign_id"><?= esc(lang('Presale_reports.campaign')) ?></label>
    <select name="campaign_id" id="campaign_id" class="form-control input-sm">
        <option value=""><?= esc(lang('Presale_reports.choose_campaign')) ?></option>
        <?php foreach ($campaigns as $campaign) { ?>
            <option value="<?= (int) $campaign['campaign_id'] ?>"<?= (int) $campaign['campaign_id'] === (int) $campaign_id ? ' selected' : '' ?>><?= esc($campaign['name']) ?></option>
        <?php } ?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm"><?= esc(lang('Presale_reports.show')) ?></button>
    <?php if ($campaign_id !== null) { ?>
        <a class="btn btn-default btn-sm" href="<?= esc(site_url('presales/committed/csv') . '?campaign_id=' . (int) $campaign_id, 'attr') ?>"><?= esc(lang('Presale_reports.download_csv')) ?></a>
    <?php } ?>
</form>

<?php if ($table === null) { ?>
    <div class="alert alert-info" role="status"><?= esc(lang('Presale_reports.pick_campaign')) ?></div>
<?php } elseif (empty($table['rows'])) { ?>
    <div class="alert alert-info" role="status"><?= esc(lang('Presale_reports.nothing_committed')) ?></div>
<?php } else { ?>
    <div class="table-responsive">
        <table id="committed_table" class="table table-striped table-bordered table-hover">
            <thead>
                <tr>
                    <th><?= esc(lang('Presale_reports.product')) ?></th>
                    <th><?= esc(lang('Presale_reports.item_number')) ?></th>
                    <th><?= esc(lang('Presale_reports.unit')) ?></th>
                    <?php foreach ($table['dates'] as $date) { ?>
                        <th class="text-right"><?= esc($date) ?></th>
                    <?php } ?>
                    <th class="text-right"><?= esc(lang('Presale_reports.total')) ?></th>
                    <th class="text-right"><?= esc(lang('Presale_reports.stock')) ?></th>
                    <th class="text-right"><?= esc(lang('Presale_reports.shortfall')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($table['rows'] as $row) { ?>
                    <tr<?= $row['short'] ? ' class="danger"' : '' ?>>
                        <td><?= esc($row['name']) ?></td>
                        <td><?= esc($row['item_number']) ?></td>
                        <td><?= esc($row['unit']) ?></td>
                        <?php foreach ($row['cells'] as $cell) { ?>
                            <td class="text-right"><?= esc($cell) ?></td>
                        <?php } ?>
                        <td class="text-right"><strong><?= esc($row['total']) ?></strong></td>
                        <td class="text-right"><?= esc($row['stock']) ?></td>
                        <td class="text-right"><strong><?= esc($row['shortfall']) ?></strong></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
<?php } ?>

<?= view('partial/footer') ?>
