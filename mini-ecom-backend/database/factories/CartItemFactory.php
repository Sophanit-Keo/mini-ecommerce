<?php

namespace Database\Factories;

use App\Enums\SubstitutionPreference;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartItem>
 */
class CartItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),
            'product_id' => Product::factory(),
            'quantity' => fake()->randomFloat(3, 1, 5),
            'unit_price_snapshot' => fake()->randomFloat(2, 0.99, 24.99),
            'substitution_preference' => SubstitutionPreference::Similar,
            'note' => null,
        ];
    }

    /**
     * Take the quantity and snapshot price from a real product, the way add-to-cart does.
     */
    public function forProduct(Product $product, float $quantity): static
    {
        return $this->state(fn (array $attributes) => [
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price_snapshot' => $product->chargeableUnitPrice(),
        ]);
    }
}
