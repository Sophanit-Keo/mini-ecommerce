<?php

namespace App\Actions\Orders;

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\ProblemException;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class RestoreOrderCart
{
    public function handle(User $user, Order $order): Cart
    {
        return DB::transaction(function () use ($user, $order): Cart {
            $lockedOrder = Order::query()
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($order->id);

            if ($lockedOrder->status !== OrderStatus::Cancelled || $lockedOrder->payment_status !== PaymentStatus::Failed) {
                throw ProblemException::invalidStatusTransition($lockedOrder->status->value, OrderStatus::PendingPayment->value);
            }

            $cart = $this->activeCart($user);

            if ($lockedOrder->cart_restored_at !== null) {
                return $cart->load('items.product');
            }

            $products = Product::query()
                ->whereIn('id', $lockedOrder->items->pluck('product_id')->filter())
                ->with('inventory')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $existingItems = $cart->items()->lockForUpdate()->get()->keyBy('product_id');

            foreach ($lockedOrder->items as $line) {
                $product = $products->get($line->product_id);

                if ($product === null || ! $product->is_active) {
                    throw ProblemException::badRequest("{$line->product_name} is no longer available and cannot be restored.");
                }

                $existing = $existingItems->get($product->id);
                $quantity = $existing === null
                    ? (string) $line->ordered_quantity
                    : Money::add($existing->quantity, $line->ordered_quantity, Money::QUANTITY_SCALE);
                $available = $product->inventory?->quantity_available ?? '0';

                if (Money::compare($quantity, $available, Money::QUANTITY_SCALE) > 0) {
                    throw ProblemException::insufficientStock($product->public_id, $quantity, $available, $product->name);
                }

                if ($existing === null) {
                    $existing = $cart->items()->create([
                        'product_id' => $product->id,
                        'quantity' => $quantity,
                        'unit_price_snapshot' => $product->chargeableUnitPrice(),
                        'substitution_preference' => $line->substitution_preference,
                        'note' => $line->note,
                    ]);
                    $existingItems->put($product->id, $existing);
                } else {
                    $existing->update([
                        'quantity' => $quantity,
                        'unit_price_snapshot' => $product->chargeableUnitPrice(),
                    ]);
                }
            }

            $lockedOrder->update(['cart_restored_at' => now()]);

            return $cart->load('items.product');
        });
    }

    private function activeCart(User $user): Cart
    {
        $cart = $user->carts()->where('status', CartStatus::Active)->lockForUpdate()->first();

        if ($cart !== null) {
            return $cart;
        }

        try {
            return $user->carts()->create(['status' => CartStatus::Active, 'currency' => 'USD']);
        } catch (QueryException $exception) {
            if (! str_contains($exception->getMessage(), 'uq_carts_active_user')) {
                throw $exception;
            }

            return $user->carts()->where('status', CartStatus::Active)->lockForUpdate()->firstOrFail();
        }
    }
}
