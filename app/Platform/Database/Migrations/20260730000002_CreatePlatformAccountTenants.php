<?php

namespace Platform\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Membership: which platform_accounts can reach which tenants. A
 * business owner with a single negocio has exactly one row here; an
 * owner of several negocios has one row per tenant, driving the
 * business-switcher in the platform login (Fase 8).
 */
class CreatePlatformAccountTenants extends Migration
{
    protected $DBGroup = 'platform';

    public function up(): void
    {
        $this->forge->addField([
            'account_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'tenant_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey(['account_id', 'tenant_id']);
        $this->forge->addForeignKey('account_id', 'platform_accounts', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', '', 'CASCADE');
        $this->forge->createTable('platform_account_tenants');
    }

    public function down(): void
    {
        $this->forge->dropTable('platform_account_tenants', true);
    }
}
