<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => 'order_status_changed',
            'data' => [
                'orderId' => fake()->uuid(),
                'orderNumber' => 'GR-'.fake()->numerify('######'),
                'fromStatus' => 'confirmed',
                'toStatus' => 'picking',
                'message' => 'Your order is being picked.',
            ],
            'read_at' => null,
        ];
    }

    public function read(): static
    {
        return $this->state(fn (array $attributes) => [
            'read_at' => now(),
        ]);
    }
}
