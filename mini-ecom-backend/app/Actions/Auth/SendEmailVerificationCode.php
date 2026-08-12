<?php

namespace App\Actions\Auth;

use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;

/**
 * Issues a fresh 6-digit verification code for a user and emails it.
 *
 * Only the SHA-256 of the code is persisted (see `EmailVerificationCode`). `updateOrCreate`
 * on the unique `user_id` column both enforces "one active code per user" and makes a resend
 * invalidate whatever code was issued before it, rather than accumulating rows.
 */
class SendEmailVerificationCode
{
    private const TTL_MINUTES = 10;

    public function handle(User $user): void
    {
        $code = (string) random_int(100000, 999999);

        EmailVerificationCode::updateOrCreate(
            ['user_id' => $user->id],
            [
                'code_hash' => EmailVerificationCode::hash($code),
                // A new email creates a *new credential*, so it must receive a fresh attempt
                // budget rather than inheriting failures from the code it replaced.
                'attempt_count' => 0,
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ],
        );

        $user->notify(new EmailVerificationCodeNotification($code));
    }
}
