<?php

namespace App\Controllers;

use App\Models\Item;
use App\Models\Presale_campaign;
use App\Models\Presale_report;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Reports of the presales module. Today one: what each campaign has committed, which is the
 * season's shopping list (docs/Funcional/venta-anticipada.md section 4.11).
 *
 * Behind the `presales` module grant and the business's switch, like the rest of the module. Read
 * only. A separate controller from Presales so the lanes of the module do not share a file.
 */
class PresaleReports extends Secure_Controller
{
    protected Presale_report $report;
    protected Presale_campaign $campaigns;

    public function __construct()
    {
        parent::__construct('presales');

        $this->report    = model(Presale_report::class);
        $this->campaigns = model(Presale_campaign::class);
    }

    /**
     * The screen: a campaign picker and, once one is chosen, the table.
     */
    public function getCommitted(): string
    {
        if (! Presales::is_enabled()) {
            return view('presales/disabled');
        }

        $campaigns   = $this->campaigns->get_all();
        $campaign_id = $this->chosenCampaign($campaigns);

        return view('presales/committed', [
            'campaigns'   => $campaigns,
            'campaign_id' => $campaign_id,
            'table'       => $campaign_id === null ? null : $this->table($campaign_id),
        ]);
    }

    /**
     * The same table as a CSV download.
     */
    public function getCommittedCsv(): ResponseInterface
    {
        if (! Presales::is_enabled()) {
            return redirect()->to('presales');
        }

        $campaign_id = $this->chosenCampaign($this->campaigns->get_all());

        if ($campaign_id === null) {
            return redirect()->to('presales/committed');
        }

        $table = $this->table($campaign_id);
        $lines = [array_merge(
            [lang('Presale_reports.product'), lang('Presale_reports.item_number'), lang('Presale_reports.unit')],
            $table['dates'],
            [lang('Presale_reports.total'), lang('Presale_reports.stock'), lang('Presale_reports.shortfall')],
        )];

        foreach ($table['rows'] as $row) {
            $lines[] = array_merge(
                [$row['name'], $row['item_number'], $row['unit']],
                $row['cells'],
                [$row['total'], $row['stock'], $row['shortfall']],
            );
        }

        $handle = fopen('php://temp', 'r+b');
        fwrite($handle, "\xEF\xBB\xBF");

        foreach ($lines as $line) {
            fputcsv($handle, array_map([self::class, 'safeCell'], $line), ',', '"', '\\');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $this->response->download('preventas_comprometido_' . $campaign_id . '.csv', $csv);
    }

    /**
     * A cell a spreadsheet will not run as a formula.
     */
    public static function safeCell(mixed $value): string
    {
        $text = (string) $value;

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $text : $text;
    }

    /**
     * The campaign asked for, when it exists. Never one that does not: the list is the whitelist.
     *
     * @param list<array<string, mixed>> $campaigns
     */
    private function chosenCampaign(array $campaigns): ?int
    {
        $asked = (int) $this->request->getGet('campaign_id');

        foreach ($campaigns as $campaign) {
            if ((int) $campaign['campaign_id'] === $asked) {
                return $asked;
            }
        }

        return null;
    }

    /**
     * The report ready to print: dates and quantities in the business's formats.
     *
     * @return array{dates: list<string>, rows: list<array<string, mixed>>}
     */
    private function table(int $campaign_id): array
    {
        $data = $this->report->committed($campaign_id);
        $rows = [];

        foreach ($data['rows'] as $row) {
            $cells = [];

            foreach ($data['dates'] as $date) {
                $cells[] = isset($row['by_date'][$date]) ? to_quantity_decimals($row['by_date'][$date]) : '';
            }

            $short = bccomp($row['shortfall'], '0', Presale_report::SCALE) > 0;

            $rows[] = [
                'name'        => $row['name'],
                'item_number' => $row['item_number'],
                'unit'        => $this->unitLabel($row['unit_of_measure']),
                'cells'       => $cells,
                'total'       => to_quantity_decimals($row['total']),
                'stock'       => to_quantity_decimals($row['stock']),
                'shortfall'   => $short ? to_quantity_decimals($row['shortfall']) : '',
                'short'       => $short,
            ];
        }

        return [
            'dates' => array_map(static fn (string $d): string => to_date(strtotime($d)), $data['dates']),
            'rows'  => $rows,
        ];
    }

    private function unitLabel(string $code): string
    {
        return Item::units_of_measure_options()[Item::normalize_unit_of_measure($code)] ?? '';
    }
}
