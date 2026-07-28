<?php

namespace Database\Factories;

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Cart>
 */
class CartFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'guest_token' => null,
            'status' => CartStatus::Active,
            'currency' => 'USD',
            'expires_at' => null,
        ];
    }

    /**
     * An anonymous cart. `ck_carts_owner` requires one owner or the other, so the user is
     * cleared as the guest token is set.
     */
    public function guest(?string $token = null): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
            'guest_token' => $token ?? Str::random(40),
            'expires_at' => now()->addDays(30),
        ]);
    }

    public function status(CartStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }
}
