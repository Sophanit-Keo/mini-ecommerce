<?php

namespace App\Actions\Auth;

use Illuminate\Support\Facades\Password;

/**
 * Requests a reset link for an email address, unconditionally.
 *
 * `Password::sendResetLink()` answers differently depending on whether the address has an
 * account, is already throttled, and so on — every one of those is an enumeration oracle for
 * an unauthenticated endpoint that exists solely to answer "does this email have an account".
 * The status is deliberately discarded here; the controller always reports the same outcome.
 */
class SendPasswordResetLink
{
    public function handle(string $email): void
    {
        Password::sendResetLink(['email' => $email]);
    }
}
