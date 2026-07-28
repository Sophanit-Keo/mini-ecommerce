<?php

namespace App\Models;

use Database\Factories\InventoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stock is continuous — kilograms, not counts — so every quantity is DECIMAL(10,3).
 *
 * `quantity_available` is a generated column rather than a maintained one, which makes it
 * impossible for the available figure to drift from on-hand minus reserved.
 */
#[Table('inventory')]
#[Fillable(['product_id', 'quantity_on_hand', 'quantity_reserved', 'low_stock_threshold', 'restock_expected_at'])]
#[WithoutIncrementing]
class Inventory extends Model
{
    /** @use HasFactory<InventoryFactory> */
    use HasFactory;

    public const CREATED_AT = null;

    protected $primaryKey = 'product_id';

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isLow(): bool
    {
        return bccomp($this->quantity_available, $this->low_stock_threshold, 3) <= 0;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'decimal:3',
            'quantity_reserved' => 'decimal:3',
            'quantity_available' => 'decimal:3',
            'low_stock_threshold' => 'decimal:3',
            'restock_expected_at' => 'datetime',
        ];
    }
}
