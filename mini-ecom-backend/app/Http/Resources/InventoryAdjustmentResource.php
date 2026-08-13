<?php

namespace App\Http\Resources;

use App\Models\InventoryAdjustment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InventoryAdjustment */
class InventoryAdjustmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->whenLoaded('product', fn () => $this->product?->public_id),
            'delta' => $this->delta,
            'reason' => $this->reason->value,
            'referenceType' => $this->reference_type,
            'referenceId' => $this->reference_id,
            'note' => $this->note,
            'createdBy' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->public_id),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
