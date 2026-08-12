<?php

namespace App\Actions\Checkout;

use App\Enums\PaymentMethod;
use App\Exceptions\ProblemException;
use App\Models\Address;
use App\Models\Cart;
use App\Models\DeliverySlot;
use App\Models\User;
use App\Support\Bakong\BakongKhqr;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Produces a short-lived, server-signed checkout quote.
 *
 * The client only receives the quote token; it cannot change the amount, cart, slot,
 * address, or payment method without invalidating the HMAC. PlaceOrder re-computes
 * the fingerprint immediately before it locks and reserves the authoritative rows.
 */
class CreateCheckoutQuote
{
    private const TTL_MINUTES = 10;

    public function __construct(private readonly BakongKhqr $bakong) {}

    /**
     * @return array<string, mixed>
     */
    public function create(User $user, Cart $cart, Address $address, DeliverySlot $slot, PaymentMethod $paymentMethod): array
    {
        $this->assertPaymentMethodAvailable($paymentMethod);
        $this->assertQuoteable($cart, $slot);

        $items = $cart->items->map(function ($item): array {
            $product = $item->product;
            $unitPrice = $product->chargeableUnitPrice();

            return [
                'productId' => $product->public_id,
                'name' => $product->name,
                'quantity' => $item->quantity,
                'unitPrice' => $unitPrice,
                'lineTotal' => Money::lineTotal($unitPrice, $item->quantity),
                'availableQuantity' => $product->inventory?->quantity_available ?? '0',
            ];
        })->values()->all();

        $subtotal = Money::sum(array_column($items, 'lineTotal'));
        $deliveryFee = (string) $slot->fee;
        $tax = '0.00';
        $discount = '0.00';
        $total = Money::sub(Money::sum([$subtotal, $deliveryFee, $tax]), $discount);
        $expiresAt = CarbonImmutable::now()->addMinutes(self::TTL_MINUTES);

        $claims = [
            'v' => 1,
            'uid' => $user->id,
            'fp' => $this->fingerprint($cart, $address, $slot, $paymentMethod),
            'exp' => $expiresAt->getTimestamp(),
            'nonce' => Str::random(16),
        ];

        return [
            'quoteToken' => $this->sign($claims),
            'expiresAt' => $expiresAt->toIso8601String(),
            'currency' => $cart->currency,
            'items' => $items,
            'subtotal' => $subtotal,
            'deliveryFee' => $deliveryFee,
            'discountTotal' => $discount,
            'tax' => $tax,
            'total' => $total,
            'paymentMethod' => $paymentMethod->value,
            'deliverySlotId' => $slot->public_id,
            'addressId' => $address->public_id,
        ];
    }

    public function assertCurrent(string $token, User $user, Cart $cart, Address $address, DeliverySlot $slot, PaymentMethod $paymentMethod): void
    {
        $this->assertPaymentMethodAvailable($paymentMethod);
        $claims = $this->verify($token);

        if ($claims === null
            || ($claims['v'] ?? null) !== 1
            || ($claims['uid'] ?? null) !== $user->id
            || ! isset($claims['exp'])
            || ! is_int($claims['exp'])
            || $claims['exp'] < now()->getTimestamp()
            || ! isset($claims['fp'])
            || ! is_string($claims['fp'])
            || ! hash_equals($claims['fp'], $this->fingerprint($cart, $address, $slot, $paymentMethod))) {
            throw ProblemException::staleCheckoutQuote();
        }

        $this->assertQuoteable($cart, $slot);
    }

    private function assertPaymentMethodAvailable(PaymentMethod $paymentMethod): void
    {
        if ($paymentMethod === PaymentMethod::Bakong) {
            $this->bakong->assertConfigured();
        }
    }

    private function assertQuoteable(Cart $cart, DeliverySlot $slot): void
    {
        if ($cart->items->isEmpty()) {
            throw ProblemException::badRequest('The cart is empty.');
        }

        if (! $slot->is_active || $slot->starts_at->isPast() || $slot->isFull()) {
            throw ProblemException::slotUnavailable($slot->public_id);
        }

        foreach ($cart->items as $item) {
            $product = $item->product;
            $available = $product->inventory?->quantity_available ?? '0';

            if (! $product->is_active || Money::compare($item->quantity, $available, Money::QUANTITY_SCALE) > 0) {
                throw ProblemException::insufficientStock($product->public_id, $item->quantity, $available, $product->name);
            }
        }
    }

    private function fingerprint(Cart $cart, Address $address, DeliverySlot $slot, PaymentMethod $paymentMethod): string
    {
        $lines = $cart->items->sortBy('id')->map(function ($item): string {
            $product = $item->product;
            $inventory = $product->inventory;

            return implode(':', [
                $item->id,
                $product->id,
                $item->quantity,
                $product->chargeableUnitPrice(),
                $product->updated_at?->getTimestamp() ?? 0,
                $inventory?->quantity_available ?? '0',
                $inventory?->updated_at?->getTimestamp() ?? 0,
            ]);
        })->implode('|');

        return hash('sha256', implode('#', [
            $cart->id,
            $cart->currency,
            $address->id,
            $address->updated_at?->getTimestamp() ?? 0,
            $slot->id,
            $slot->fee,
            $slot->booked_count,
            $slot->updated_at?->getTimestamp() ?? 0,
            $paymentMethod->value,
            $lines,
        ]));
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function sign(array $claims): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode($claims, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $payload, (string) config('app.key'), true);

        return $payload.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function verify(string $token): ?array
    {
        [$payload, $signature] = array_pad(explode('.', $token, 2), 2, null);

        if (! is_string($payload) || ! is_string($signature) || $payload === '' || $signature === '') {
            return null;
        }

        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, (string) config('app.key'), true)), '+/', '-_'), '=');

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);

        if ($decoded === false) {
            return null;
        }

        try {
            $claims = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($claims) ? $claims : null;
    }
}
