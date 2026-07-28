<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;

/**
 * The detail view: everything in the summary, plus description, ordering bounds, the full
 * image set, live availability and the owning category.
 *
 * @mixin Product
 */
class ProductResource extends ProductSummaryResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'description' => $this->description,
            'weightTolerancePct' => $this->weight_tolerance_pct,
            'minOrderQuantity' => $this->min_order_quantity,
            'maxOrderQuantity' => $this->max_order_quantity,
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
            'availableQuantity' => $this->whenLoaded(
                'inventory',
                fn () => $this->inventory?->quantity_available ?? '0.000',
            ),
            'category' => CategoryResource::make($this->whenLoaded('category')),
        ];
    }
}
