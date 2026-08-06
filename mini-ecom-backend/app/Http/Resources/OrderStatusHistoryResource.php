<?php

namespace App\Http\Resources;

use App\Models\OrderStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrderStatusHistory
 */
class OrderStatusHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'fromStatus' => $this->from_status?->value,
            'toStatus' => $this->to_status->value,
            'note' => $this->note,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
