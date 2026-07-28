<?php

namespace App\Http\Resources;

use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Address
 */
class AddressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'label' => $this->label,
            'recipientName' => $this->recipient_name,
            'phone' => $this->phone,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'region' => $this->region,
            'postalCode' => $this->postal_code,
            'countryCode' => $this->country_code,
            // Coordinates stay strings for the same reason money does: DECIMAL(10,7) does not
            // survive a round trip through an IEEE-754 double intact.
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'deliveryNotes' => $this->delivery_notes,
            'isDefault' => $this->is_default,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
