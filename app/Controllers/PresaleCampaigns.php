<?php

namespace App\Controllers;

use App\Models\Presale_campaign;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Campaigns of the presales module: what is sold in advance, at what price, delivered on which days
 * and sold until when (docs/Funcional/venta-anticipada.md §4.2).
 *
 * Same module as Presales -- `presales` -- and every action here additionally requires the
 * presales_manage subpermission. Not a module of its own: a second module id would be one more thing
 * to grant, and the subpermission is exactly the line between the cashier and whoever sets prices.
 *
 * Every rule (dates, percentages, catalogue-only products, prices) is enforced in
 * App\Models\Presale_campaign.
 */
class PresaleCampaigns extends Secure_Controller
{
    protected Presale_campaign $campaigns;

    public function __construct()
    {
        parent::__construct('presales');

        $this->campaigns = model(Presale_campaign::class);
    }

    public function getIndex(): RedirectResponse|string
    {
        if (! Presales::is_enabled()) {
            return view('presales/disabled');
        }

        if (! $this->can_manage()) {
            return redirect()->to('no_access/presales/presales_manage');
        }

        return view('presales/campaigns');
    }

    protected function can_manage(): bool
    {
        $employee = $this->employee->get_logged_in_employee_info();

        return is_object($employee) && $this->employee->has_grant('presales_manage', (int) $employee->person_id);
    }
}
