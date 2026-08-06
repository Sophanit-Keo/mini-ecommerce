<?php

namespace App\Actions\Auth;

use App\Models\User;

/**
 * Resends the verification notification, unless the account is already verified.
 */
class ResendEmailVerification
{
    /**
     * @return bool true if a notification was sent, false if already verified
     */
    public function handle(User $user): bool
    {
        if ($user->hasVerifiedEmail()) {
            return false;
        }

        $user->sendEmailVerificationNotification();

        return true;
    }
}
