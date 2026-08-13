<?php

namespace App\Http\Resources;

use App\Models\Inventory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Inventory */
class InventoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'productId' => $this->whenLoaded('product', fn () => $this->product?->public_id),
            'quantityOnHand' => $this->quantity_on_hand,
            'quantityReserved' => $this->quantity_reserved,
            'quantityAvailable' => $this->quantity_available,
            'lowStockThreshold' => $this->low_stock_threshold,
            'isLowStock' => $this->isLow(),
            'restockExpectedAt' => $this->restock_expected_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
