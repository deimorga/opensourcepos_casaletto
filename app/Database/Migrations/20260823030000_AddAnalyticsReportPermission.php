<?php

namespace App\Database\Migrations;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;

/**
 * Registers the permission the analytical reports panel is gated on.
 *
 * app/Views/reports/listing.php builds its panels from the employee's permissions, so without a row
 * here the report exists and is routable but nobody can see the link to it.
 *
 * The grant goes to whoever already holds reports_sales rather than to person 1 by name: on this
 * installation the administrator is not necessarily employee 1, and handing a new report only to a
 * hardcoded id would quietly leave the actual users without it.
 */
class Migration_AddAnalyticsReportPermission extends Migration
{
    private const PERMISSION = 'reports_analytics';

    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        $permissions = $this->db->table('permissions');

        if ($permissions->where('permission_id', self::PERMISSION)->countAllResults() === 0) {
            $permissions->insert([
                'permission_id' => self::PERMISSION,
                'module_id'     => 'reports'
            ]);
        }

        $holders = $this->db->table('grants')
            ->select('person_id')
            ->where('permission_id', 'reports_sales')
            ->get()
            ->getResultArray();

        $granted = 0;

        foreach ($holders as $holder) {
            $exists = $this->db->table('grants')
                ->where('permission_id', self::PERMISSION)
                ->where('person_id', $holder['person_id'])
                ->countAllResults();

            if ($exists === 0) {
                $this->db->table('grants')->insert([
                    'permission_id' => self::PERMISSION,
                    'person_id'     => $holder['person_id']
                ]);

                $granted++;
            }
        }

        if (is_cli()) {
            CLI::write("AddAnalyticsReportPermission: granted to $granted employee(s) who already hold reports_sales.");

            if ($granted === 0) {
                CLI::write('  ! Nobody holds reports_sales, so nobody was granted the analytical report. Grant it by hand from Employees.');
            }
        }
    }

    /**
     * Revert a migration step.
     */
    public function down(): void
    {
        $this->db->table('grants')->where('permission_id', self::PERMISSION)->delete();
        $this->db->table('permissions')->where('permission_id', self::PERMISSION)->delete();
    }
}
