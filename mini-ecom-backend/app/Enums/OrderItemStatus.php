<?php

namespace App\Enums;

/**
 * Where a line stands in picking.
 *
 * `ck_order_items_picked_shape` ties this to the money: `Picked` and `Substituted` lines
 * must carry a `final_line_total`, `Pending` and `Unavailable` lines must not. A null final
 * total means "not yet picked" — it is not zero.
 */
enum OrderItemStatus: string
{
    case Pending = 'pending';
    case Picked = 'picked';
    case Substituted = 'substituted';
    case Unavailable = 'unavailable';

    public function isResolved(): bool
    {
        return $this !== self::Pending;
    }

    public function hasFinalTotal(): bool
    {
        return $this === self::Picked || $this === self::Substituted;
    }
}
