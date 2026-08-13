<?php

namespace App\Actions\Orders;

use App\Enums\InventoryAdjustmentReason;
use App\Exceptions\ProblemException;
use App\Models\DeliverySlot;
use App\Models\Inventory;
use App\Models\InventoryAdjustment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * Reserves and releases the fulfilment resources attached to an order.
 *
 * The caller must already be inside a database transaction and hold an exclusive lock on the
 * order row. This keeps status changes and their resource effects atomic: an order is never
 * cancelled while its stock or delivery capacity remains consumed, and a failed checkout never
 * leaves a partial reservation behind.
 *
 * Lock order is intentionally consistent across all callers: delivery slot first, then inventory
 * rows ordered by product ID. The stable order minimizes deadlocks when two checkout requests
 * contain the same products in a different cart-item order.
 */
final class ManageOrderReservation
{
    /**
     * Reserve delivery capacity and live inventory for a newly created order.
     *
     * @throws ProblemException when the slot or any requested quantity is no longer available
     */
    public function reserve(Order $order, ?int $actorId = null): void
    {
        $slot = DeliverySlot::query()
            ->lockForUpdate()
            ->find($order->delivery_slot_id);

        if ($slot === null || ! $slot->is_active || $slot->starts_at->isPast() || $slot->isFull()) {
            throw ProblemException::slotUnavailable($slot?->public_id);
        }

        $items = $this->items($order);
        $inventories = $this->inventoriesFor($items);

        foreach ($items as $item) {
            $inventory = $inventories->get($item->product_id);
            $product = $inventory?->product;

            if ($inventory === null || $product === null) {
                throw ProblemException::insufficientStock(
                    $product?->public_id ?? 'unknown',
                    $item->ordered_quantity,
                    '0.000',
                    $item->product_name,
                );
            }

            if (Money::compare($inventory->quantity_available, $item->ordered_quantity, Money::QUANTITY_SCALE) < 0) {
                throw ProblemException::insufficientStock(
                    $product->public_id,
                    $item->ordered_quantity,
                    $inventory->quantity_available,
                    $product->name,
                );
            }
        }

        $slot->update(['booked_count' => $slot->booked_count + 1]);

        foreach ($items as $item) {
            $inventory = $inventories->get($item->product_id);
            $quantity = $item->ordered_quantity;

            $inventory->update([
                'quantity_reserved' => Money::add($inventory->quantity_reserved, $quantity, Money::QUANTITY_SCALE),
            ]);

            InventoryAdjustment::create([
                'product_id' => $item->product_id,
                'delta' => $quantity,
                'reason' => InventoryAdjustmentReason::OrderReserved,
                'reference_type' => Order::class,
                'reference_id' => $order->id,
                'note' => 'Reserved for checkout '.$order->order_number,
                'created_by' => $actorId,
            ]);
        }

        // The marker is written only after all reservations and ledger entries have succeeded.
        // It lets release paths handle pre-migration orders safely: those rows consumed a slot
        // but never reserved stock, so subtracting stock for them would corrupt inventory.
        $order->update(['resources_reserved_at' => now()]);
    }

    /**
     * Release an active order reservation exactly once.
     *
     * `reservation_released_at` is the durable release marker. `reservation_expires_at` tracks
     * only the payment deadline and is cleared after a verified payment, so it cannot tell us
     * whether stock was released. Every caller locks the order first, preventing concurrent
     * cancellation, rejection, and expiry paths from decrementing shared resources twice.
     *
     * @return bool true when stock and capacity were released; false when previously released
     */
    public function release(Order $order, ?int $actorId = null): bool
    {
        if ($order->reservation_released_at !== null || $order->fulfilled_at !== null) {
            return false;
        }

        $slot = DeliverySlot::query()
            ->lockForUpdate()
            ->find($order->delivery_slot_id);

        // Pre-migration rows booked slot capacity but did not reserve inventory. Release the
        // capacity they actually hold, mark their reservation closed, and never subtract stock.
        // Older fixtures/manual records may not even have incremented the counter; close their
        // stale marker without changing any shared resource.
        if ($order->resources_reserved_at === null) {
            $releasedSlot = $slot !== null && $slot->booked_count > 0;

            if ($releasedSlot) {
                $slot->update(['booked_count' => $slot->booked_count - 1]);
            }

            $order->update([
                'reservation_expires_at' => null,
                'reservation_released_at' => now(),
            ]);

            return $releasedSlot;
        }

        if ($slot === null || $slot->booked_count < 1) {
            throw new LogicException("Order {$order->order_number} has no releasable delivery-slot reservation.");
        }

        $items = $this->items($order);
        $inventories = $this->inventoriesFor($items);

        foreach ($items as $item) {
            $inventory = $inventories->get($item->product_id);

            if ($inventory === null || Money::compare($inventory->quantity_reserved, $item->ordered_quantity, Money::QUANTITY_SCALE) < 0) {
                throw new LogicException("Order {$order->order_number} has no releasable inventory reservation for item {$item->id}.");
            }
        }

        $slot->update(['booked_count' => $slot->booked_count - 1]);

        foreach ($items as $item) {
            $inventory = $inventories->get($item->product_id);
            $quantity = $item->ordered_quantity;

            $inventory->update([
                'quantity_reserved' => Money::sub($inventory->quantity_reserved, $quantity, Money::QUANTITY_SCALE),
            ]);

            InventoryAdjustment::create([
                'product_id' => $item->product_id,
                'delta' => '-'.$quantity,
                'reason' => InventoryAdjustmentReason::OrderReleased,
                'reference_type' => Order::class,
                'reference_id' => $order->id,
                'note' => 'Released from order '.$order->order_number,
                'created_by' => $actorId,
            ]);
        }

        $order->update([
            'reservation_expires_at' => null,
            'reservation_released_at' => now(),
        ]);

        return true;
    }

    /**
     * @return Collection<int, OrderItem>
     */
    private function items(Order $order): Collection
    {
        return $order->items()
            ->whereNotNull('product_id')
            ->orderBy('product_id')
            ->get();
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     * @return Collection<int, Inventory>
     */
    private function inventoriesFor(Collection $items): Collection
    {
        return Inventory::query()
            ->with('product:id,public_id,name')
            ->whereIn('product_id', $items->pluck('product_id'))
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('product_id');
    }
}
