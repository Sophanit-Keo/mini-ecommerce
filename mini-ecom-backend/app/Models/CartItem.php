<?php

namespace App\Models;

use App\Enums\SubstitutionPreference;
use App\Models\Concerns\HasPublicId;
use Database\Factories\CartItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `unit_price_snapshot` records the price the customer was shown when they added the item.
 * It is not what they are charged — checkout re-prices from the live catalogue — but it is
 * what lets the API report "this went up from $3.99 to $4.49" instead of silently changing
 * the total.
 */
#[Fillable(['cart_id', 'product_id', 'quantity', 'unit_price_snapshot', 'substitution_preference', 'note'])]
class CartItem extends Model
{
    /** @use HasFactory<CartItemFactory> */
    use HasFactory, HasPublicId;

    /** @return BelongsTo<Cart, $this> */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price_snapshot' => 'decimal:2',
            'substitution_preference' => SubstitutionPreference::class,
        ];
    }
}
