<?php

namespace App\Controllers;

use App\Models\Presale;
use Config\OSPOS;

/**
 * The presales screens a cashier uses: the list, registering a presale, taking instalments, printing
 * the documents and sending a paid presale to the register to be delivered.
 *
 * Campaigns live in PresaleCampaigns, behind the presales_manage subpermission. Two controllers and not
 * one so the two pieces of work can be built in parallel without touching the same file.
 *
 * THE SWITCH
 *
 * presales_enable is read with ?? '0' on every request: an absent switch is an off switch. Off, the
 * module is not usable; the menu tile can still show for a granted employee, so the screen says plainly
 * that the business has it switched off instead of answering with an error.
 *
 * Every rule about money, dates and prices is enforced in App\Models\Presale, never here. This
 * controller reads the form, normalises numbers with parse_decimals() and dates with
 * parse_typed_datetime(), and hands over.
 *
 * See docs/Funcional/venta-anticipada.md and docs/Tecnico/venta-anticipada.md.
 */
class Presales extends Secure_Controller
{
    protected Presale $presale;

    public function __construct()
    {
        parent::__construct('presales');

        $this->presale = model(Presale::class);
    }

    public function getIndex(): string
    {
        if (! self::is_enabled()) {
            return view('presales/disabled');
        }

        return view('presales/manage');
    }

    /**
     * Whether the business has presales switched on. Absent reads as off.
     *
     * Public and static because the register, the cash-up and the reports ask the same question before
     * doing anything presale-related, and there must be one answer.
     */
    public static function is_enabled(): bool
    {
        return (string) (config(OSPOS::class)->settings['presales_enable'] ?? '0') === '1';
    }

    /**
     * Whether the logged-in employee holds presales_manage: campaigns and cancellations.
     */
    protected function can_manage(): bool
    {
        $employee = $this->employee->get_logged_in_employee_info();

        return is_object($employee) && $this->employee->has_grant('presales_manage', (int) $employee->person_id);
    }
}
