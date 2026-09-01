<?php

namespace Platform\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The way back in when the phone with the second factor is gone.
 *
 * With one real superadministrator and TOTP switched on, losing the phone means the only remaining
 * door is a database client -- see section 9.12 of the technical design. These codes are that
 * door, and they are the only recovery this design has, because the platform deliberately owns no
 * channel to send anything through.
 *
 * WHY A TABLE AND NOT A JSON COLUMN ON THE ACCOUNT
 *
 * Because "single use" has to be a fact, not an intention. A row can be spent atomically:
 *
 *     UPDATE ... SET used_at = NOW() WHERE id = ? AND used_at IS NULL
 *
 * and `affectedRows() === 1` is the proof that THIS request is the one that spent it. Read-modify-
 * write on a JSON array cannot say that: two requests arriving together both read a code as unused
 * and both let it through. That there is one operator today does not make the race impossible, it
 * makes it rare -- and a rare authentication bypass is worse than a common one, because nothing
 * will ever reproduce it.
 *
 * WHY SHA-256 AND NOT bcrypt/argon2
 *
 * These are not passwords. A password is short, chosen by a person, and guessable, so it needs a
 * slow hash to make guessing expensive. A recovery code is generated here from
 * `random_bytes()` with full entropy: there is nothing to guess, so the only property required of
 * the hash is that it be one-way. A fast hash is also what makes the single-use UPDATE above a
 * plain indexed lookup instead of a scan over every row belonging to the account, each one costing
 * a deliberate 100 ms. CHAR(64) is exactly the hex form of SHA-256, so the column cannot silently
 * hold something else.
 *
 * The unique index over `code_hash` is what makes the lookup an index hit, and closes the door on
 * ever issuing the same code twice.
 *
 * No foreign key on `account_id`, for the same reason as everywhere else in this schema: cleanup
 * is the application's job and is written where it can be read. The index is there because every
 * query is "the codes of this account".
 *
 * Run with `php spark platform:migrate`. See docs/Tecnico/gestion-de-plataforma-y-negocios.md
 * section 9.14.
 */
class CreatePlatformAccountRecoveryCodes extends Migration
{
    protected $DBGroup = 'platform';

    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'account_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => false],
            'code_hash'  => ['type' => 'CHAR', 'constraint' => 64, 'null' => false],
            'used_at'    => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('code_hash', 'platform_recovery_codes_hash');
        $this->forge->addKey('account_id', false, false, 'platform_recovery_codes_account');

        $this->forge->createTable('platform_account_recovery_codes');
    }

    public function down(): void
    {
        $this->forge->dropTable('platform_account_recovery_codes', true);
    }
}
