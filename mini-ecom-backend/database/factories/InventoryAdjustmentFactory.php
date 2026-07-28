<?php

namespace Database\Factories;

use App\Enums\InventoryAdjustmentReason;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryAdjustment>
 */
class InventoryAdjustmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'delta' => fake()->randomFloat(3, 1, 50),
            'reason' => InventoryAdjustmentReason::Restock,
            'reference_type' => null,
            'reference_id' => null,
            'note' => null,
            'created_by' => null,
        ];
    }

    public function reason(InventoryAdjustmentReason $reason, float $delta): static
    {
        return $this->state(fn (array $attributes) => [
            'reason' => $reason,
            'delta' => $delta,
        ]);
    }
}
