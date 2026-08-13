<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'orderNumber' => $this->order_number,
            'status' => $this->status->value,
            'paymentStatus' => $this->payment_status->value,
            'paymentMethod' => $this->payment_method->value,
            'payment' => [
                'method' => $this->payment_method->value,
                'status' => $this->payment_status->value,
                'authorizedAmount' => $this->authorized_amount,
                'capturedAmount' => $this->captured_amount,
                'attempt' => $this->relationLoaded('latestPaymentAttempt') && $this->latestPaymentAttempt !== null
                    ? PaymentAttemptResource::make($this->latestPaymentAttempt)
                    : null,
            ],
            'reconciliation' => [
                'status' => $this->reconciliation_status?->value,
                'delta' => $this->reconciliation_delta,
                'reference' => $this->reconciliation_reference,
                'reconciledAt' => $this->reconciled_at?->toIso8601String(),
            ],
            'currency' => $this->currency,
            'deliveryAddressSnapshot' => $this->delivery_address_snapshot,
            'deliverySlotId' => $this->relationLoaded('deliverySlot') ? $this->deliverySlot?->public_id : null,
            'subtotalEstimated' => $this->subtotal_estimated,
            'deliveryFee' => $this->delivery_fee,
            'discountTotal' => $this->discount_total,
            'taxEstimated' => $this->tax_estimated,
            'totalEstimated' => $this->total_estimated,
            'subtotalFinal' => $this->subtotal_final,
            'taxFinal' => $this->tax_final,
            'totalFinal' => $this->total_final,
            'customerNote' => $this->customer_note,
            'placedAt' => $this->placed_at?->toIso8601String(),
            'confirmedAt' => $this->confirmed_at?->toIso8601String(),
            'deliveredAt' => $this->delivered_at?->toIso8601String(),
            'cancelledAt' => $this->cancelled_at?->toIso8601String(),
            'fulfilledAt' => $this->fulfilled_at?->toIso8601String(),
            'cancellationReason' => $this->cancellation_reason,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'statusHistory' => OrderStatusHistoryResource::collection($this->whenLoaded('statusHistory')),
        ];
    }
}
