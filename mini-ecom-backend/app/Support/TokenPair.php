<?php

namespace App\Support;

use App\Models\User;

/**
 * A short-lived access token plus the single-use refresh token that will replace it.
 *
 * The refresh token is the only time the plaintext exists — only its SHA-256 is stored, so
 * a database leak yields no usable sessions.
 */
final readonly class TokenPair
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public int $expiresIn,
        public User $user,
    ) {}
}
