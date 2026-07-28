<?php

namespace App\Models;

use App\Enums\CartStatus;
use App\Models\Concerns\HasPublicId;
use Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A cart belongs to either a signed-in user or an anonymous browser session, never neither
 * — `ck_carts_owner` enforces that. The `active_for_user` / `active_for_guest` unique
 * generated columns guarantee at most one active cart per owner in the database, so two
 * tabs cannot silently produce two carts.
 */
#[Fillable(['user_id', 'guest_token', 'status', 'currency', 'expires_at'])]
class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use HasFactory, HasPublicId;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CartItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CartStatus::class,
            'expires_at' => 'datetime',
        ];
    }
}
