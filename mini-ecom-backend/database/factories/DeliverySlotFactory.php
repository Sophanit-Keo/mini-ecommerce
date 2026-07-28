<?php

namespace Database\Factories;

use App\Models\DeliverySlot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliverySlot>
 */
class DeliverySlotFactory extends Factory
{
    /**
     * `uq_delivery_slots_window` makes (starts_at, ends_at) unique, so windows march forward
     * in two-hour steps rather than being drawn at random — otherwise any test that creates
     * two orders eventually collides on a duplicate window instead of testing what it meant to.
     */
    private static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now()->addDay()->startOfDay()->addHours(8 + (2 * self::$sequence++));

        return [
            'slot_date' => $startsAt->toDateString(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(2),
            'capacity' => 20,
            'booked_count' => 0,
            'fee' => 3.99,
            'is_active' => true,
        ];
    }

    public function full(): static
    {
        return $this->state(fn (array $attributes) => [
            'booked_count' => $attributes['capacity'],
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
