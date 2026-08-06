<?php

namespace App\Actions\Auth;

use App\Exceptions\ProblemException;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\Password;

/**
 * Sets a new password from a broker token and forces every other session to re-authenticate.
 *
 * The stock `PasswordBroker::reset()` callback assumes an Eloquent `password` column; this
 * app's column is `password_hash` (see `User::getAuthPassword()`), so the callback sets that
 * explicitly. `password_hash` is cast `'hashed'`, so assigning the raw password is enough —
 * Eloquent hashes it on save. Calling `Hash::make()` here as well would hash it twice.
 */
class ResetUserPassword
{
    /**
     * @param  array{email: string, token: string, password: string}  $data
     */
    public function handle(array $data): void
    {
        $status = Password::reset(
            $data,
            function (User $user, string $password): void {
                $user->password_hash = $password;
                $user->save();

                // A password reset is exactly the moment every other session should be
                // forced to re-authenticate — mirrors the "reuse revokes the whole chain"
                // posture used for a replayed refresh token (see RotateRefreshToken).
                RefreshToken::where('user_id', $user->id)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now()]);

                $user->tokens()->delete();
            }
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            // Never distinguishes an unknown email from a wrong/expired token — both are the
            // same "this reset link no longer works" from the caller's point of view.
            throw ProblemException::validationFailed([
                ['field' => 'token', 'message' => 'This reset link is invalid or has expired.'],
            ]);
        }
    }
}
