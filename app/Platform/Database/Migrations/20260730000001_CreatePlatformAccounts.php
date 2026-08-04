<?php

namespace Platform\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Platform-level identities, in the platform control schema: business
 * owners (who may be linked to more than one tenant, see
 * platform_account_tenants) and platform administrators. Distinct from
 * each tenant's own `employees`/`people` -- those stay fully isolated
 * per schema.
 */
class CreatePlatformAccounts extends Migration
{
    protected $DBGroup = 'platform';

    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'email'         => ['type' => 'VARCHAR', 'constraint' => 255],
            'password_hash' => ['type' => 'VARCHAR', 'constraint' => 255],
            'is_platform_admin' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('email');
        $this->forge->createTable('platform_accounts');
    }

    public function down(): void
    {
        $this->forge->dropTable('platform_accounts', true);
    }
}
