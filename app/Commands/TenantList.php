<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Prints the db_name of every active tenant, one per line, plain (no
 * color/banner) -- meant to be captured by scripts/migrate-tenants.sh,
 * not read by a human.
 */
class TenantList extends BaseCommand
{
    protected $group       = 'Platform';
    protected $name        = 'tenant:list';
    protected $description = 'List active tenant db_names, one per line.';

    public function run(array $params)
    {
        $tenants = db_connect('platform')
            ->table('tenants')
            ->select('db_name')
            ->where('status', 'active')
            ->get()
            ->getResultArray();

        // Prefixed and grepped for in scripts/migrate-tenants.sh -- spark
        // always writes its own banner/timestamp line to stdout first,
        // regardless of which command runs, so plain output isn't safe
        // to capture as-is.
        foreach ($tenants as $tenant) {
            CLI::write('TENANT_DB:' . $tenant['db_name']);
        }
    }
}
