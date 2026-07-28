<?php

namespace App\Actions\Auth;

use App\Models\RefreshToken;
use App\Models\User;
use App\Support\TokenPair;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Mints an access/refresh pair for a user.
 *
 * The access token is a Sanctum personal access token with an explicit 15-minute expiry;
 * the refresh token is an opaque 64-character string whose SHA-256 is all that is persisted.
 */
class IssueTokenPair
{
    public function handle(User $user, Request $request, ?RefreshToken $replacing = null): TokenPair
    {
        $accessTtl = (int) config('api.access_token_ttl');

        $accessToken = $user->createToken(
            name: 'api',
            expiresAt: now()->addSeconds($accessTtl),
        );

        $plainRefreshToken = Str::random(64);

        $refreshToken = RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => RefreshToken::hash($plainRefreshToken),
            'expires_at' => now()->addSeconds((int) config('api.refresh_token_ttl')),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
            'ip_address' => $request->ip() === null ? null : inet_pton($request->ip()),
        ]);

        // Record the rotation chain. Reuse of an already-rotated token is the standard signal
        // of theft, and the chain is what lets the whole session tree be revoked at once.
        $replacing?->forceFill([
            'revoked_at' => now(),
            'replaced_by_id' => $refreshToken->id,
        ])->save();

        return new TokenPair(
            accessToken: $accessToken->plainTextToken,
            refreshToken: $plainRefreshToken,
            expiresIn: $accessTtl,
            user: $user,
        );
    }
}
