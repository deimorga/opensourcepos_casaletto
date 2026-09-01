<?php

namespace Platform\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The console's record of what it changed -- level 1 of section 7 of the technical design.
 *
 * D6 settles the scope and its cost in one sentence: MODIFICATIONS are recorded, accesses are not.
 * The system will therefore never be able to answer "who logged in and when" -- `last_login_at`
 * covers the only part of that anyone asked for -- and it will always be able to answer "who
 * changed what". Failed attempts are not rows here either; the one that is, is `account.locked`,
 * because the counter tripping is a real change of state and the account stays shut until somebody
 * acts.
 *
 * WHY `account_email` IS DENORMALISED, WHICH IS THE WHOLE POINT OF THIS TABLE
 *
 * The first thing this log will be used for is deleting the orphan account. If the actor were only
 * `account_id`, the row that says who deleted it would read "account #2 deleted account #3" and,
 * once account #2 is itself retired, would never be legible again. The email is copied in at write
 * time so the record survives the deletion of everything it refers to. That is also why there is no
 * foreign key: a log that can be broken by a DELETE elsewhere is not a log.
 *
 * `account_id` is nullable for the same family of reasons -- something may be recorded from the
 * command line, where there is no session -- and `target_id` is a VARCHAR rather than an INT
 * because the targets are of different kinds: a business is named by its slug, an account by its
 * numeric id.
 *
 * `detail` IS JSON IN A TEXT COLUMN, ON PURPOSE
 *
 * The shape of what is worth keeping differs per action and will keep changing; a column per field
 * would be a migration per action. TEXT rather than the JSON type so the same file works on the
 * MariaDB versions this project actually runs, and so a human reading the row with a database
 * client sees the text.
 *
 * THE TWO INDEXES
 *
 * `created_at` because the screen is a reverse-chronological list and that is its ORDER BY.
 * `(target_type, target_id)` in that order because the second question this log gets asked is
 * "everything that ever happened to THIS business", and a composite in that order also serves
 * "everything that happened to any business" on its own.
 *
 * Run with `php spark platform:migrate`. See docs/Tecnico/gestion-de-plataforma-y-negocios.md
 * section 9.14.
 */
class CreatePlatformActivityLog extends Migration
{
    protected $DBGroup = 'platform';

    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'account_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'account_email' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'action'        => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => false],
            'target_type'   => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'target_id'     => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'detail'        => ['type' => 'TEXT', 'null' => true],
            // 45 characters: the longest textual IPv6 address, including an IPv4-mapped tail.
            // Same width Config\Session's table uses, so the two never disagree.
            'ip_address' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('created_at', false, false, 'platform_activity_created_at');
        $this->forge->addKey(['target_type', 'target_id'], false, false, 'platform_activity_target');

        $this->forge->createTable('platform_activity_log');
    }

    public function down(): void
    {
        $this->forge->dropTable('platform_activity_log', true);
    }
}
