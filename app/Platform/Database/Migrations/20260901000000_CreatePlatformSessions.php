<?php

namespace Platform\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Session storage for the platform console, in the platform control schema.
 *
 * Until now the console had nowhere of its own to keep a session: Config\Session uses the
 * DatabaseHandler over the DEFAULT connection with savePath 'sessions', so the panel's session
 * lived inside whichever business's database the panel was reached through -- in practice, inside
 * Casaletto's. Moving the console to its own address is what makes this table necessary, because
 * on that address the default connection deliberately points at nothing a business owns.
 *
 * THE PRIMARY KEY IS COMPOSITE, AND THAT IS NOT A STYLE CHOICE
 *
 * Config\Session::$matchIP is true in this project. CodeIgniter's DatabaseHandler then reads,
 * locks and writes every row by (id, ip_address). A primary key on `id` alone does not fail: it
 * just stops matching as soon as the client's address changes -- a phone moving between wifi and
 * mobile data, a laptop through a different exit node -- and the console logs the superadministrator
 * out mid-task with nothing in any log to explain it. The framework's manual warns about it next to
 * that setting, and OSPOS itself already got it right for the POS in
 * app/Database/Migrations/sqlscripts/3.4.1_migrate_sessions_table.sql, whose structure this copies.
 *
 * Run with `php spark platform:migrate`, never with the stock `migrate -g platform`: the built-in
 * runner writes its history on the default connection regardless of -g, which is how the platform's
 * migration history ended up inside Casaletto's database once already. See
 * docs/Tecnico/gestion-de-plataforma-y-negocios.md section 9.14.
 */
class CreatePlatformSessions extends Migration
{
    protected $DBGroup = 'platform';

    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
            'ip_address' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => false],
            'timestamp'  => ['type' => 'TIMESTAMP', 'null' => false, 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'data'       => ['type' => 'BLOB', 'null' => false],
        ]);

        // Both columns, in this order: the handler always knows the id and filters on the address.
        $this->forge->addPrimaryKey(['id', 'ip_address']);

        // The garbage collector deletes by timestamp alone on every request that opens a session.
        $this->forge->addKey('timestamp', false, false, 'platform_sessions_timestamp');

        $this->forge->createTable('platform_sessions');
    }

    public function down(): void
    {
        $this->forge->dropTable('platform_sessions', true);
    }
}
