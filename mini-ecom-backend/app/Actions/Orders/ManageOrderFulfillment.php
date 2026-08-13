<?php

namespace App\Actions\Orders;

use App\Enums\FulfillmentEventType;
use App\Enums\InventoryAdjustmentReason;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\SoldBy;
use App\Enums\SubstitutionDecidedBy;
use App\Enums\SubstitutionPreference;
use App\Exceptions\ProblemException;
use App\Models\Inventory;
use App\Models\InventoryAdjustment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Explicit command service for grocery picking. The caller never sets an arbitrary status or
 * total: each line is resolved under an order lock, and finalization performs the only
 * inventory-consumption and packed transition in one transaction.
 */
final class ManageOrderFulfillment
{
    public function pick(Order $order, string $itemId, User $admin, string $quantity, ?string $weightKg, ?string $note = null): Order
    {
        return DB::transaction(function () use ($order, $itemId, $admin, $quantity, $weightKg, $note): Order {
            $lockedOrder = $this->lockedPickingOrder($order);
            $item = $this->lockedPendingItem($lockedOrder, $itemId);
            $picked = $this->lineSelection($item, $quantity, $weightKg);

            $item->update([
                'picked_quantity' => $picked['quantity'],
                'picked_weight_kg' => $picked['weight'],
                'final_line_total' => Money::lineTotal($item->unit_price, $picked['billableQuantity']),
                'status' => OrderItemStatus::Picked,
                'note' => $note ?? $item->note,
            ]);

            $this->event($lockedOrder, $item, FulfillmentEventType::Picked, $admin, [
                'pickedQuantity' => $picked['quantity'],
                'pickedWeightKg' => $picked['weight'],
                'finalLineTotal' => $item->fresh()->final_line_total,
            ]);

            return $lockedOrder;
        });
    }

    public function substitute(
        Order $order,
        string $itemId,
        User $admin,
        string $productId,
        string $quantity,
        ?string $weightKg,
        ?string $reason,
        bool $customerApproved,
    ): Order {
        return DB::transaction(function () use ($order, $itemId, $admin, $productId, $quantity, $weightKg, $reason, $customerApproved): Order {
            $lockedOrder = $this->lockedPickingOrder($order);
            $item = $this->lockedPendingItem($lockedOrder, $itemId);

            if ($item->substitution_preference === SubstitutionPreference::None) {
                throw ProblemException::substitutionRefused($item->public_id);
            }

            if ($item->substitution_preference === SubstitutionPreference::ContactMe && ! $customerApproved) {
                throw ProblemException::substitutionApprovalRequired();
            }

            $product = Product::query()
                ->wherePublicId($productId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($product === null || $product->id === $item->product_id) {
                throw ProblemException::invalidSubstituteProduct();
            }

            if ($product->sold_by !== $item->sold_by) {
                throw ProblemException::invalidSubstituteProduct();
            }

            $selection = $this->selectionForSoldBy($product->sold_by, $quantity, $weightKg, $item->ordered_quantity);
            $lineTotal = Money::lineTotal($product->chargeableUnitPrice(), $selection['billableQuantity']);

            $item->substitutions()->create([
                'substitute_product_id' => $product->id,
                'substitute_name' => $product->name,
                'substitute_sku' => $product->sku,
                'substitute_unit_price' => $product->chargeableUnitPrice(),
                'substitute_quantity' => $selection['quantity'],
                'substitute_weight_kg' => $selection['weight'],
                'substitute_line_total' => $lineTotal,
                'price_delta' => Money::sub($lineTotal, $item->estimated_line_total),
                'reason' => $reason,
                'decided_by' => $customerApproved ? SubstitutionDecidedBy::Customer : SubstitutionDecidedBy::Picker,
                'customer_approved' => $customerApproved ?: null,
                'created_by' => $admin->id,
            ]);

            $item->update([
                'picked_quantity' => $selection['quantity'],
                'picked_weight_kg' => $selection['weight'],
                'final_line_total' => $lineTotal,
                'status' => OrderItemStatus::Substituted,
            ]);

            $this->event($lockedOrder, $item, FulfillmentEventType::Substituted, $admin, [
                'substituteProductId' => $product->public_id,
                'pickedQuantity' => $selection['quantity'],
                'pickedWeightKg' => $selection['weight'],
                'finalLineTotal' => $lineTotal,
                'customerApproved' => $customerApproved,
            ]);

            return $lockedOrder;
        });
    }

    public function unavailable(Order $order, string $itemId, User $admin, string $reason): Order
    {
        return DB::transaction(function () use ($order, $itemId, $admin, $reason): Order {
            $lockedOrder = $this->lockedPickingOrder($order);
            $item = $this->lockedPendingItem($lockedOrder, $itemId);

            $item->update([
                'picked_quantity' => null,
                'picked_weight_kg' => null,
                'final_line_total' => null,
                'status' => OrderItemStatus::Unavailable,
                'note' => $reason,
            ]);

            $this->event($lockedOrder, $item, FulfillmentEventType::Unavailable, $admin, ['reason' => $reason]);

            return $lockedOrder;
        });
    }

    public function finalize(Order $order, User $admin): Order
    {
        return DB::transaction(function () use ($order, $admin): Order {
            $lockedOrder = $this->lockedPickingOrder($order);
            $items = $lockedOrder->items()->with('substitutions')->lockForUpdate()->orderBy('id')->get();

            if ($items->contains(fn (OrderItem $item): bool => ! $item->status->isResolved())) {
                throw ProblemException::itemsUnresolved($items->where('status', OrderItemStatus::Pending)->count());
            }

            $this->consumeReservedInventory($lockedOrder, $items, $admin);

            $subtotal = Money::sum($items->map(fn (OrderItem $item): string => $item->final_line_total ?? '0.00'));
            // Checkout currently applies no tax. The finalization formula deliberately keeps the
            // tax field explicit so a future tax-rate snapshot can be applied without changing
            // the line-total or reconciliation invariants.
            $tax = '0.00';
            $total = Money::sub(Money::sum([$subtotal, $lockedOrder->delivery_fee, $tax]), $lockedOrder->discount_total);
            $reconciliation = $this->reconciliationFor($lockedOrder, $total);

            $attributes = [
                'status' => OrderStatus::Packed,
                'subtotal_final' => $subtotal,
                'tax_final' => $tax,
                'total_final' => $total,
                'reconciliation_status' => $reconciliation['status'],
                'reconciliation_delta' => $reconciliation['delta'],
                'reconciliation_reference' => $reconciliation['reference'],
                'reconciled_at' => $reconciliation['at'],
            ];

            if ($reconciliation['capture']) {
                $attributes['captured_amount'] = $total;
                $attributes['payment_status'] = PaymentStatus::Captured;
            }

            $lockedOrder->update($attributes);
            $this->event($lockedOrder, null, FulfillmentEventType::Finalized, $admin, [
                'subtotalFinal' => $subtotal,
                'taxFinal' => $tax,
                'totalFinal' => $total,
                'reconciliationStatus' => $reconciliation['status']->value,
                'reconciliationDelta' => $reconciliation['delta'],
            ]);
            $this->history($lockedOrder, OrderStatus::Picking, OrderStatus::Packed, $admin, 'All order lines finalized.');

            return $lockedOrder;
        });
    }

    public function reconcile(Order $order, User $admin, string $reference): Order
    {
        return DB::transaction(function () use ($order, $admin, $reference): Order {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($lockedOrder->status !== OrderStatus::Packed) {
                throw ProblemException::invalidStatusTransition($lockedOrder->status->value, OrderStatus::Packed->value);
            }

            if (! in_array($lockedOrder->reconciliation_status, [ReconciliationStatus::AmountDue, ReconciliationStatus::RefundDue], true)) {
                throw ProblemException::reconciliationNotRequired();
            }

            $lockedOrder->update([
                'reconciliation_status' => ReconciliationStatus::Settled,
                'reconciliation_reference' => $reference,
                'reconciled_at' => now(),
                'captured_amount' => $lockedOrder->total_final,
                'payment_status' => PaymentStatus::Captured,
            ]);

            $this->event($lockedOrder, null, FulfillmentEventType::Reconciled, $admin, [
                'reference' => $reference,
                'delta' => $lockedOrder->reconciliation_delta,
            ]);

            return $lockedOrder;
        });
    }

    /** @return array{quantity: string, weight: ?string, billableQuantity: string} */
    private function lineSelection(OrderItem $item, string $quantity, ?string $weightKg): array
    {
        return $this->selectionForSoldBy($item->sold_by, $quantity, $weightKg, $item->ordered_quantity);
    }

    /** @return array{quantity: string, weight: ?string, billableQuantity: string} */
    private function selectionForSoldBy(SoldBy $soldBy, string $quantity, ?string $weightKg, string $maximumQuantity): array
    {
        if (Money::compare($quantity, '0', Money::QUANTITY_SCALE) <= 0
            || Money::compare($quantity, $maximumQuantity, Money::QUANTITY_SCALE) > 0) {
            throw ProblemException::fulfillmentQuantityOutOfRange();
        }

        if ($soldBy === SoldBy::Unit) {
            if ($weightKg !== null) {
                throw ProblemException::invalidFulfillmentWeight();
            }

            return ['quantity' => $quantity, 'weight' => null, 'billableQuantity' => $quantity];
        }

        if ($weightKg === null || Money::compare($weightKg, '0', Money::QUANTITY_SCALE) <= 0) {
            throw ProblemException::invalidFulfillmentWeight();
        }

        return ['quantity' => $quantity, 'weight' => $weightKg, 'billableQuantity' => $weightKg];
    }

    private function lockedPickingOrder(Order $order): Order
    {
        $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

        if ($locked->status !== OrderStatus::Picking) {
            throw ProblemException::invalidStatusTransition($locked->status->value, OrderStatus::Picking->value);
        }

        return $locked;
    }

    private function lockedPendingItem(Order $order, string $itemId): OrderItem
    {
        $item = $order->items()->wherePublicId($itemId)->lockForUpdate()->first();

        if ($item === null) {
            throw ProblemException::notFound('No such order item.');
        }

        if ($item->status !== OrderItemStatus::Pending) {
            throw ProblemException::orderItemAlreadyResolved();
        }

        return $item;
    }

    /**
     * Consume every original reservation exactly once, redirecting it to the actual picked or
     * substituted product. A substitute is not reserved at checkout, so it may consume only
     * stock currently available after every other reservation is respected.
     *
     * @param  Collection<int, OrderItem>  $items
     */
    private function consumeReservedInventory(Order $order, Collection $items, User $admin): void
    {
        if ($order->fulfilled_at !== null) {
            throw new LogicException("Order {$order->order_number} inventory is already fulfilled.");
        }

        if ($order->resources_reserved_at === null || $order->reservation_released_at !== null) {
            throw new LogicException("Order {$order->order_number} has no active inventory reservation to fulfil.");
        }

        /** @var array<int, string> $reserved */
        $reserved = [];
        /** @var array<int, string> $consumed */
        $consumed = [];

        foreach ($items as $item) {
            if ($item->product_id !== null) {
                $reserved[$item->product_id] = Money::add($reserved[$item->product_id] ?? '0.000', $item->ordered_quantity, Money::QUANTITY_SCALE);
            }

            $actual = $this->actualProductAndQuantity($item);
            if ($actual !== null) {
                [$productId, $quantity] = $actual;
                $consumed[$productId] = Money::add($consumed[$productId] ?? '0.000', $quantity, Money::QUANTITY_SCALE);
            }
        }

        $productIds = array_values(array_unique([...array_keys($reserved), ...array_keys($consumed)]));
        $inventories = Inventory::query()
            ->whereIn('product_id', $productIds)
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('product_id');

        if ($inventories->count() !== count($productIds)) {
            throw new LogicException("Order {$order->order_number} references inventory that no longer exists.");
        }

        foreach ($productIds as $productId) {
            $inventory = $inventories->get($productId);
            $reservation = $reserved[$productId] ?? '0.000';
            $actual = $consumed[$productId] ?? '0.000';

            if (Money::compare($inventory->quantity_reserved, $reservation, Money::QUANTITY_SCALE) < 0) {
                throw new LogicException("Order {$order->order_number} has no fulfilable reservation for product {$productId}.");
            }

            // Consumption above this order's own reservation (for a heavier weighted pick or a
            // substitute) must come from unreserved stock, never another customer's reservation.
            $extra = Money::compare($actual, $reservation, Money::QUANTITY_SCALE) > 0
                ? Money::sub($actual, $reservation, Money::QUANTITY_SCALE)
                : '0.000';

            if (Money::compare($inventory->quantity_available, $extra, Money::QUANTITY_SCALE) < 0
                || Money::compare($inventory->quantity_on_hand, $actual, Money::QUANTITY_SCALE) < 0) {
                throw ProblemException::insufficientStock(
                    (string) $productId,
                    $actual,
                    $inventory->quantity_available,
                    'Fulfilment item',
                );
            }

            $inventory->update([
                'quantity_on_hand' => Money::sub($inventory->quantity_on_hand, $actual, Money::QUANTITY_SCALE),
                'quantity_reserved' => Money::sub($inventory->quantity_reserved, $reservation, Money::QUANTITY_SCALE),
            ]);

            if (! Money::isZero($actual, Money::QUANTITY_SCALE)) {
                InventoryAdjustment::create([
                    'product_id' => $productId,
                    'delta' => '-'.$actual,
                    'reason' => InventoryAdjustmentReason::OrderFulfilled,
                    'reference_type' => Order::class,
                    'reference_id' => $order->id,
                    'note' => 'Fulfilled for order '.$order->order_number,
                    'created_by' => $admin->id,
                ]);
            }

            $released = Money::compare($reservation, $actual, Money::QUANTITY_SCALE) > 0
                ? Money::sub($reservation, $actual, Money::QUANTITY_SCALE)
                : '0.000';

            if (! Money::isZero($released, Money::QUANTITY_SCALE)) {
                InventoryAdjustment::create([
                    'product_id' => $productId,
                    'delta' => '-'.$released,
                    'reason' => InventoryAdjustmentReason::OrderReleased,
                    'reference_type' => Order::class,
                    'reference_id' => $order->id,
                    'note' => 'Unused reservation released at fulfilment for order '.$order->order_number,
                    'created_by' => $admin->id,
                ]);
            }
        }

        $order->update(['fulfilled_at' => now()]);
    }

    /** @return array{0: int, 1: string}|null */
    private function actualProductAndQuantity(OrderItem $item): ?array
    {
        if ($item->status === OrderItemStatus::Unavailable) {
            return null;
        }

        if ($item->status === OrderItemStatus::Picked) {
            if ($item->product_id === null) {
                throw new LogicException("Picked order item {$item->id} has no product.");
            }

            return [$item->product_id, $item->sold_by === SoldBy::Weight ? $item->picked_weight_kg : $item->picked_quantity];
        }

        $substitution = $item->substitutions->first();
        if ($substitution === null || $substitution->substitute_product_id === null) {
            throw new LogicException("Substituted order item {$item->id} has no live substitute product.");
        }

        return [
            $substitution->substitute_product_id,
            $item->sold_by === SoldBy::Weight ? $substitution->substitute_weight_kg : $substitution->substitute_quantity,
        ];
    }

    /** @return array{status: ReconciliationStatus, delta: ?string, reference: ?string, at: CarbonInterface|null, capture: bool} */
    private function reconciliationFor(Order $order, string $total): array
    {
        if ($order->payment_method === PaymentMethod::CashOnDelivery) {
            return [
                'status' => ReconciliationStatus::NotRequired,
                'delta' => null,
                'reference' => 'cash_on_delivery',
                'at' => now(),
                'capture' => false,
            ];
        }

        $paid = $order->captured_amount ?? $order->authorized_amount;
        if ($paid === null) {
            throw ProblemException::paymentNotAuthorized($order->payment_status->value);
        }

        $delta = Money::sub($total, $paid);
        if (Money::isZero($delta)) {
            return [
                'status' => ReconciliationStatus::Settled,
                'delta' => null,
                'reference' => 'exact_authorization',
                'at' => now(),
                'capture' => true,
            ];
        }

        return [
            'status' => Money::compare($delta, '0') > 0 ? ReconciliationStatus::AmountDue : ReconciliationStatus::RefundDue,
            'delta' => $delta,
            'reference' => null,
            'at' => null,
            'capture' => false,
        ];
    }

    /** @param array<string, mixed> $data */
    private function event(Order $order, ?OrderItem $item, FulfillmentEventType $type, User $admin, array $data): void
    {
        $order->fulfillmentEvents()->create([
            'order_item_id' => $item?->id,
            'event_type' => $type,
            'data' => $data,
            'created_by' => $admin->id,
        ]);
    }

    private function history(Order $order, OrderStatus $from, OrderStatus $to, User $admin, string $note): void
    {
        $order->statusHistory()->create([
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $admin->id,
            'note' => $note,
        ]);
    }
}
