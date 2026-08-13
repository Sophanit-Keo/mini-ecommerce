<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReconciliationStatus;
use App\Models\Concerns\HasPublicId;
use App\Observers\OrderObserver;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A grocery order has no exact total at checkout.
 *
 * `*_estimated` is computed from average weights and authorises payment; `*_final` is
 * computed after picking from actual scale readings and captures it. `*_final` stays null
 * until the order reaches `packed` — null means "not yet picked", not zero.
 *
 * `#[ObservedBy(OrderObserver::class)]` records an in-app notification for the customer on
 * every status change, regardless of which code path made the change (customer cancel, an
 * admin action, a future bot) — see OrderObserver.
 */
#[ObservedBy(OrderObserver::class)]
#[Fillable([
    'order_number', 'user_id', 'delivery_address_id', 'delivery_address_snapshot',
    'delivery_slot_id', 'status', 'payment_status', 'payment_method', 'currency',
    'subtotal_estimated', 'delivery_fee', 'discount_total', 'tax_estimated', 'total_estimated',
    'subtotal_final', 'tax_final', 'total_final', 'authorized_amount', 'captured_amount',
    'customer_note', 'idempotency_key', 'reservation_expires_at', 'resources_reserved_at',
    'placed_at', 'confirmed_at', 'delivered_at', 'cancelled_at', 'cart_restored_at', 'cancellation_reason',
    'fulfilled_at', 'reservation_released_at', 'reconciliation_status', 'reconciliation_delta',
    'reconciliation_reference', 'reconciled_at',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasPublicId;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Address, $this> */
    public function deliveryAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'delivery_address_id');
    }

    /** @return BelongsTo<DeliverySlot, $this> */
    public function deliverySlot(): BelongsTo
    {
        return $this->belongsTo(DeliverySlot::class, 'delivery_slot_id');
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    /** @return HasMany<OrderFulfillmentEvent, $this> */
    public function fulfillmentEvents(): HasMany
    {
        return $this->hasMany(OrderFulfillmentEvent::class);
    }

    /** @return HasMany<PaymentAttempt, $this> */
    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    /** @return HasOne<PaymentAttempt, $this> */
    public function latestPaymentAttempt(): HasOne
    {
        return $this->hasOne(PaymentAttempt::class)->latestOfMany();
    }

    /**
     * True once picking is complete and the final figures have been written.
     */
    public function hasFinalTotals(): bool
    {
        return $this->total_final !== null;
    }

    public function hasFulfilledInventory(): bool
    {
        return $this->fulfilled_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'delivery_address_snapshot' => 'array',
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'payment_method' => PaymentMethod::class,
            'subtotal_estimated' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_estimated' => 'decimal:2',
            'total_estimated' => 'decimal:2',
            'subtotal_final' => 'decimal:2',
            'tax_final' => 'decimal:2',
            'total_final' => 'decimal:2',
            'authorized_amount' => 'decimal:2',
            'captured_amount' => 'decimal:2',
            'reconciliation_status' => ReconciliationStatus::class,
            'reconciliation_delta' => 'decimal:2',
            'placed_at' => 'datetime',
            'reservation_expires_at' => 'datetime',
            'resources_reserved_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'reservation_released_at' => 'datetime',
            'reconciled_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'cart_restored_at' => 'datetime',
        ];
    }
}
