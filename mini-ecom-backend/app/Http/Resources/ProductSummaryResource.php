<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The list-view projection of a product.
 *
 * `effectivePrice` is the field clients should sort, filter and display on: it is present
 * for both pricing shapes, whereas `price` is null for every weight-priced product — a
 * client that reads `price` shows nothing for half the catalogue.
 *
 * @mixin Product
 */
class ProductSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'sku' => $this->sku,
            'name' => $this->name,
            'slug' => $this->slug,
            'brand' => $this->brand,
            'soldBy' => $this->sold_by->value,
            'unitLabel' => $this->unit_label,
            'effectivePrice' => $this->effective_price,
            'price' => $this->price,
            'pricePerKg' => $this->price_per_kg,
            'compareAtPrice' => $this->compare_at_price,
            'averageWeightKg' => $this->average_weight_kg,
            'primaryImageUrl' => $this->whenLoaded('primaryImage', fn () => $this->primaryImage?->url, null),
            'inStock' => $this->isInStock(),
            'categoryId' => $this->whenLoaded('category', fn () => $this->category->public_id),
        ];
    }
}
