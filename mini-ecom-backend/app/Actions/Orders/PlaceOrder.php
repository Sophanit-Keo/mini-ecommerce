<?php

namespace App\Actions\Orders;

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\ProblemException;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\DeliverySlot;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use App\Support\Telegram\TelegramOrderNotifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns the caller's active cart into an Order.
 *
 * Checkout is idempotent on the pair `(user_id, idempotency_key)`
 * (`uq_orders_user_idempotency_key`): a replayed request by the same customer returns the order
 * already created rather than erroring or double-charging. The key is deliberately *not*
 * global — another customer using the same opaque value is unrelated and must never be able to
 * block this checkout. The check-then-insert race is closed by the unique index itself, not by
 * application code — a duplicate insert throws and is translated back into the existing row.
 */
class PlaceOrder
{
    public function __construct(private readonly TelegramOrderNotifier $telegramOrderNotifier) {}

    public function handle(
        User $user,
        Cart $cart,
        Address $address,
        DeliverySlot $slot,
        PaymentMethod $paymentMethod,
        string $idempotencyKey,
        ?string $customerNote,
    ): Order {
        $existing = Order::where('user_id', $user->id)->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        if ($cart->items->isEmpty()) {
            throw ProblemException::badRequest('The cart is empty.');
        }

        if (! $slot->is_active) {
            throw ProblemException::slotUnavailable($slot->public_id);
        }

        try {
            $order = DB::transaction(function () use ($user, $cart, $address, $slot, $paymentMethod, $idempotencyKey, $customerNote) {
                // Atomic increment-and-bounds-check: zero affected rows means the slot filled
                // between the read above and this write, not a read-then-write race.
                $booked = DeliverySlot::where('id', $slot->id)
                    ->where('booked_count', '<', $slot->capacity)
                    ->increment('booked_count');

                if ($booked === 0) {
                    throw ProblemException::slotUnavailable($slot->public_id);
                }

                $lines = $cart->items->map(fn ($item) => $this->priceLine($item));

                $subtotal = Money::sum($lines->pluck('estimated_line_total'));
                $deliveryFee = (string) $slot->fee;
                $tax = '0.00';
                $discount = '0.00';
                $total = Money::sub(Money::sum([$subtotal, $deliveryFee, $tax]), $discount);

                $order = Order::create([
                    'order_number' => $this->generateOrderNumber(),
                    'user_id' => $user->id,
                    'delivery_address_id' => $address->id,
                    'delivery_address_snapshot' => $address->toSnapshot(),
                    'delivery_slot_id' => $slot->id,
                    'status' => OrderStatus::PendingPayment,
                    'payment_status' => PaymentStatus::Pending,
                    'payment_method' => $paymentMethod,
                    'currency' => $cart->currency,
                    'subtotal_estimated' => $subtotal,
                    'delivery_fee' => $deliveryFee,
                    'discount_total' => $discount,
                    'tax_estimated' => $tax,
                    'total_estimated' => $total,
                    'customer_note' => $customerNote,
                    'idempotency_key' => $idempotencyKey,
                    'reservation_expires_at' => now()->addMinutes(30),
                    'placed_at' => now(),
                ]);

                foreach ($lines as $line) {
                    $order->items()->create($line);
                }

                $order->statusHistory()->create([
                    'from_status' => null,
                    'to_status' => OrderStatus::PendingPayment,
                    'changed_by' => $user->id,
                ]);

                $cart->update(['status' => CartStatus::Converted]);

                return $order;
            });

            // Sent after the transaction commits — an HTTP call inside DB::transaction() would
            // hold the row locks open for the duration of a network round trip to Telegram.
            $this->telegramOrderNotifier->notifyNewOrder($order->load(['items', 'deliverySlot']));

            return $order;
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'uq_orders_user_idempotency_key')) {
                throw $e;
            }

            // Lost the race to a concurrent replay of the same key; the winner's row is the
            // answer this request should have gotten.
            return Order::where('user_id', $user->id)->where('idempotency_key', $idempotencyKey)->firstOrFail();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function priceLine(CartItem $item): array
    {
        $product = $item->product;
        $unitPrice = $product->chargeableUnitPrice();
        $quantity = $item->quantity;
        $isWeighed = $product->sold_by->isWeighed();

        return [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'brand' => $product->brand,
            'sold_by' => $product->sold_by,
            'unit_label' => $product->unit_label,
            'unit_price' => $unitPrice,
            'ordered_quantity' => $quantity,
            'estimated_weight_kg' => $isWeighed ? $quantity : null,
            'estimated_line_total' => Money::lineTotal($unitPrice, $quantity),
            'substitution_preference' => $item->substitution_preference,
            'note' => $item->note,
        ];
    }

    private function generateOrderNumber(): string
    {
        return 'GR-'.strtoupper(Str::random(10));
    }
}
