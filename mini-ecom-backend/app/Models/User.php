<?php

namespace App\Models;

use App\Actions\Auth\SendEmailVerificationCode;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Concerns\HasPublicId;
use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['email', 'password_hash', 'full_name', 'phone', 'role', 'status', 'email_verified_at', 'telegram_chat_id', 'notification_preferences'])]
#[Hidden(['password_hash'])]
class User extends Authenticatable implements MustVerifyEmailContract
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPublicId, MustVerifyEmail, Notifiable, SoftDeletes;

    /**
     * The spec names the column `password_hash`, not Laravel's default `password`.
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * Overrides the stock `MustVerifyEmail` trait, which sends the link-based
     * `Illuminate\Auth\Notifications\VerifyEmail` — this API has no landing page for a link
     * to open, so a 6-digit code the user types into the app is sent instead. See
     * `App\Actions\Auth\SendEmailVerificationCode`.
     */
    public function sendEmailVerificationNotification(): void
    {
        app(SendEmailVerificationCode::class)->handle($this);
    }

    /** @return HasMany<Address, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    /** @return HasMany<RefreshToken, $this> */
    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    /** @return HasMany<Cart, $this> */
    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<Notification, $this> */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /** @return HasMany<TelegramLinkChallenge, $this> */
    public function telegramLinkChallenges(): HasMany
    {
        return $this->hasMany(TelegramLinkChallenge::class);
    }

    /** @return HasMany<WishlistItem, $this> */
    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password_hash' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'notification_preferences' => 'array',
        ];
    }
}
