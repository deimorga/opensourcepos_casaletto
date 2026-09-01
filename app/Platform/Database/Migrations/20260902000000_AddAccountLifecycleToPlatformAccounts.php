<?php

namespace Platform\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What a superadministrator account has to be able to say about itself before the console can
 * answer the one question section 6.1 of the functional scope asks: "which of these accounts
 * should not exist?".
 *
 * FOUR COLUMNS, TWO PURPOSES
 *
 * `last_login_at` and `created_by_account_id` answer that question. The platform has two accounts
 * today and one of them -- admin@ospos-saas.micronuba.net -- was created from a terminal with
 * `php spark platform:create-account`, nobody wrote down its password, and it can delete any
 * business together with its database. A listing that shows only the email cannot tell the two
 * apart. One that shows "created by nobody, never used" names the orphan on sight.
 *
 * `failed_login_count` and `failed_login_first_at` are the rate limit of D8: three failures per
 * two hours, counted ON THE ACCOUNT, with a window that heals itself.
 *
 * WHY `created_by_account_id` IS NULLABLE AND CARRIES NO FOREIGN KEY
 *
 * NULL is not "unknown", it is the signal: it means the account was created from the command
 * line, which is exactly what betrays the orphan. And a foreign key would be actively wrong here
 * -- deleting the account that created another one must not cascade, nor block, nor rewrite the
 * record of who created what. The activity log makes the same trade for the same reason and keeps
 * a denormalised copy of the email; see CreatePlatformActivityLog.
 *
 * WHY THIS IS ITS OWN MIGRATION AND NOT ONE WITH THE TOTP COLUMNS
 *
 * So the login brake can ship even if the second factor slips, and so each down() reverses one
 * concern rather than three.
 *
 * Run with `php spark platform:migrate`, never with the stock `migrate -g platform`: the built-in
 * runner writes its history on the default connection regardless of -g, which is how the
 * platform's migration history ended up inside Casaletto's database once already. See
 * docs/Tecnico/gestion-de-plataforma-y-negocios.md section 9.14.
 */
class AddAccountLifecycleToPlatformAccounts extends Migration
{
    protected $DBGroup = 'platform';

    public function up(): void
    {
        $this->forge->addColumn('platform_accounts', [
            'last_login_at' => [
                'type'  => 'DATETIME',
                'null'  => true,
                'after' => 'is_platform_admin',
            ],
            'created_by_account_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'last_login_at',
            ],
            // NOT NULL DEFAULT 0 rather than nullable: "never failed" and "failed zero times since
            // the window opened" are the same state, and a nullable counter would make every
            // increment have to decide which one it was looking at.
            'failed_login_count' => [
                'type'       => 'SMALLINT',
                'constraint' => 5,
                'unsigned'   => true,
                'null'       => false,
                'default'    => 0,
                'after'      => 'created_by_account_id',
            ],
            // When the CURRENT window opened, not when the last attempt happened. The window is
            // two hours from the first failure, so a stream of attempts cannot push its end
            // further away by arriving.
            'failed_login_first_at' => [
                'type'  => 'DATETIME',
                'null'  => true,
                'after' => 'failed_login_count',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('platform_accounts', [
            'last_login_at',
            'created_by_account_id',
            'failed_login_count',
            'failed_login_first_at',
        ]);
    }
}
