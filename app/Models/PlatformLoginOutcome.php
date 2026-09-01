<?php

declare(strict_types=1);

namespace App\Models;

/**
 * The four ways a platform login can end.
 *
 * PlatformAccount::login() used to return `?object`: the account, or null. Null had to stand for
 * every kind of failure at once, and Entrega 2 adds two more of them -- the account is shut by the
 * brake of D8, and the password was right but the second factor of D11 has not been given yet.
 * Those are three genuinely different situations for the controller and they cannot be told apart
 * by the absence of a row.
 *
 * WHAT THE SCREEN IS ALLOWED TO SAY IS A SEPARATE QUESTION
 *
 * D8 requires that the error not reveal whether the email exists. `Locked` therefore must NOT get
 * a message of its own on the login form: an account that answers "too many attempts" while an
 * unknown address answers "wrong password" has just confirmed the address. The recommended
 * rendering is one message for both -- Platform.invalid_credentials, whose text already mentions
 * the two-hour brake, so it is true either way and tells nobody anything.
 *
 * The distinction still has to reach the controller, because the controller does two things the
 * screen does not: it records `account.locked` in the activity log the moment the counter trips,
 * and it decides where to send a half-authenticated visitor.
 */
enum PlatformLoginOutcome
{
    /**
     * Password verified, no second factor pending: the session is open.
     */
    case Success;

    /**
     * Wrong password, or no such account. Deliberately one case and not two.
     */
    case InvalidCredentials;

    /**
     * Three failures inside the two-hour window (D8). Refused even when the password is right.
     */
    case Locked;

    /**
     * Password verified, but the account carries TOTP and has not answered the challenge yet.
     */
    case SecondFactorRequired;
}
