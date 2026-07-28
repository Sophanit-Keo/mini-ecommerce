<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Confirmed = 'confirmed';
    case Picking = 'picking';
    case Packed = 'packed';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    /**
     * Statuses that do not require a delivery slot.
     *
     * Mirrors `ck_orders_slot_required` (design-review finding R-04): once an order is past
     * checkout it must carry a slot, or it appears on no picking manifest and is silently
     * never fulfilled.
     *
     * @return array<int, self>
     */
    public static function slotOptional(): array
    {
        return [self::PendingPayment, self::Cancelled];
    }
}
