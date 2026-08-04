<?php

namespace Platform\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Each tenant gets its own dedicated MySQL user (Fase 7 provisioning),
 * not the shared admin credentials TenantResolver fell back to since
 * Fase 4 -- this column is what lets it stop doing that. Encrypted at
 * rest with CI4's Encryption service (same encryption.key already
 * configured for Email_lib), not plaintext.
 */
class AddDbPasswordToTenants extends Migration
{
    protected $DBGroup = 'platform';

    public function up(): void
    {
        $this->forge->addColumn('tenants', [
            'db_password' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'db_user'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('tenants', 'db_password');
    }
}
