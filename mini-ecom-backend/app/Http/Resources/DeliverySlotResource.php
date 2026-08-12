<?php

namespace App\Http\Resources;

use App\Models\DeliverySlot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DeliverySlot
 */
class DeliverySlotResource extends JsonResource
{
    /**
     * @return array<string, int|string|null>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'startsAt' => $this->starts_at?->toIso8601String(),
            'endsAt' => $this->ends_at?->toIso8601String(),
            'timezone' => config('app.timezone'),
            'fee' => $this->fee,
            'capacity' => $this->capacity,
            'bookedCount' => $this->booked_count,
            'remainingCapacity' => max(0, $this->capacity - $this->booked_count),
        ];
    }
}
