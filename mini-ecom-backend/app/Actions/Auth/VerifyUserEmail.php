<?php

namespace App\Actions\Auth;

use App\Exceptions\ProblemException;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Auth\Events\Verified;

/**
 * Verifies the authenticated user's email against the 6-digit code they were sent.
 *
 * The code is checked against the row for `$user` specifically — never looked up by an
 * arbitrary identifier from the request body — because a 6-digit code is guessable in ~1M
 * attempts and this is the control that keeps that from being a brute-forceable
 * account-takeover surface for an anonymous caller. The route this backs must stay behind
 * `auth:sanctum`.
 */
class VerifyUserEmail
{
    public function handle(User $user, string $code): User
    {
        $record = EmailVerificationCode::where('user_id', $user->id)->first();

        if ($record === null
            || ! $record->isUsable()
            || ! hash_equals($record->code_hash, EmailVerificationCode::hash($code))
        ) {
            // Never distinguishes "no code on file", "expired" and "wrong digits" — all three
            // are the same "this code no longer works" from the caller's point of view.
            throw ProblemException::validationFailed([
                ['field' => 'code', 'message' => 'This verification code is invalid or has expired.'],
            ]);
        }

        // Single-use: the row is gone whether or not the account was already verified.
        $record->delete();

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();

            event(new Verified($user));
        }

        return $user;
    }
}
