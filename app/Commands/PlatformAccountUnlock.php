<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\PlatformAccount;
use App\Models\PlatformActivity;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Lifts the D8 lockout on one platform account from the command line.
 *
 * WHY THIS EXISTS WHEN THERE IS ALREADY A BUTTON
 *
 * The button is on `platform/accounts`, behind a login -- so unlocking somebody needs ANOTHER
 * superadministrator. That is the right shape for the screen: an account that can lift its own
 * brake does not have one. But it means the whole mechanism has a floor, and the floor is that
 * there must be two people. Today there is one real account and one orphan that is about to be
 * deleted, so the floor is exactly where it must not be: if that single account locks itself out
 * with three wrong passwords, the only remaining way in would be a MySQL client and a hand-written
 * UPDATE -- which is precisely the problem this module was built to remove.
 *
 * This command is that floor's trapdoor. It needs a shell on the server, which is a higher bar
 * than a browser and a password, and it leaves the same row in the activity log that the screen
 * would leave. See section 9.12 of the technical design.
 *
 * It is NOT a substitute for having a second real superadministrator, and it does not try to be:
 * losing the phone that holds the second factor is a different failure with a different answer
 * (the recovery codes), and both are on the same list for a reason.
 *
 * ON THE EXIT CODES
 *
 * 0 means "this account can log in as far as the brake is concerned" -- including when it was
 * never braked. The command is meant to be reachable for somebody who is not sure what is wrong,
 * and reporting failure for a no-op would send them looking for a second problem that is not
 * there. 1 is reserved for "I did not do what you asked": no such account, no email given, or the
 * database refused.
 */
class PlatformAccountUnlock extends BaseCommand
{
    protected $group       = 'Platform';
    protected $name        = 'platform:unlock-account';
    protected $description = 'Clear the failed-login brake on a platform account. Exits non-zero if it could not.';
    protected $usage       = 'platform:unlock-account <email>';
    protected $arguments   = [
        'email' => 'Email of the account to unlock.',
    ];

    public function run(array $params)
    {
        $email = trim((string) ($params[0] ?? ''));

        if ($email === '') {
            CLI::error('Usage: php spark platform:unlock-account <email>');

            return 1;
        }

        try {
            $accounts = model(PlatformAccount::class);
            $account  = $accounts->where('email', $email)->first();

            if ($account === null) {
                // The screen deliberately refuses to say whether an email exists (D8). Here it
                // says so plainly: whoever is running this already has a shell on the server, and
                // a rescue tool that will not tell you that you mistyped the address is a worse
                // trade than the one D8 is making.
                CLI::error("No platform account exists with the email '{$email}'.");

                return 1;
            }

            $attempts = (int) $account->failed_login_count;
            $since    = $account->failed_login_first_at;

            if ($attempts === 0 && $since === null) {
                CLI::write("Account '{$email}' has no failed attempts recorded. Nothing to unlock.");
                CLI::write('If it still cannot log in, the brake is not what is stopping it.');

                return 0;
            }

            $accounts->unlock((int) $account->id);

            // The same row the screen would write. Under spark there is no session, so it is
            // recorded without an actor -- which is honest, and is why `via` says where it came
            // from. A rescue that leaves no trace is how an account quietly changes hands.
            model(PlatformActivity::class)->record(
                PlatformActivity::ACCOUNT_UNLOCKED,
                PlatformActivity::TARGET_ACCOUNT,
                (string) $account->id,
                ['email' => $email, 'failed_login_count' => $attempts, 'via' => 'cli'],
            );

            CLI::write('');
            CLI::write("Unlocked '{$email}'.", 'green');
            CLI::write("  Cleared {$attempts} failed attempt(s)" . ($since === null ? '.' : ", first recorded at {$since}."));

            if ($attempts < PlatformAccount::MAX_FAILED_ATTEMPTS) {
                CLI::write('  It had not reached the limit yet, so it was not actually shut out.');
            }
        } catch (Throwable $e) {
            CLI::error('platform:unlock-account: ' . $e->getMessage());

            return 1;
        }

        return 0;
    }
}
