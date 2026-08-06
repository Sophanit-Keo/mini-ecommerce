<?php

namespace App\Http\Resources;

use App\Models\CartItem;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CartItem
 */
class CartItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'productId' => $this->whenLoaded('product', fn () => $this->product->public_id),
            'product' => ProductSummaryResource::make($this->whenLoaded('product')),
            'quantity' => $this->quantity,
            'unitPriceSnapshot' => $this->unit_price_snapshot,
            'lineTotal' => Money::lineTotal($this->unit_price_snapshot, $this->quantity),
            'substitutionPreference' => $this->substitution_preference->value,
            'note' => $this->note,
        ];
    }
}
