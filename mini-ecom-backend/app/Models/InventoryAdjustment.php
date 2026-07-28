<?php

namespace App\Models;

use App\Enums\InventoryAdjustmentReason;
use Database\Factories\InventoryAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The audit ledger. Every movement of stock writes a row here, so a discrepancy can always
 * be traced to the operation that caused it.
 */
#[Fillable(['product_id', 'delta', 'reason', 'reference_type', 'reference_id', 'note', 'created_by'])]
class InventoryAdjustment extends Model
{
    /** @use HasFactory<InventoryAdjustmentFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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
            'delta' => 'decimal:3',
            'reason' => InventoryAdjustmentReason::class,
        ];
    }
}
