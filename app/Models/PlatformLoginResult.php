<?php

declare(strict_types=1);

namespace App\Models;

/**
 * What PlatformAccount::login() answers: one of the four outcomes, and the account it is about.
 *
 * A PHP enum cannot carry per-case data, and every outcome except InvalidCredentials has an account
 * the caller needs -- to log `account.locked` against it, to show whose second factor is being
 * asked for, to stamp the session. So the enum stays the vocabulary and this carries the subject.
 *
 * `account` is null for exactly one outcome, InvalidCredentials, and it is null there ON PURPOSE
 * rather than as an accident of the lookup: "no such email" and "wrong password for a real email"
 * must be indistinguishable to the caller (D8), so neither of them hands back a row that the other
 * could not have handed back.
 */
final class PlatformLoginResult
{
    private function __construct(
        public readonly PlatformLoginOutcome $outcome,
        public readonly ?object $account,
        /**
         * True only when THIS attempt is the one that tripped the counter, false when the account
         * was already shut. D6 records `account.locked` -- the modification -- and not each failed
         * attempt, so the controller needs to know which of the two it is looking at or it would
         * write a row on every refusal for the next two hours.
         */
        public readonly bool $justLocked = false,
    ) {
    }

    public static function success(object $account): self
    {
        return new self(PlatformLoginOutcome::Success, $account);
    }

    public static function invalidCredentials(): self
    {
        return new self(PlatformLoginOutcome::InvalidCredentials, null);
    }

    public static function locked(object $account, bool $justLocked = false): self
    {
        return new self(PlatformLoginOutcome::Locked, $account, $justLocked);
    }

    public static function secondFactorRequired(object $account): self
    {
        return new self(PlatformLoginOutcome::SecondFactorRequired, $account);
    }

    public function isSuccess(): bool
    {
        return $this->outcome === PlatformLoginOutcome::Success;
    }
}
