<?php

namespace App\Libraries;

use CodeIgniter\Session\Session;

/**
 * Stops a resent form from applying twice on the waiter's screen.
 *
 * THE FAILURE IT EXISTS FOR
 *
 * The waiter's screen uses classic POST forms with Post/Redirect/Get. On a bad signal the request
 * reaches the server and is saved, but the response is lost; the browser shows an error, the waiter
 * reloads, the browser offers to resend the form, the waiter accepts -- and the dish is added a second
 * time (or a second ticket is opened, or a line is voided twice). Every order-ticket form therefore
 * carries a single-use token in a hidden input named FIELD, issued when the form is rendered; the
 * controller claims it before acting, and a resubmission finds it already claimed.
 *
 * THE STATE LIVES IN THE SESSION, NOT THE DATABASE
 *
 * A resubmission comes from the same phone, with the same cookie, in the same session. The session is
 * therefore the narrowest place that sees both submissions, and keeping the claimed tokens there needs
 * no table and no migration.
 *
 * ONLY CLAIMED TOKENS ARE KEPT, AND ONLY THE LAST MAX_CLAIMED
 *
 * Issued tokens are not stored: a session that lasts a whole shift renders thousands of forms, most of
 * them never submitted. That means a well-formed token that was never issued can still be claimed once,
 * and that is fine: the threat here is REPETITION, not forgery. Where a request comes from is already
 * guarded by CSRF; this guard only answers "has this exact form been applied already?". The claimed
 * list is capped FIFO so the session cannot grow without bound; a resubmission arrives seconds or
 * minutes after the original, far inside the last MAX_CLAIMED submissions of one waiter.
 *
 * The Session object is injected rather than touching $_SESSION, so a test can hand it its own.
 */
final class Order_ticket_request_guard
{
    /**
     * Name of the hidden input every order-ticket form carries.
     */
    public const FIELD = 'request_token';

    private const SESSION_KEY = 'order_ticket_claimed_tokens';
    private const MAX_CLAIMED = 200;

    /**
     * D: without it PCRE's $ also matches before a trailing "\n", and 33 characters would pass.
     */
    private const FORMAT = '/^[0-9a-f]{32}$/D';

    private Session $session;

    public function __construct(?Session $session = null)
    {
        $this->session = $session ?? session();
    }

    /**
     * A fresh single-use token for one rendered form: 32 lowercase hex characters from random_bytes(16).
     * Nothing is stored; see the class docblock for why only claims are remembered.
     */
    public function issue(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Claims a token for this request.
     *
     * True the FIRST time a given token is claimed in this session; false every time after (the
     * resubmission), and false for a missing or malformed token -- in which case the session is not
     * written at all, so garbage input can neither evict real claims nor grow the list.
     */
    public function claim(?string $token): bool
    {
        if ($token === null || preg_match(self::FORMAT, $token) !== 1) {
            return false;
        }

        $claimed = $this->session->get(self::SESSION_KEY);
        $claimed = is_array($claimed) ? array_values($claimed) : [];

        if (in_array($token, $claimed, true)) {
            return false;
        }

        $claimed[] = $token;

        if (count($claimed) > self::MAX_CLAIMED) {
            $claimed = array_slice($claimed, -self::MAX_CLAIMED);
        }

        $this->session->set(self::SESSION_KEY, $claimed);

        return true;
    }
}
