<?php

namespace App\Http\Resources;

use App\Models\OrderItemSubstitution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrderItemSubstitution */
class OrderItemSubstitutionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'productId' => $this->whenLoaded('substituteProduct', fn () => $this->substituteProduct?->public_id),
            'productName' => $this->substitute_name,
            'productSku' => $this->substitute_sku,
            'unitPrice' => $this->substitute_unit_price,
            'quantity' => $this->substitute_quantity,
            'weightKg' => $this->substitute_weight_kg,
            'lineTotal' => $this->substitute_line_total,
            'priceDelta' => $this->price_delta,
            'reason' => $this->reason,
            'decidedBy' => $this->decided_by->value,
            'customerApproved' => $this->customer_approved,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
