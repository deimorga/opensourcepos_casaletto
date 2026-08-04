<?php

namespace Platform\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tenant registry, in the platform control schema. One row per
 * negocio-cliente; resolved by TenantResolver (Fase 4) from the request
 * Host, never from the per-tenant `default` connection.
 */
class CreatePlatformTenants extends Migration
{
    protected $DBGroup = 'platform';

    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'slug'       => ['type' => 'VARCHAR', 'constraint' => 100],
            'db_name'    => ['type' => 'VARCHAR', 'constraint' => 100],
            'db_user'    => ['type' => 'VARCHAR', 'constraint' => 100],
            'status'     => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'active'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('slug');
        $this->forge->createTable('tenants');
    }

    public function down(): void
    {
        $this->forge->dropTable('tenants', true);
    }
}
