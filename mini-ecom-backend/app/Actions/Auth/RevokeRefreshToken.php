<?php

namespace App\Actions\Auth;

use App\Models\RefreshToken;
use App\Models\User;

/**
 * Signs a device out by revoking the refresh token it holds.
 *
 * Idempotent: revoking an already-revoked or unknown token is a no-op, because the caller's
 * intent — "this token must not work" — is already satisfied.
 *
 * The presented *access* token is deliberately left alive for the remainder of its 15
 * minutes. Killing it would make a second logout call answer 401 rather than the 204 the
 * contract promises, and a short access-token lifetime is precisely the mechanism chosen to
 * bound that window.
 */
class RevokeRefreshToken
{
    public function handle(User $user, string $plainToken): void
    {
        RefreshToken::where('user_id', $user->id)
            ->where('token_hash', RefreshToken::hash($plainToken))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }
}
