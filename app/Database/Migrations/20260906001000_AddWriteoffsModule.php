<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;

/**
 * Registers the write-off module and the permission it is gated on.
 *
 * The menus are built from the `modules` table joined against the employee's grants
 * (App\Models\Module::get_allowed_home_modules), so a module with no grants is invisible: it does
 * not appear in the top bar, it does not appear on the home tiles, and Secure_Controller turns a
 * typed-in URL into a redirect to no_access. The screen exists and nobody can reach it.
 *
 * NOTHING IS GRANTED HERE, AND THAT IS THE POINT. The precedent next door
 * (20260823030000_AddAnalyticsReportPermission) hands the new permission to everyone who already
 * holds another one, and copying that here would drop a module the business never asked for into
 * the menu of a shop that sells with this code every day -- the opposite of the isolation this
 * platform promises its tenants. The grant is made by hand from Employees, for the tenant that
 * asks for it. Attributes, cash-ups and the rest granted themselves to person 1 on the way in;
 * on a multi-tenant installation "person 1" is not reliably the administrator either.
 *
 * Sort 25 puts it between Items (20) and Item Kits (30): a write-off is an inventory operation and
 * belongs next to the inventory.
 *
 * See docs/Tecnico/venta-por-peso-y-hardware-de-caja.md section 6.1.
 */
class Migration_AddWriteoffsModule extends Migration
{
    private const MODULE = 'writeoffs';
    private const SORT = 25;

    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        $this->addModule();
        $this->addPermission();

        CLI::write('AddWriteoffsModule: module and permission "' . self::MODULE . '" registered.');
        CLI::write('  No grant was created on purpose. Nobody sees the module until somebody is given the permission from Employees.');
    }

    /**
     * Revert a migration step.
     *
     * Grants cascade off permissions and permissions cascade off modules, so removing the module
     * takes any grant somebody made by hand with it. Order still matters for readability.
     */
    public function down(): void
    {
        $this->db->table('grants')->where('permission_id', self::MODULE)->delete();
        $this->db->table('permissions')->where('permission_id', self::MODULE)->delete();
        $this->db->table('modules')->where('module_id', self::MODULE)->delete();
    }

    /**
     * name_lang_key and desc_lang_key are both UNIQUE in this table, so the guard is on the module
     * id and the insert is skipped whole rather than retried field by field.
     */
    private function addModule(): void
    {
        $modules = $this->db->table('modules');

        if ($modules->where('module_id', self::MODULE)->countAllResults() > 0) {
            return;
        }

        $modules->insert([
            'module_id'     => self::MODULE,
            'name_lang_key' => 'module_' . self::MODULE,
            'desc_lang_key' => 'module_' . self::MODULE . '_desc',
            'sort'          => self::SORT
        ]);
    }

    private function addPermission(): void
    {
        $permissions = $this->db->table('permissions');

        if ($permissions->where('permission_id', self::MODULE)->countAllResults() > 0) {
            return;
        }

        $permissions->insert([
            'permission_id' => self::MODULE,
            'module_id'     => self::MODULE
        ]);
    }
}
