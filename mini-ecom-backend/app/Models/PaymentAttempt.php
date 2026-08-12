<?php

namespace App\Models;

use App\Enums\PaymentAttemptStatus;
use App\Models\Concerns\HasPublicId;
use Database\Factories\PaymentAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id', 'provider', 'status', 'provider_reference', 'khqr_payload',
    'amount', 'currency', 'provider_transaction_hash', 'provider_response',
    'verification_count', 'expires_at', 'verified_at', 'last_checked_at',
])]
class PaymentAttempt extends Model
{
    /** @use HasFactory<PaymentAttemptFactory> */
    use HasFactory, HasPublicId;

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentAttemptStatus::class,
            'amount' => 'decimal:2',
            'provider_response' => 'array',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }
}
