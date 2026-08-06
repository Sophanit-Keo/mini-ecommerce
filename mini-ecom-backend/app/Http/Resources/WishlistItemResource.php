<?php

namespace App\Http\Resources;

use App\Models\WishlistItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WishlistItem
 */
class WishlistItemResource extends JsonResource
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
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
