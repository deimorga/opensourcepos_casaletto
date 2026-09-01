<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\PlatformContext;
use App\Models\PlatformAccount;
use App\Models\PlatformActivity;
use CodeIgniter\HTTP\Exceptions\RedirectException;
use Config\Services;

/**
 * What every screen of the platform console has in common: who is allowed in, who is only halfway
 * in, who is asking, and what language the console speaks.
 *
 * The underscore in the name is not a typo. It matches Secure_Controller, which is the same idea
 * one layer down -- the base every gated controller of the point of sale extends. The two guard
 * completely different things and must never be confused: Secure_Controller checks a tenant's
 * `employees` grants, this one checks `platform_accounts.is_platform_admin` in the control schema.
 * An operator of this console has no employee row anywhere, and an employee of a business has no
 * standing here at all.
 *
 * THE TWO GUARDS, IN THIS ORDER
 *
 * 1. A pending second factor comes FIRST. Somebody who gave the right password and has not yet
 *    answered the TOTP challenge is not logged in -- the session holds no `platform_account_id`
 *    at all -- so without this branch they would be bounced to the login form and would type the
 *    password again, forever. They belong on the challenge screen, and every console page has to
 *    agree about that or one of them becomes a way around the factor.
 *
 * 2. Then the ordinary gate. Redirect and not 403, because there is nothing here to tell an
 *    anonymous visitor about.
 *
 * WHY THE LOCALE IS SET HERE AS WELL AS IN Load_config
 *
 * Load_config runs on `post_controller_constructor` -- AFTER this constructor. Both guards above
 * can end the request with a translated message before that event ever fires, and a console that
 * answers in English exactly when it is refusing somebody is the least useful moment to do it. The
 * value comes from PlatformContext so the two can never drift; see the constant there for why the
 * console is single-locale on purpose.
 */
abstract class Platform_Controller extends BaseController
{
    protected PlatformAccount $account;
    protected PlatformActivity $activity;

    /**
     * The row of whoever is operating the console. Read once, in the constructor, past both
     * guards -- so every method below can rely on it existing without asking again.
     */
    private object $loggedInAccount;

    public function __construct()
    {
        Services::language()->setLocale(PlatformContext::LOCALE);

        $this->account  = model(PlatformAccount::class);
        $this->activity = model(PlatformActivity::class);

        if ($this->account->pendingSecondFactorAccountId() !== null) {
            throw new RedirectException('platform/login/totp');
        }

        $account = $this->account->getLoggedInAccount();

        if ($account === null || ! (bool) $account->is_platform_admin) {
            throw new RedirectException('platform/login');
        }

        $this->loggedInAccount = $account;
    }

    protected function currentAccount(): object
    {
        return $this->loggedInAccount;
    }

    protected function currentAccountId(): int
    {
        return (int) $this->loggedInAccount->id;
    }

    /**
     * Records a modification (D6). The actor is passed explicitly rather than left to the model to
     * look up: it is already in hand here, and a log line that quietly comes out anonymous because
     * a session expired mid-request would be worse than no line at all.
     *
     * Never called for a page view or a successful login -- see App\Models\PlatformActivity for
     * what this log deliberately does not contain.
     *
     * @param array $detail stored as JSON. Never a password, a TOTP secret or a recovery code.
     */
    protected function logActivity(
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $detail = [],
    ): void {
        $this->activity->record($action, $targetType, $targetId, $detail, $this->loggedInAccount);
    }
}
