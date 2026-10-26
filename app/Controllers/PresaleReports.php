<?php

namespace App\Controllers;

use App\Models\Item;
use App\Models\Presale;
use App\Models\Presale_campaign;
use App\Models\Presale_report;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\OSPOS;
use NumberFormatter;

/**
 * Reports of the presales module: what each campaign has committed, which is the season's shopping
 * list (docs/Funcional/venta-anticipada.md section 4.11), and the portfolio, what the open presales
 * still owe and who is late (docs/Funcional/preventas-cartera-y-avisos.md section 3).
 *
 * Behind the `presales` module grant and the business's switch, like the rest of the module; the
 * portfolio also needs presales_manage. Read only. A separate controller from Presales so the lanes
 * of the module do not share a file.
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
        $campaign_id = $this->chosen_campaign($campaigns);

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

        $campaign_id = $this->chosen_campaign($this->campaigns->get_all());

        if ($campaign_id === null) {
            return redirect()->to('presales/committed');
        }

        $table = $this->table($campaign_id, true);
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

        return $this->response->download('preventas_comprometido_' . $campaign_id . '.csv', self::csv($lines));
    }

    // ---------------------------------------------------------------------------------------------
    // Portfolio (docs/Funcional/preventas-cartera-y-avisos.md §3)
    // ---------------------------------------------------------------------------------------------

    /**
     * The portfolio: what the open presales sold, collected and still owe, what is overdue and by
     * how long, what falls due this week and who is late. Only for presales_manage (S4): it shows the
     * business's figures and every customer's phone and email.
     */
    public function getPortfolio(): RedirectResponse|string
    {
        if (! Presales::is_enabled()) {
            return view('presales/disabled');
        }

        if (! Presales::can_manage($this->employee)) {
            return redirect()->to('no_access/presales/presales_manage');
        }

        $campaigns = $this->campaigns->get_all();
        $dates     = $this->report->open_delivery_dates();
        $filter    = $this->portfolio_filter($campaigns, $dates);
        $data      = $this->report->portfolio(date('Y-m-d'), $filter['campaign_id'], $filter['delivery_date']);

        // The screen lists the late and the upcoming; the number printed for the customer goes on each.
        $data['late']             = $this->with_numbers($data['late']);
        $data['upcoming']['rows'] = $this->with_numbers($data['upcoming']['rows']);

        return view('presales/portfolio', [
            'campaigns'     => $campaigns,
            'dates'         => $dates,
            'campaign_id'   => $filter['campaign_id'],
            'delivery_date' => $filter['delivery_date'],
            'portfolio'     => $data,
            'csv_query'     => http_build_query(array_filter($filter, static fn ($value): bool => $value !== null)),
        ], ['saveData' => false]);
    }

    /**
     * Every open presale of the filter as a CSV, one row each with its money and its contact data, the
     * most days late first. Same filter and same guard as the screen.
     */
    public function getPortfolioCsv(): ResponseInterface
    {
        if (! Presales::is_enabled()) {
            return redirect()->to('presales');
        }

        if (! Presales::can_manage($this->employee)) {
            return redirect()->to('no_access/presales/presales_manage');
        }

        $filter = $this->portfolio_filter($this->campaigns->get_all(), $this->report->open_delivery_dates());
        $data   = $this->report->portfolio(date('Y-m-d'), $filter['campaign_id'], $filter['delivery_date']);
        $rows   = $this->with_numbers($data['rows']);

        usort($rows, static fn (array $a, array $b): int => ($b['days_late'] <=> $a['days_late']) ?: ($a['presale_id'] <=> $b['presale_id']));

        $lines = [[
            lang('Presale_reports.number'), lang('Presale_reports.customer'), lang('Presale_reports.phone'),
            lang('Presale_reports.email'), lang('Presale_reports.campaign'), lang('Presale_reports.delivery_date'),
            lang('Presale_reports.total'), lang('Presale_reports.paid'), lang('Presale_reports.balance'),
            lang('Presale_reports.overdue'), lang('Presale_reports.days_late'), lang('Presale_reports.due_next_days'),
        ]];

        foreach ($rows as $row) {
            $lines[] = [
                $row['number'],
                trim($row['first_name'] . ' ' . $row['last_name']),
                $row['phone_number'],
                $row['email'],
                $row['campaign_name'],
                to_date((int) strtotime($row['delivery_date'])),
                $this->money_cell($row['total']),
                $this->money_cell($row['paid']),
                $this->money_cell($row['balance']),
                $this->money_cell($row['overdue']),
                $row['days_late'] > 0 ? (string) $row['days_late'] : '',
                $this->money_cell($row['upcoming']),
            ];
        }

        $suffix = $filter['campaign_id'] === null ? 'todas' : (string) $filter['campaign_id'];

        return $this->response->download('preventas_cartera_' . $suffix . '.csv', self::csv($lines));
    }

    /**
     * The campaign and the delivery date asked for, each only when it is one of the options: the lists
     * are the whitelist. Anything else reads as "all".
     *
     * @param list<array<string, mixed>> $campaigns
     * @param list<string>               $dates
     *
     * @return array{campaign_id: int|null, delivery_date: string|null}
     */
    private function portfolio_filter(array $campaigns, array $dates): array
    {
        $date = (string) $this->request->getGet('delivery_date');

        return [
            'campaign_id'   => $this->chosen_campaign($campaigns),
            'delivery_date' => in_array($date, $dates, true) ? $date : null,
        ];
    }

    /**
     * Each row with `number`, the presale's number as printed for the customer (Presale::number()).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function with_numbers(array $rows): array
    {
        $presale = model(Presale::class);

        return array_map(static fn (array $row): array => $row + ['number' => $presale->number((int) $row['presale_id'])], $rows);
    }

    /**
     * An amount for the CSV: the business's decimals and decimal separator, no thousands separator
     * and no currency symbol, so a spreadsheet in the same locale reads it as a number (same reason as
     * quantity()).
     */
    private function money_cell(string $amount): string
    {
        $config   = config(OSPOS::class)->settings;
        $decimals = totals_decimals();
        $fmt      = new NumberFormatter($config['number_locale'] ?? 'en_US', NumberFormatter::DECIMAL);

        $fmt->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $fmt->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
        $fmt->setTextAttribute(NumberFormatter::GROUPING_SEPARATOR_SYMBOL, '');
        $fmt->setAttribute(NumberFormatter::GROUPING_USED, 0);

        return (string) $fmt->format((float) $amount);
    }

    /**
     * Lines as a CSV with a BOM, every cell through safe_cell().
     *
     * @param list<list<mixed>> $lines
     */
    private static function csv(array $lines): string
    {
        $handle = fopen('php://temp', 'r+b');
        fwrite($handle, "\xEF\xBB\xBF");

        foreach ($lines as $line) {
            fputcsv($handle, array_map([self::class, 'safe_cell'], $line), ',', '"', '\\');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * A cell a spreadsheet will not run as a formula.
     */
    public static function safe_cell(mixed $value): string
    {
        $text = (string) $value;

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $text : $text;
    }

    /**
     * The campaign asked for, when it exists. Never one that does not: the list is the whitelist.
     *
     * @param list<array<string, mixed>> $campaigns
     */
    private function chosen_campaign(array $campaigns): ?int
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
    private function table(int $campaign_id, bool $csv = false): array
    {
        $data = $this->report->committed($campaign_id);
        $rows = [];

        foreach ($data['rows'] as $row) {
            $weight = Item::unit_of_measure_is_weight($row['unit_of_measure']);
            $symbol = $csv ? '' : Item::unit_of_measure_symbol($row['unit_of_measure']);
            $fmt    = fn (string $q): string => $this->quantity($q, $weight, $csv, $symbol);
            $cells  = [];

            foreach ($data['dates'] as $date) {
                $cells[] = isset($row['by_date'][$date]) ? $fmt($row['by_date'][$date]) : '';
            }

            $short = bccomp($row['shortfall'], '0', Presale_report::SCALE) > 0;

            $rows[] = [
                'name'        => $row['name'],
                'item_number' => $row['item_number'],
                'unit'        => $this->unit_label($row['unit_of_measure']),
                'cells'       => $cells,
                'total'       => $fmt($row['total']),
                'stock'       => $fmt($row['stock']),
                'shortfall'   => $short ? $fmt($row['shortfall']) : '',
                'short'       => $short,
            ];
        }

        return [
            'dates' => array_map(static fn (string $d): string => to_date(strtotime($d)), $data['dates']),
            'rows'  => $rows,
        ];
    }

    /**
     * A quantity ready to show.
     *
     * By-weight products take up to three decimals with no trailing zeros (0,750 -> 0,75), never the
     * business's display decimals: with quantity_decimals = 0, three quarters of a kilo would read as
     * "1". Other products keep the business's decimals. The decimal separator is always the
     * business's locale's. On screen the thousands separator follows the business's setting and a
     * by-weight quantity carries its unit symbol; in the CSV there is never a thousands separator
     * (a spreadsheet in the same locale would read "1.234" as text or as a different number) and
     * never a symbol, since the Unidad column already says it.
     */
    private function quantity(string $value, bool $weight, bool $csv, string $symbol): string
    {
        $config = config(OSPOS::class)->settings;
        $fmt    = new NumberFormatter($config['number_locale'], NumberFormatter::DECIMAL);

        if ($weight) {
            $fmt->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 0);
            $fmt->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, Presale_report::SCALE);
        } else {
            $decimals = (int) ($config['quantity_decimals'] ?? 0);
            $fmt->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $fmt->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
        }

        if ($csv || empty($config['thousands_separator'])) {
            $fmt->setTextAttribute(NumberFormatter::GROUPING_SEPARATOR_SYMBOL, '');
            $fmt->setAttribute(NumberFormatter::GROUPING_USED, 0);
        }

        $text = (string) $fmt->format((float) $value);

        return $symbol === '' ? $text : $text . ' ' . $symbol;
    }

    private function unit_label(string $code): string
    {
        return Item::units_of_measure_options()[Item::normalize_unit_of_measure($code)] ?? '';
    }
}
