<?php

namespace Database\Factories;

use App\Enums\SubstitutionDecidedBy;
use App\Models\OrderItem;
use App\Models\OrderItemSubstitution;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItemSubstitution>
 */
class OrderItemSubstitutionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPrice = Money::round(fake()->randomFloat(2, 0.99, 24.99));
        $quantity = (string) fake()->numberBetween(1, 3);
        $lineTotal = Money::lineTotal($unitPrice, $quantity);

        return [
            'order_item_id' => OrderItem::factory(),
            'substitute_product_id' => null,
            'substitute_name' => fake()->words(3, true),
            'substitute_sku' => strtoupper(fake()->unique()->bothify('???-#####')),
            'substitute_unit_price' => $unitPrice,
            'substitute_quantity' => $quantity,
            'substitute_weight_kg' => null,
            'substitute_line_total' => $lineTotal,
            'price_delta' => '0.00',
            'reason' => fake()->sentence(),
            'decided_by' => SubstitutionDecidedBy::Picker,
            'customer_approved' => null,
            'created_by' => null,
        ];
    }

    /**
     * Record a swap against a line. `price_delta` is derived here for display only — adding
     * it to a total on top of the line's own `final_line_total` would double-count the
     * substitution (design-review finding R-03).
     */
    public function replacing(OrderItem $item, string $substituteLineTotal): static
    {
        return $this->state(fn (array $attributes) => [
            'order_item_id' => $item->id,
            'substitute_line_total' => $substituteLineTotal,
            'price_delta' => Money::sub($substituteLineTotal, $item->estimated_line_total),
        ]);
    }
}
