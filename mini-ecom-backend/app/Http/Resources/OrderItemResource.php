<?php

namespace App\Http\Resources;

use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrderItem
 */
class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'productId' => $this->whenLoaded('product', fn () => $this->product?->public_id),
            'productName' => $this->product_name,
            'productSku' => $this->product_sku,
            'brand' => $this->brand,
            'soldBy' => $this->sold_by->value,
            'unitLabel' => $this->unit_label,
            'unitPrice' => $this->unit_price,
            'orderedQuantity' => $this->ordered_quantity,
            'estimatedWeightKg' => $this->estimated_weight_kg,
            'estimatedLineTotal' => $this->estimated_line_total,
            'pickedQuantity' => $this->picked_quantity,
            'pickedWeightKg' => $this->picked_weight_kg,
            'finalLineTotal' => $this->final_line_total,
            'substitutionPreference' => $this->substitution_preference->value,
            'status' => $this->status->value,
            // `finalLineTotal` remains the only money input to order finalization; the nested
            // substitution records are an immutable explanation, not an additional charge.
            'substitutions' => OrderItemSubstitutionResource::collection($this->whenLoaded('substitutions')),
            'note' => $this->note,
        ];
    }
}
