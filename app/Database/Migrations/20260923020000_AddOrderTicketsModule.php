<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;

/**
 * Registers the order ticket module and the two permissions it is gated on.
 *
 * The menus are built from the `modules` table joined against the employee's grants
 * (App\Models\Module::get_allowed_home_modules), so a module with no grants is invisible: it does
 * not appear in the top bar, it does not appear on the home tiles, and Secure_Controller turns a
 * typed-in URL into a redirect to no_access. The screen exists and nobody can reach it.
 *
 * NOTHING IS GRANTED HERE, AND THAT IS THE POINT. The same reasoning written into
 * 20260906001000_AddWriteoffsModule: granting automatically would drop a module the business never
 * asked for into the menu of a shop that sells with this code every day -- the opposite of the
 * isolation this platform promises its tenants. The grant is made by hand from Employees, for the
 * business that asks for it. "person 1" is not reliably the administrator on a multi-tenant install
 * either.
 *
 * WHY THERE IS EXACTLY ONE SUBPERMISSION, AND WHY THE KITCHEN IS NOT HERE
 *
 * This is a security decision, not a stylistic one. Employee::has_module_grant()
 * (app/Models/Employee.php:483-502) resolves a module grant with `like(permission_id, $x, 'after')`
 * -- a prefix match -- and when the number of matches is not exactly 1 it returns `count != 0`.
 *
 *   With TWO OR MORE subpermissions under one prefix, an employee holding only those two and NOT
 *   the base permission passes the module check anyway.
 *
 * With exactly one subpermission the count is 1, the branch falls through to has_subpermissions(),
 * and the answer is correct. So this module ships one subpermission -- order_tickets_void -- and the
 * kitchen screen of delivery 3 gets its own module id, `kitchen_display`, whose prefix deliberately
 * does not begin with `order_tickets`.
 *
 * That closes the hole by construction instead of by patching a function every other module depends
 * on. The hole itself is real and stays open elsewhere (`sales` has three); fixing it is its own
 * task, with its own regression risk.
 *
 * Sort 75 puts it immediately after Sales (70) and before Employees (80): a comanda is how a sale
 * begins, and it belongs next to the sale.
 *
 * See docs/Tecnico/comandas-y-cuenta-abierta.md section 6.
 */
class Migration_AddOrderTicketsModule extends Migration
{
    private const MODULE = 'order_tickets';

    /**
     * Cancelling a ticket that has already been printed is the action somebody will want to audit:
     * the kitchen has already cooked. It is deliberately not the same permission as taking orders --
     * a waiter takes them, a supervisor cancels them.
     */
    private const PERMISSION_VOID = 'order_tickets_void';

    private const SORT = 75;

    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        $this->addModule();
        $this->addPermission(self::MODULE);
        $this->addPermission(self::PERMISSION_VOID);

        CLI::write('AddOrderTicketsModule: module "' . self::MODULE . '" and permissions "' . self::MODULE . '", "' . self::PERMISSION_VOID . '" registered.');
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
        $this->db->table('grants')->where('permission_id', self::PERMISSION_VOID)->delete();
        $this->db->table('grants')->where('permission_id', self::MODULE)->delete();
        $this->db->table('permissions')->where('permission_id', self::PERMISSION_VOID)->delete();
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
            'sort'          => self::SORT,
        ]);
    }

    /**
     * A row whose permission_id equals the module id is the module permission; any other row with
     * that module_id is a subpermission. That is the whole distinction, and it is what
     * Module::get_all_subpermissions() uses.
     */
    private function addPermission(string $permission_id): void
    {
        $permissions = $this->db->table('permissions');

        if ($permissions->where('permission_id', $permission_id)->countAllResults() > 0) {
            return;
        }

        $permissions->insert([
            'permission_id' => $permission_id,
            'module_id'     => self::MODULE,
        ]);
    }
}
