<?php

namespace Database\Factories;

use App\Enums\SoldBy;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * The default state produces a *unit* product. `ck_products_pricing_shape` rejects any row
 * that mixes the two pricing shapes, so `price` and `price_per_kg` can never both be set —
 * use the `weight()` state rather than overriding columns piecemeal.
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'category_id' => Category::factory(),
            'sku' => strtoupper(fake()->unique()->bothify('???-#####')),
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'brand' => fake()->company(),
            'description' => fake()->sentence(),
            'sold_by' => SoldBy::Unit,
            'unit_label' => 'ea',
            'price' => fake()->randomFloat(2, 0.99, 24.99),
            'price_per_kg' => null,
            'average_weight_kg' => null,
            'compare_at_price' => null,
            'weight_tolerance_pct' => 10.00,
            'min_order_quantity' => 1.000,
            'max_order_quantity' => 20.000,
            'is_active' => true,
        ];
    }

    /**
     * A product priced per kilogram, whose real price is not known until it is weighed.
     */
    public function weight(): static
    {
        return $this->state(fn (array $attributes) => [
            'sold_by' => SoldBy::Weight,
            'unit_label' => 'kg',
            'price' => null,
            'price_per_kg' => fake()->randomFloat(2, 1.49, 39.99),
            'average_weight_kg' => fake()->randomFloat(3, 0.150, 1.200),
            'min_order_quantity' => 0.250,
            'max_order_quantity' => 10.000,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
