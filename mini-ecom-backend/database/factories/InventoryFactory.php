<?php

namespace Database\Factories;

use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Inventory>
 */
class InventoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'quantity_on_hand' => fake()->randomFloat(3, 10, 500),
            'quantity_reserved' => 0.000,
            'low_stock_threshold' => 5.000,
            'restock_expected_at' => null,
        ];
    }

    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity_on_hand' => 0.000,
            'quantity_reserved' => 0.000,
        ]);
    }

    /**
     * `ck_inventory_not_oversold` forbids reserving more than is on hand, so the reserved
     * figure is clamped to what the state actually has.
     */
    public function reserved(float $quantity): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity_on_hand' => max($quantity, (float) $attributes['quantity_on_hand']),
            'quantity_reserved' => $quantity,
        ]);
    }
}
