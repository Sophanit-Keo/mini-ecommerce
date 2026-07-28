<?php

namespace Database\Factories;

use App\Enums\OrderItemStatus;
use App\Enums\SoldBy;
use App\Enums\SubstitutionPreference;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The default state is an unpicked unit line: `estimated_weight_kg` is null as
 * `ck_order_items_weight_shape` requires, and `final_line_total` is null because
 * `ck_order_items_picked_shape` forbids a final total on a pending line.
 *
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPrice = Money::round(fake()->randomFloat(2, 0.99, 24.99));
        $quantity = (string) fake()->numberBetween(1, 4);

        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'product_name' => fake()->words(3, true),
            'product_sku' => strtoupper(fake()->unique()->bothify('???-#####')),
            'brand' => fake()->company(),
            'sold_by' => SoldBy::Unit,
            'unit_label' => 'ea',
            'unit_price' => $unitPrice,
            'ordered_quantity' => $quantity,
            'estimated_weight_kg' => null,
            'estimated_line_total' => Money::lineTotal($unitPrice, $quantity),
            'picked_quantity' => null,
            'picked_weight_kg' => null,
            'final_line_total' => null,
            'substitution_preference' => SubstitutionPreference::Similar,
            'status' => OrderItemStatus::Pending,
            'note' => null,
        ];
    }

    /**
     * A line priced per kilogram. The estimate comes from the product's average weight; the
     * real figure does not exist until someone puts it on a scale.
     */
    public function weighed(?float $quantityKg = null): static
    {
        return $this->state(function (array $attributes) use ($quantityKg) {
            $pricePerKg = Money::round(fake()->randomFloat(2, 1.49, 39.99));
            $quantity = Money::round($quantityKg ?? fake()->randomFloat(3, 0.3, 2.0), Money::QUANTITY_SCALE);

            return [
                'sold_by' => SoldBy::Weight,
                'unit_label' => 'kg',
                'unit_price' => $pricePerKg,
                'ordered_quantity' => $quantity,
                'estimated_weight_kg' => $quantity,
                'estimated_line_total' => Money::lineTotal($pricePerKg, $quantity),
            ];
        });
    }

    /**
     * Picked as ordered. For a weighed line the actual scale reading drives the final total,
     * which is why it rarely equals the estimate.
     */
    public function picked(?float $actualWeightKg = null): static
    {
        return $this->state(function (array $attributes) use ($actualWeightKg) {
            $isWeighed = $attributes['sold_by'] === SoldBy::Weight;

            $pickedQuantity = $isWeighed
                ? Money::round($actualWeightKg ?? $attributes['ordered_quantity'], Money::QUANTITY_SCALE)
                : $attributes['ordered_quantity'];

            return [
                'status' => OrderItemStatus::Picked,
                'picked_quantity' => $pickedQuantity,
                'picked_weight_kg' => $isWeighed ? $pickedQuantity : null,
                'final_line_total' => Money::lineTotal($attributes['unit_price'], $pickedQuantity),
            ];
        });
    }

    /**
     * Swapped for something else. `final_line_total` carries the authoritative figure for the
     * line (R-03) — the substitution row alongside it is audit only.
     */
    public function substituted(string $finalLineTotal): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderItemStatus::Substituted,
            'picked_quantity' => $attributes['ordered_quantity'],
            'final_line_total' => $finalLineTotal,
        ]);
    }

    /**
     * Out of stock and not substituted. No final total — the customer is not charged.
     */
    public function unavailable(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderItemStatus::Unavailable,
            'picked_quantity' => null,
            'picked_weight_kg' => null,
            'final_line_total' => null,
        ]);
    }
}
