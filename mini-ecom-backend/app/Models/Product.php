<?php

namespace App\Models;

use App\Enums\SoldBy;
use App\Models\Concerns\HasPublicId;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * `effective_price`, `sku_active` and `slug_active` are generated columns — MySQL rejects
 * writes to them, so they are deliberately absent from the fillable list.
 */
#[Fillable([
    'category_id', 'sku', 'name', 'slug', 'brand', 'description', 'sold_by', 'unit_label',
    'price', 'price_per_kg', 'compare_at_price', 'average_weight_kg', 'weight_tolerance_pct',
    'min_order_quantity', 'max_order_quantity', 'is_active',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasPublicId, SoftDeletes;

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    /** @return HasOne<ProductImage, $this> */
    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true);
    }

    /** @return HasOne<Inventory, $this> */
    public function inventory(): HasOne
    {
        return $this->hasOne(Inventory::class);
    }

    /**
     * Whether any stock is available to sell right now.
     *
     * Reads the generated `quantity_available` column — on hand minus reserved — so an item
     * whose entire stock is spoken for by unpaid checkouts reads as out of stock.
     */
    public function isInStock(): bool
    {
        $available = $this->inventory?->quantity_available;

        return $available !== null && bccomp($available, '0', 3) > 0;
    }

    /**
     * The unit of `quantity` for this product: a count for unit products, kilograms for
     * weight products.
     */
    public function quantityUnit(): string
    {
        return $this->sold_by->isWeighed() ? 'kg' : $this->unit_label;
    }

    /**
     * The price one unit of `quantity` is charged at — per kilogram for weight products,
     * per item for unit products. This is what a line total is computed from; `effective_price`
     * exists only so the two shapes can be compared and sorted against each other.
     */
    public function chargeableUnitPrice(): string
    {
        return $this->sold_by->isWeighed() ? $this->price_per_kg : $this->price;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sold_by' => SoldBy::class,
            'price' => 'decimal:2',
            'price_per_kg' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'effective_price' => 'decimal:2',
            'average_weight_kg' => 'decimal:3',
            'weight_tolerance_pct' => 'decimal:2',
            'min_order_quantity' => 'decimal:3',
            'max_order_quantity' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }
}
