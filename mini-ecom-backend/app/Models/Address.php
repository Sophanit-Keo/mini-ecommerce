<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id', 'label', 'recipient_name', 'phone', 'line1', 'line2', 'city', 'region',
    'postal_code', 'country_code', 'latitude', 'longitude', 'delivery_notes', 'is_default',
])]
class Address extends Model
{
    /** @use HasFactory<AddressFactory> */
    use HasFactory, HasPublicId, SoftDeletes;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The immutable copy an order keeps, so a later edit or delete cannot rewrite where a
     * past order was actually sent.
     *
     * @return array<string, string|null>
     */
    public function toSnapshot(): array
    {
        return [
            'recipientName' => $this->recipient_name,
            'phone' => $this->phone,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'region' => $this->region,
            'postalCode' => $this->postal_code,
            'countryCode' => $this->country_code,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'deliveryNotes' => $this->delivery_notes,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_default' => 'boolean',
        ];
    }
}
