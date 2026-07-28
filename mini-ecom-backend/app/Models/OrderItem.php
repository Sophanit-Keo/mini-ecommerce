<?php

namespace App\Models;

use App\Enums\OrderItemStatus;
use App\Enums\SoldBy;
use App\Enums\SubstitutionPreference;
use App\Models\Concerns\HasPublicId;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lines snapshot the product — name, SKU, brand, pricing shape and unit price are copied at
 * checkout, so a later catalogue edit cannot rewrite history.
 *
 * Per design-review finding R-03, `final_line_total` is the single authoritative figure for
 * what this line is charged, whether it was picked as ordered or substituted. It is the only
 * column `orders.subtotal_final` ever sums.
 */
#[Fillable([
    'order_id', 'product_id', 'product_name', 'product_sku', 'brand', 'sold_by', 'unit_label',
    'unit_price', 'ordered_quantity', 'estimated_weight_kg', 'estimated_line_total',
    'picked_quantity', 'picked_weight_kg', 'final_line_total', 'substitution_preference',
    'status', 'note',
])]
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory, HasPublicId;

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<OrderItemSubstitution, $this> */
    public function substitutions(): HasMany
    {
        return $this->hasMany(OrderItemSubstitution::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sold_by' => SoldBy::class,
            'unit_price' => 'decimal:2',
            'ordered_quantity' => 'decimal:3',
            'estimated_weight_kg' => 'decimal:3',
            'estimated_line_total' => 'decimal:2',
            'picked_quantity' => 'decimal:3',
            'picked_weight_kg' => 'decimal:3',
            'final_line_total' => 'decimal:2',
            'substitution_preference' => SubstitutionPreference::class,
            'status' => OrderItemStatus::class,
        ];
    }
}
