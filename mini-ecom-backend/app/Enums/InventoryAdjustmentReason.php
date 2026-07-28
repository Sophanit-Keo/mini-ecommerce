<?php

namespace App\Enums;

/**
 * Why stock moved. Every change to `inventory` writes one of these to the ledger, so a
 * discrepancy can always be traced to the movement that caused it.
 */
enum InventoryAdjustmentReason: string
{
    case Restock = 'restock';
    case Shrinkage = 'shrinkage';
    case Correction = 'correction';
    case OrderReserved = 'order_reserved';
    case OrderReleased = 'order_released';
    case OrderFulfilled = 'order_fulfilled';
    case Return = 'return';
}
