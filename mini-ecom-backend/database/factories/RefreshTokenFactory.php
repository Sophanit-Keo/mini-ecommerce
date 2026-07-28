<?php

namespace Database\Factories;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RefreshToken>
 */
class RefreshTokenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token_hash' => RefreshToken::hash(Str::random(64)),
            'expires_at' => now()->addDays(30),
            'revoked_at' => null,
            'replaced_by_id' => null,
            'user_agent' => fake()->userAgent(),
            'ip_address' => inet_pton(fake()->ipv4()),
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'revoked_at' => now(),
        ]);
    }

    /**
     * `ck_refresh_expiry` requires expires_at > issued_at, so an expired token has to have
     * been issued earlier still rather than only having its expiry moved back.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'issued_at' => now()->subDays(31),
            'expires_at' => now()->subDay(),
        ]);
    }
}
