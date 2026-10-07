<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;

/**
 * Registers the presales module and the two permissions it is gated on.
 *
 * NOTHING IS GRANTED HERE, AND THAT IS THE POINT. Same reasoning as AddWriteoffsModule and
 * AddOrderTicketsModule: granting automatically would drop a module the business never asked for
 * into the menu of a shop that sells with this code every day. The grant is made by hand from
 * Employees, for the business that turns the module on. After a deploy, run
 * `php spark platform:support-employee` so the support session gets the new permissions too.
 *
 * EXACTLY ONE SUBPERMISSION
 *
 * Employee::has_module_grant() resolves a module with a prefix match, and when the number of matches
 * is not exactly 1 it returns `count != 0`: with two or more subpermissions under one prefix, an
 * employee holding only those and not the base permission passes the module check. So everything a
 * supervisor does -- campaigns and cancellations -- sits behind one subpermission, presales_manage.
 * The full explanation is in AddOrderTicketsModule.
 *
 * Sort 72: after Sales (70) and before Order tickets (75). A presale ends at the register.
 *
 * See docs/Tecnico/venta-anticipada.md section 5.
 */
class Migration_AddPresalesModule extends Migration
{
    public const MODULE = 'presales';

    /**
     * Configure campaigns and cancel a presale, with or without giving money back. What a cashier
     * does not do, and what somebody will want to audit.
     */
    public const PERMISSION_MANAGE = 'presales_manage';

    private const SORT = 72;

    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        $this->addModule();
        $this->addPermission(self::MODULE);
        $this->addPermission(self::PERMISSION_MANAGE);

        if (is_cli()) {
            CLI::write('AddPresalesModule: module "' . self::MODULE . '" and permissions "' . self::MODULE . '", "' . self::PERMISSION_MANAGE . '" registered.');
            CLI::write('  No grant was created on purpose. Nobody sees the module until somebody is given the permission from Employees.');
        }
    }

    /**
     * Revert a migration step. Grants made by hand go with their permissions.
     */
    public function down(): void
    {
        $this->db->table('grants')->whereIn('permission_id', [self::MODULE, self::PERMISSION_MANAGE])->delete();
        $this->db->table('permissions')->whereIn('permission_id', [self::MODULE, self::PERMISSION_MANAGE])->delete();
        $this->db->table('modules')->where('module_id', self::MODULE)->delete();
    }

    /**
     * name_lang_key and desc_lang_key are both UNIQUE in this table, so the guard is on the module id
     * and the insert is skipped whole rather than retried field by field.
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
     * A row whose permission_id equals the module id is the module permission; any other row with that
     * module_id is a subpermission.
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
