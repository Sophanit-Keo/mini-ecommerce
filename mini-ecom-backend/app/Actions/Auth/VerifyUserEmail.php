<?php

namespace App\Actions\Auth;

use App\Exceptions\ProblemException;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;

/**
 * Verifies the authenticated user's email against the 6-digit code they were sent.
 *
 * The code is checked against the row for `$user` specifically — never looked up by an
 * arbitrary identifier from the request body — because a 6-digit code is guessable in ~1M
 * attempts and this is the control that keeps that from being a brute-forceable
 * account-takeover surface for an anonymous caller. The route this backs must stay behind
 * `auth:sanctum`.
 *
 * The endpoint has two independent controls: a user/IP rate limit for cheap burst absorption,
 * and a database-backed five-attempt budget on the code itself. The second is essential because
 * rate limits keyed by an IP can be bypassed by spreading guesses over many addresses. Locking
 * the row means two concurrent wrong-code requests cannot both read the same remaining budget.
 */
class VerifyUserEmail
{
    public function handle(User $user, string $code): User
    {
        // The transaction must return normally on an invalid code. Throwing inside it causes
        // Laravel to roll back the attempt-count update — exactly the subtle failure that would
        // make a database-backed attempt budget look correct in code while never persisting.
        $verifiedUser = DB::transaction(function () use ($user, $code): ?User {
            $record = EmailVerificationCode::where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            $valid = $record !== null
                && $record->isUsable()
                && hash_equals($record->code_hash, EmailVerificationCode::hash($code));

            if (! $valid) {
                if ($record !== null) {
                    $record->attempt_count++;

                    // Once the small code-space budget is gone, erase the hash. A resend issues
                    // a completely fresh credential; leaving the exhausted one in place would
                    // invite pointless retries and obscure the actual state.
                    if ($record->attempt_count >= (int) config('api.verification_max_attempts')) {
                        $record->delete();
                    } else {
                        $record->save();
                    }
                }

                return null;
            }

            // Single-use: the row is gone whether or not the account was already verified.
            $record->delete();

            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();

                event(new Verified($user));
            }

            return $user;
        });

        if ($verifiedUser === null) {
            // Never distinguishes "no code on file", "expired", exhausted, and wrong digits —
            // all are the same "this code no longer works" to the caller.
            throw ProblemException::validationFailed([
                ['field' => 'code', 'message' => 'This verification code is invalid or has expired.'],
            ]);
        }

        return $verifiedUser;
    }
}
