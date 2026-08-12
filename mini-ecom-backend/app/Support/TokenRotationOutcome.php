<?php

namespace App\Support;

/**
 * What a presented refresh token turned out to be.
 *
 * Every failing outcome answers the client identically (401 `token-revoked`) except
 * `Suspended`, which answers 403 `account-suspended`: a client that sees 401 correctly tries to
 * refresh again, and for a suspended account that is an infinite loop. They are otherwise kept
 * apart here only because each requires a different write on the server.
 */
enum TokenRotationOutcome
{
    case Rotated;
    case Replayed;
    case Expired;
    case Unknown;
    case Suspended;
}
