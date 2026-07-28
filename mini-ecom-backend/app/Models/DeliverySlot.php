<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\DeliverySlotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `booked_count` is a counter rather than a COUNT(*) over orders, for concurrency rather
 * than speed: it can be incremented and bounds-checked in one atomic statement, and zero
 * affected rows means "slot full".
 */
#[Fillable(['slot_date', 'starts_at', 'ends_at', 'capacity', 'booked_count', 'fee', 'is_active'])]
class DeliverySlot extends Model
{
    /** @use HasFactory<DeliverySlotFactory> */
    use HasFactory, HasPublicId;

    public function isFull(): bool
    {
        return $this->booked_count >= $this->capacity;
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slot_date' => 'date',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'capacity' => 'integer',
            'booked_count' => 'integer',
            'fee' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
