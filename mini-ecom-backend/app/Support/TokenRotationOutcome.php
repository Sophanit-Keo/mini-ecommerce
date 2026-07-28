<?php

namespace App\Support;

/**
 * What a presented refresh token turned out to be.
 *
 * Every failing outcome answers the client identically (401 `token-revoked`); they are kept
 * apart here only because each requires a different write on the server.
 */
enum TokenRotationOutcome
{
    case Rotated;
    case Replayed;
    case Expired;
    case Unknown;
}
