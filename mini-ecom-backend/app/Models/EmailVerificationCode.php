<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Only the SHA-256 of the plaintext 6-digit code is stored, mirroring how `RefreshToken`
 * stores `token_hash` — a database leak does not hand over a usable code. One row per user
 * (unique `user_id`): a resend replaces the row via `updateOrCreate` rather than accumulating
 * stale codes, and a successful verification deletes it, making the code single-use.
 */
#[Fillable(['user_id', 'code_hash', 'expires_at'])]
class EmailVerificationCode extends Model
{
    public const UPDATED_AT = null;

    public static function hash(string $plainCode): string
    {
        return hash('sha256', $plainCode);
    }

    public function isUsable(): bool
    {
        return $this->expires_at->isFuture();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }
}
