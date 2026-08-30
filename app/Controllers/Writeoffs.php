<?php

namespace App\Controllers;

use App\Libraries\Sale_lib;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\Reports\Inventory_write_offs;
use App\Models\Stock_location;
use CodeIgniter\HTTP\ResponseInterface;
use Config\OSPOS;

/**
 * Recording stock that was lost, and reporting on it.
 *
 * The whole module lives behind the `writeoffs` permission, which no migration grants: until
 * somebody is given it from Employees, the menu entry does not exist and Secure_Controller turns a
 * typed-in URL into a redirect to no_access. See 20260906001000_AddWriteoffsModule.
 *
 * The saving path deliberately mirrors Items::postSaveInventory() -- one audit row in `inventory`
 * plus the matching move on `item_quantities` -- but the arithmetic does not: that one runs the
 * typed number through parse_quantity(), which rounds to the tenant's *display* decimals, and for
 * Casaletto (quantity_decimals = 0) half a kilo of cheese would arrive here as a whole one. See
 * docs/Tecnico/venta-por-peso-y-hardware-de-caja.md section 2.
 */
class Writeoffs extends Secure_Controller
{
    private Inventory $inventory;
    private Item $item;
    private Stock_location $stock_location;
    private Inventory_write_offs $write_offs;
    private array $config;

    public function __construct()
    {
        parent::__construct('writeoffs');

        $this->inventory = model(Inventory::class);
        $this->item = model(Item::class);
        $this->stock_location = model(Stock_location::class);
        $this->write_offs = model(Inventory_write_offs::class);
        $this->config = config(OSPOS::class)->settings;
    }

    /**
     * The registration screen.
     */
    public function getIndex(): string
    {
        return view('writeoffs/form', $this->formData());
    }

    /**
     * Records one write-off.
     *
     * Everything is decided on the server. The quantity arrives as text and is read with
     * Sale_lib::normalize_weight_input(), which is the one parser in this codebase that treats a
     * lone dot as a decimal point: parse_decimals() runs the text through the tenant's
     * number_locale, and under es_CO "0.735" comes back as 735 -- 735 kilos of cheese written off
     * instead of 735 grams, with nothing on screen to suggest anything went wrong.
     *
     * $data_item_id is inherited from Secure_Controller::postSave() and is not used: a write-off is
     * always a new movement. It has to stay in the signature -- PHP refuses an override that drops
     * a parameter, and the fatal is raised at class load, so the whole module would 500.
     */
    public function postSave(int $data_item_id = NEW_ENTRY): string
    {
        $data = $this->formData();

        $item_id = (int) $this->request->getPost('item_id');
        $location_id = (int) $this->request->getPost('stock_location');
        $reason_code = (string) $this->request->getPost('reason_code');
        $comment = (string) $this->request->getPost('comment');
        $quantity = Sale_lib::normalize_weight_input((string) $this->request->getPost('quantity'));

        $data['submitted'] = [
            'item_id'     => $item_id,
            'item_name'   => (string) $this->request->getPost('item_name'),
            'location_id' => $location_id,
            'reason_code' => $reason_code,
            'comment'     => $comment,
            'quantity'    => (string) $this->request->getPost('quantity')
        ];

        $item_info = $item_id > 0 ? $this->item->get_info($item_id) : null;

        // Compared against the id that was asked for, NOT tested with empty(): Item::get_info()
        // answers for an unknown id with a hollow object whose primary key is NEW_ENTRY (-1), which
        // is not empty. Letting that through would hand the model an item that does not exist, the
        // INSERT would fail on the foreign key, and -- because CodeIgniter's transactions are
        // strict by default -- every transaction for the rest of that process would fail with it.
        if ($item_info === null || (int) $item_info->item_id !== $item_id) {
            $data['error'] = lang('Writeoffs.item_required');

            return view('writeoffs/form', $data);
        }

        if (!array_key_exists($location_id, $data['stock_locations'])) {
            $data['error'] = lang('Writeoffs.stock_location_invalid');

            return view('writeoffs/form', $data);
        }

        if (!Inventory::is_write_off_reason($reason_code)) {
            $data['error'] = lang('Writeoffs.reason_invalid');

            return view('writeoffs/form', $data);
        }

        // normalize_weight_input() returns null for anything that is not a plain number, and the
        // model refuses zero and negatives. Both are the same message to the user: a write-off of
        // nothing is not a write-off.
        if ($quantity === null || !$this->inventory->record_write_off($item_id, $location_id, $quantity, $reason_code, $comment, $this->employeeId())) {
            $data['error'] = lang('Writeoffs.quantity_invalid');

            return view('writeoffs/form', $data);
        }

        // Cleared on success so the next write-off starts from an empty form rather than from a
        // pre-filled one that invites a double entry.
        $data['submitted'] = [];

        // The quantity is echoed back exactly as it was stored, NOT through to_quantity_decimals():
        // that formatter rounds to the tenant's display decimals, and confirming "0.735 kg written
        // off" with the word "1" right under the field where 0.735 was typed is how somebody stops
        // trusting the screen.
        $data['success'] = lang('Writeoffs.successful_adding') . ' ' . $item_info->name
            . ': ' . $quantity . ' (' . $this->reasonOptions()[$reason_code] . ')';

        return view('writeoffs/form', $data);
    }

    /**
     * Item suggestions for the picker on the registration screen.
     *
     * The module has its own endpoint rather than borrowing items/suggest because that one is
     * behind the `items` permission: an employee trusted to record spoilage is not necessarily an
     * employee trusted to edit the catalogue, and the picker has to work for both.
     */
    public function getSuggest(): ResponseInterface
    {
        $suggestions = $this->item->get_search_suggestions(
            (string) $this->request->getGet('term'),
            ['search_custom' => false, 'is_deleted' => false],
            true
        );

        return $this->response->setJSON($suggestions);
    }

    /**
     * The write-off report: the date-range form when called bare, the numbers when called with a
     * range.
     *
     * The dates arrive rawurlencoded in the path, exactly as the reports module does it, because
     * with a tenant that shows times they contain spaces and colons.
     */
    public function getReport(?string $start_date = null, ?string $end_date = null, string $location_id = 'all'): string
    {
        if ($start_date === null || $end_date === null) {
            return view('writeoffs/report_input', ['stock_locations' => $this->reportLocations()]);
        }

        $inputs = [
            'start_date'  => rawurldecode($start_date),
            'end_date'    => rawurldecode($end_date),
            'location_id' => $location_id
        ];

        $rows = [];

        foreach ($this->write_offs->getData($inputs) as $row) {
            $rows[] = [
                'item_name'      => $row['name'],
                'item_number'    => $row['item_number'],
                'reason'         => $this->reasonLabel($row['reason_code']),
                'quantity'       => to_quantity_decimals($row['quantity']),
                'cost_price'     => to_currency($row['cost_price']),
                'write_off_cost' => to_currency($row['write_off_cost'])
            ];
        }

        $summary = $this->write_offs->getSummaryData($inputs);

        $by_reason = [];

        foreach ($summary['by_reason'] as $code => $totals) {
            $by_reason[] = [
                'reason'         => $this->reasonLabel($code),
                'quantity'       => to_quantity_decimals($totals['quantity']),
                'write_off_cost' => to_currency($totals['write_off_cost'])
            ];
        }

        return view('writeoffs/report', [
            'title'          => lang('Writeoffs.report'),
            'subtitle'       => $this->subtitle($inputs),
            'headers'        => $this->write_offs->getDataColumns(),
            'data'           => $rows,
            'by_reason'      => $by_reason,
            'total_cost'     => to_currency($summary['total_cost']),
            'total_quantity' => to_quantity_decimals((string) $summary['total_quantity'])
        ]);
    }

    /**
     * Everything the registration form needs, whether it is being shown for the first time or
     * re-shown with an error.
     */
    private function formData(): array
    {
        return [
            'stock_locations' => $this->formLocations(),
            'reasons'         => $this->reasonOptions(),
            'submitted'       => [],
            'error'           => null,
            'success'         => null
        ];
    }

    /**
     * code => translated label.
     *
     * The mapping lives in exactly one place, and the database stores only the left-hand side --
     * which is the point of storing a code: the wording can change, or be translated, without a
     * single stored row having to change with it.
     *
     * @return array<string, string>
     */
    private function reasonOptions(): array
    {
        $options = [];

        foreach (Inventory::WRITE_OFF_REASON_CODES as $code) {
            $options[$code] = lang('Writeoffs.reason_' . $code);
        }

        return $options;
    }

    /**
     * A stored code rendered back into words, falling back to the code itself.
     *
     * A row written by a future version of this application, or by hand, must still show up in the
     * report rather than appear as a blank line: an unrecognised classification is worth seeing.
     */
    private function reasonLabel(?string $code): string
    {
        return $this->reasonOptions()[$code] ?? (string) $code;
    }

    /**
     * The locations a write-off can be recorded against.
     *
     * Stock_location's own get_allowed_locations() filters by per-location permissions that only
     * exist for items, sales and receivings -- Stock_location::save_value() creates those three by
     * name and nothing else, so a `writeoffs_<location>` permission would be wiped the first time
     * somebody renamed a location. Rather than ship a location ACL that silently stops working,
     * this module is gated by its module permission alone. Noted as a limitation, not as a
     * discovery: see the report at the end of this work.
     *
     * @return array<int, string>
     */
    private function formLocations(): array
    {
        $locations = [];

        foreach ($this->stock_location->get_all()->getResultArray() as $location) {
            $locations[(int) $location['location_id']] = $location['location_name'];
        }

        return $locations;
    }

    /**
     * @return array<int|string, string>
     */
    private function reportLocations(): array
    {
        $locations = $this->formLocations();

        if (count($locations) < 2) {
            return [];
        }

        return ['all' => lang('Writeoffs.all')] + $locations;
    }

    private function employeeId(): int
    {
        return (int) $this->employee->get_logged_in_employee_info()->person_id;
    }

    /**
     * The date range as the tenant writes dates, for the heading of the report.
     */
    private function subtitle(array $inputs): string
    {
        $format = empty($this->config['date_or_time_format'])
            ? $this->config['dateformat']
            : $this->config['dateformat'] . ' ' . $this->config['timeformat'];

        return date($format, strtotime($inputs['start_date'])) . ' - ' . date($format, strtotime($inputs['end_date']));
    }
}
