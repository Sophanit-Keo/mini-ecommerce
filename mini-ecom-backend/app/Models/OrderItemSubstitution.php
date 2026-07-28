<?php

namespace App\Models;

use App\Enums\SubstitutionDecidedBy;
use Database\Factories\OrderItemSubstitutionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pure audit record of what was swapped and why.
 *
 * Per design-review finding R-03 this table is deliberately not a pricing input:
 * `price_delta` is carried for display, and adding it to a total on top of the line's own
 * `final_line_total` would double-count every substitution.
 */
#[Fillable([
    'order_item_id', 'substitute_product_id', 'substitute_name', 'substitute_sku',
    'substitute_unit_price', 'substitute_quantity', 'substitute_weight_kg',
    'substitute_line_total', 'price_delta', 'reason', 'decided_by', 'customer_approved',
    'created_by',
])]
class OrderItemSubstitution extends Model
{
    /** @use HasFactory<OrderItemSubstitutionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function substituteProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'substitute_product_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'substitute_unit_price' => 'decimal:2',
            'substitute_quantity' => 'decimal:3',
            'substitute_weight_kg' => 'decimal:3',
            'substitute_line_total' => 'decimal:2',
            'price_delta' => 'decimal:2',
            'decided_by' => SubstitutionDecidedBy::class,
            'customer_approved' => 'boolean',
        ];
    }
}
