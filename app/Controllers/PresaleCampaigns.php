<?php

namespace App\Controllers;

use App\Models\Item;
use App\Models\Presale_campaign;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Campaigns of the presales module: what is sold in advance, at what price, delivered on which days
 * and sold until when (docs/Funcional/venta-anticipada.md §4.2).
 *
 * Same module as Presales -- `presales` -- and every action here additionally requires the
 * presales_manage subpermission. Not a module of its own: a second module id would be one more thing
 * to grant, and the subpermission is exactly the line between the cashier and whoever sets prices.
 *
 * Every rule (dates, percentages, catalogue-only products, prices) is enforced in
 * App\Models\Presale_campaign. This controller only reads the form -- dates with
 * parse_typed_datetime(), numbers with parse_decimals() -- and answers {success, message} with lang()
 * messages. Messages reach $.notify, which writes HTML: anything that is not a fixed lang() string
 * (a campaign name, say) is passed through esc() here.
 */
class PresaleCampaigns extends Secure_Controller
{
    protected Presale_campaign $campaigns;

    public function __construct()
    {
        parent::__construct('presales');

        $this->campaigns = model(Presale_campaign::class);

        helper(['tabular', 'presale_campaigns']);
    }

    // ---------------------------------------------------------------------------------------------
    // Pages
    // ---------------------------------------------------------------------------------------------

    public function getIndex(): RedirectResponse|string
    {
        if (! Presales::is_enabled()) {
            return view('presales/disabled');
        }

        if (! Presales::can_manage($this->employee)) {
            return redirect()->to('no_access/presales/presales_manage');
        }

        return view('presales/campaigns', [
            'table_headers' => get_presale_campaigns_manage_table_headers(),
        ]);
    }

    /**
     * One campaign: its products and its delivery dates.
     */
    public function getDetail(int $campaign_id): RedirectResponse|string
    {
        if (! Presales::is_enabled()) {
            return view('presales/disabled');
        }

        if (! Presales::can_manage($this->employee)) {
            return redirect()->to('no_access/presales/presales_manage');
        }

        $campaign = $this->campaigns->get_info($campaign_id);

        if ($campaign === null) {
            return redirect()->to('presales/campaigns');
        }

        return view('presales/campaign_detail', [
            'campaign' => $campaign,
            'period'   => presale_campaign_date((string) $campaign['sale_starts']) . ' - ' . presale_campaign_date((string) $campaign['sale_ends']),
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // The list (bootstrap-table: search, row) and the form (view, save, delete)
    // ---------------------------------------------------------------------------------------------

    public function getSearch(): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        $search = mb_strtolower(trim((string) $this->request->getGet('search')));
        $limit  = max(0, (int) $this->request->getGet('limit'));
        $offset = max(0, (int) $this->request->getGet('offset'));
        $sort   = (string) $this->request->getGet('sort');
        $order  = strtolower((string) $this->request->getGet('order')) === 'desc' ? 'desc' : 'asc';

        $rows = [];

        // One query with the counts, not two more per campaign.
        foreach ($this->campaigns->get_with_counts() as $campaign) {
            if ($search !== '' && ! str_contains(mb_strtolower((string) $campaign['name']), $search)) {
                continue;
            }

            $rows[] = [
                'campaign' => $campaign,
                'products' => $campaign['products'],
                'dates'    => $campaign['dates'],
            ];
        }

        // Only these columns sort, by what they hold and not by how it is spelled on screen.
        $sort_keys = [
            'campaign_id'         => static fn (array $r) => (int) $r['campaign']['campaign_id'],
            'name'                => static fn (array $r) => mb_strtolower((string) $r['campaign']['name']),
            'sale_period'         => static fn (array $r) => $r['campaign']['sale_starts'],
            'discount_percent'    => static fn (array $r) => (float) $r['campaign']['discount_percent'],
            'min_initial_percent' => static fn (array $r) => (float) $r['campaign']['min_initial_percent'],
            'active'              => static fn (array $r) => (int) $r['campaign']['active'],
            'products'            => static fn (array $r) => $r['products'],
            'dates'               => static fn (array $r) => $r['dates'],
        ];

        if (isset($sort_keys[$sort])) {
            $key = $sort_keys[$sort];
            usort($rows, static fn (array $a, array $b) => $order === 'desc' ? $key($b) <=> $key($a) : $key($a) <=> $key($b));
        }

        $total = count($rows);

        if ($limit > 0) {
            $rows = array_slice($rows, $offset, $limit);
        }

        return $this->response->setJSON([
            'total' => $total,
            'rows'  => array_map(static fn (array $r) => get_presale_campaign_data_row($r['campaign'], $r['products'], $r['dates']), $rows),
        ]);
    }

    public function getRow(int $campaign_id): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        $campaign = $this->campaigns->get_with_counts($campaign_id)[0] ?? null;

        if ($campaign === null) {
            return $this->fail(lang('Presale_campaigns.not_found'), 404);
        }

        return $this->response->setJSON(get_presale_campaign_data_row($campaign, $campaign['products'], $campaign['dates']));
    }

    /**
     * The create / edit form, for the modal dialog.
     */
    public function getView(int $campaign_id = NEW_ENTRY): ResponseInterface|string
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        $id       = (int) $campaign_id;
        $campaign = $id === NEW_ENTRY ? null : $this->campaigns->get_info($id);

        if ($id !== NEW_ENTRY && $campaign === null) {
            return $this->fail(lang('Presale_campaigns.not_found'), 404);
        }

        return view('presales/campaign_form', [
            'campaign_id' => $id,
            'campaign'    => $campaign,
        ]);
    }

    public function postSave(int $campaign_id = NEW_ENTRY): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        $id = (int) $campaign_id;

        $starts = $this->read_date('sale_starts', $error);

        if ($starts === null) {
            return $this->fail($error);
        }

        $ends = $this->read_date('sale_ends', $error);

        if ($ends === null) {
            return $this->fail($error);
        }

        $discount = $this->read_number('discount_percent');
        $minimum  = $this->read_number('min_initial_percent');

        if ($discount === false || $minimum === false) {
            return $this->fail(lang('Presales.percent_invalid'));
        }

        $name = trim((string) $this->request->getPost('name'));

        $result = $this->campaigns->save_campaign([
            'name'                => $name,
            'sale_starts'         => $starts,
            'sale_ends'           => $ends,
            'discount_percent'    => $discount,
            'min_initial_percent' => $minimum,
            'active'              => $this->request->getPost('active') !== null,
        ], $id, $this->current_person_id());

        if (is_string($result)) {
            return $this->fail(lang($result));
        }

        return $this->response->setJSON([
            'success' => true,
            'message' => lang($id === NEW_ENTRY ? 'Presale_campaigns.successful_adding' : 'Presale_campaigns.successful_updating') . ' ' . esc($name),
            'id'      => $result,
        ]);
    }

    /**
     * Logical delete of the selected campaigns. A campaign with presales is refused by the model and
     * named in the message; the ones that could be deleted are deleted.
     */
    public function postDelete(): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        $ids = $this->request->getPost('ids');
        $ids = is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [];

        if ($ids === []) {
            return $this->fail(lang('Presale_campaigns.delete_none'));
        }

        $deleted = [];
        $blocked = [];

        foreach ($ids as $id) {
            $campaign = $this->campaigns->get_info($id);

            if ($campaign === null) {
                continue;
            }

            if ($this->campaigns->delete_campaign($id)) {
                $deleted[] = $id;
            } else {
                $blocked[] = lang('Presale_campaigns.delete_blocked', [esc((string) $campaign['name'])]);
            }
        }

        if ($blocked !== []) {
            return $this->response->setJSON([
                'success' => false,
                'message' => implode(' ', $blocked),
                'ids'     => $deleted,
            ]);
        }

        if ($deleted === []) {
            return $this->fail(lang('Presale_campaigns.delete_failed'));
        }

        return $this->response->setJSON([
            'success' => true,
            'message' => lang('Presale_campaigns.successful_deleted') . ' ' . count($deleted),
            'ids'     => $deleted,
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // The detail: products
    // ---------------------------------------------------------------------------------------------

    /**
     * Everything the detail screen draws, as plain values (the screen writes them with .text()).
     */
    public function getData(int $campaign_id): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        $campaign = $this->campaigns->get_info($campaign_id);

        if ($campaign === null) {
            return $this->fail(lang('Presale_campaigns.not_found'), 404);
        }

        $items = [];

        foreach ($this->campaigns->get_items($campaign_id) as $row) {
            $items[] = [
                'item_id'         => (int) $row['item_id'],
                'name'            => (string) $row['name'],
                'item_number'     => (string) ($row['item_number'] ?? ''),
                'is_weight'       => Item::unit_of_measure_is_weight($row['unit_of_measure'] ?? null),
                'catalogue_price' => to_currency((string) $row['catalogue_price']),
                'base_price'      => to_currency((string) $row['base_price']),
                'discount_input'  => $row['discount_percent'] === null ? '' : to_decimals((string) $row['discount_percent']),
                'price_input'     => $row['campaign_price'] === null ? '' : to_decimals((string) $row['campaign_price']),
                'effective_price' => to_currency((string) $row['effective_price']),
                'inherited'       => presale_campaign_percent((string) $campaign['discount_percent']),
            ];
        }

        $dates = [];

        foreach ($this->campaigns->get_dates($campaign_id) as $row) {
            $dates[] = [
                'date_id' => (int) $row['date_id'],
                'date'    => presale_campaign_date((string) $row['delivery_date']),
            ];
        }

        return $this->response->setJSON(['success' => true, 'items' => $items, 'dates' => $dates]);
    }

    /**
     * Suggestions for the product picker.
     *
     * This module's own endpoint, like Writeoffs::getSuggest(): items/suggest is behind the `items`
     * permission, and whoever sets up a campaign is not necessarily allowed to edit the catalogue.
     */
    public function getSuggest(): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        $term    = (string) $this->request->getGet('term');
        $filters = ['search_custom' => false, 'is_deleted' => false];
        $items   = model(Item::class);

        // Item kits too: Item::get_search_suggestions() leaves them out on purpose (the register picks
        // them up through its own kit search), and without this a campaign could never offer a basket
        // or a combo, which is the likeliest thing a business sells in advance. Presale::price_lines()
        // expands a kit into its components when a presale is registered.
        $suggestions = [];
        $seen        = [];

        foreach (array_merge(
            $items->get_search_suggestions($term, $filters, true),
            $items->get_kit_search_suggestions($term, $filters, true),
        ) as $suggestion) {
            if (isset($seen[$suggestion['value']])) {
                continue;
            }

            $seen[$suggestion['value']] = true;
            $suggestions[]              = $suggestion;
        }

        return $this->response->setJSON(array_slice($suggestions, 0, 25));
    }

    public function postAddItem(int $campaign_id): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        $item_id = (int) $this->request->getPost('item_id');

        if ($item_id <= 0) {
            return $this->fail(lang('Presale_campaigns.item_not_picked'));
        }

        return $this->answer($this->campaigns->add_item($campaign_id, $item_id), 'Presale_campaigns.item_added');
    }

    public function postUpdateItem(int $campaign_id, int $item_id): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        $price    = $this->read_number('campaign_price');
        $discount = $this->read_number('discount_percent');

        if ($price === false) {
            return $this->fail(lang('Presales.price_invalid'));
        }

        if ($discount === false) {
            return $this->fail(lang('Presales.percent_invalid'));
        }

        if ($this->campaigns->get_item($campaign_id, $item_id) === null) {
            return $this->fail(lang('Presales.item_not_in_campaign'));
        }

        return $this->answer($this->campaigns->update_item($campaign_id, $item_id, $price, $discount), 'Presale_campaigns.item_updated');
    }

    public function postRemoveItem(int $campaign_id, int $item_id): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        if ($this->campaigns->get_item($campaign_id, $item_id) === null) {
            return $this->fail(lang('Presales.item_not_in_campaign'));
        }

        if (! $this->campaigns->remove_item($campaign_id, $item_id)) {
            return $this->fail(lang('Presale_campaigns.item_remove_blocked'));
        }

        return $this->ok(lang('Presale_campaigns.item_removed'));
    }

    // ---------------------------------------------------------------------------------------------
    // The detail: delivery dates
    // ---------------------------------------------------------------------------------------------

    public function postAddDate(int $campaign_id): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        $date = $this->read_date('delivery_date', $error);

        if ($date === null) {
            return $this->fail($error);
        }

        return $this->answer($this->campaigns->add_date($campaign_id, $date), 'Presale_campaigns.date_added');
    }

    public function postRemoveDate(int $campaign_id, int $date_id): ResponseInterface
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        if ($this->campaigns->get_date($campaign_id, $date_id) === null) {
            return $this->fail(lang('Presales.date_invalid'));
        }

        if (! $this->campaigns->remove_date($campaign_id, $date_id)) {
            return $this->fail(lang('Presale_campaigns.date_remove_blocked'));
        }

        return $this->ok(lang('Presale_campaigns.date_removed'));
    }

    // ---------------------------------------------------------------------------------------------
    // Plumbing
    // ---------------------------------------------------------------------------------------------

    private function current_person_id(): int
    {
        return (int) $this->employee->get_logged_in_employee_info()->person_id;
    }

    /**
     * The answer to a request of an endpoint (not a page) when the module is off or the employee lacks
     * presales_manage; null when the request may go on. 403 either way, with a message, because these
     * are called by script and a redirect to an HTML page would be read as data.
     */
    private function deny(): ?ResponseInterface
    {
        if (! Presales::is_enabled()) {
            return $this->fail(lang('Presales.disabled'), 403);
        }

        if (! Presales::can_manage($this->employee)) {
            return $this->fail(lang('Presale_campaigns.forbidden'), 403);
        }

        return null;
    }

    private function fail(string $message, int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON(['success' => false, 'message' => $message]);
    }

    private function ok(string $message): ResponseInterface
    {
        return $this->response->setJSON(['success' => true, 'message' => $message]);
    }

    /**
     * Turns the model's bool|lang-key answer into the JSON answer.
     */
    private function answer(bool|string $result, string $success_key): ResponseInterface
    {
        if (is_string($result)) {
            return $this->fail(lang($result));
        }

        return $result ? $this->ok(lang($success_key)) : $this->fail(lang('Presales.save_failed'));
    }

    /**
     * A date typed in the business's format, as Y-m-d; null when it is not a real date, with the
     * refusal (escaped: it echoes what was typed and ends in $.notify) in $error.
     */
    private function read_date(string $field, ?string &$error = null): ?string
    {
        $typed = (string) $this->request->getPost($field);
        $date  = parse_typed_datetime($typed, false);

        if ($date === false) {
            $error = esc(typed_date_error($typed));

            return null;
        }

        return $date->format('Y-m-d');
    }

    /**
     * A number typed in the business's format in a posted field (Presales::read_decimal()).
     */
    private function read_number(string $field): false|string
    {
        return Presales::read_decimal((string) $this->request->getPost($field), 2);
    }
}
