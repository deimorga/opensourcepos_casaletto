<?php

namespace App\Commands;

use App\Models\PlatformAccount;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Bootstraps a platform account -- most importantly the very first
 * platform administrator, since there is no other way to reach
 * app/Controllers/PlatformAdmin.php (Fase 8) without one already
 * existing. Also usable to create a business-owner-only account
 * (no --admin flag) ahead of linking it to a tenant.
 */
class PlatformAccountCreate extends BaseCommand
{
    protected $group       = 'Platform';
    protected $name        = 'platform:create-account';
    protected $description = 'Create a platform account (business owner, or platform admin with --admin).';
    protected $arguments    = [
        'email' => 'Login email for the new account.',
    ];
    protected $options = [
        '--admin' => 'Grant platform-admin rights (access to the business-management panel).',
    ];

    public function run(array $params)
    {
        $email = $params[0] ?? null;

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            CLI::error('Usage: php spark platform:create-account <email> [--admin]');

            return 1;
        }

        $account = model(PlatformAccount::class);

        if ($account->where('email', $email)->countAllResults() > 0) {
            CLI::error("An account with email '$email' already exists.");

            return 1;
        }

        $isAdmin = CLI::getOption('admin') !== null;
        $password = bin2hex(random_bytes(8));

        $account->createAccount($email, $password, $isAdmin);

        CLI::write('');
        CLI::write('Platform account created' . ($isAdmin ? ' (platform admin)' : '') . '.', 'green');
        CLI::write("  Login -- email: $email  password: $password");
        CLI::write('  Relay this password securely and have the account owner change it on first login.');

        return 0;
    }
}
