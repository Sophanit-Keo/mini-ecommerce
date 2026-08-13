<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReconciliationStatus;
use App\Models\Address;
use App\Models\DeliverySlot;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Produces a confirmed order whose totals satisfy `ck_orders_total_estimated`, and which
 * carries a delivery slot as `ck_orders_slot_required` demands of anything past checkout.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = Money::round(fake()->randomFloat(2, 10, 120));
        $deliveryFee = '3.99';
        $discount = '0.00';
        $tax = Money::percentageOf($subtotal, '5');
        $total = Money::sub(Money::sum([$subtotal, $deliveryFee, $tax]), $discount);

        return [
            'order_number' => 'GR-'.strtoupper(Str::random(10)),
            'user_id' => User::factory(),
            'delivery_address_id' => null,
            'delivery_address_snapshot' => $this->addressSnapshot(),
            'delivery_slot_id' => DeliverySlot::factory(),
            'status' => OrderStatus::Confirmed,
            'payment_status' => PaymentStatus::Authorized,
            'payment_method' => PaymentMethod::Card,
            'currency' => 'USD',
            'subtotal_estimated' => $subtotal,
            'delivery_fee' => $deliveryFee,
            'discount_total' => $discount,
            'tax_estimated' => $tax,
            'total_estimated' => $total,
            'subtotal_final' => null,
            'tax_final' => null,
            'total_final' => null,
            'authorized_amount' => Money::add($total, Money::percentageOf($total, '10')),
            'captured_amount' => null,
            'customer_note' => null,
            'idempotency_key' => (string) Str::uuid7(),
            'reservation_expires_at' => null,
            'confirmed_at' => now(),
        ];
    }

    /**
     * Checkout has happened but payment has not settled: stock is reserved and the
     * reservation is on a 30-minute clock (design-review finding R-02).
     */
    public function pendingPayment(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'confirmed_at' => null,
            'reservation_expires_at' => now()->addMinutes(30),
        ]);
    }

    public function reservationExpired(): static
    {
        return $this->pendingPayment()->state(fn (array $attributes) => [
            'reservation_expires_at' => now()->subMinute(),
        ]);
    }

    /**
     * Picking is done, so the final figures exist and payment can be captured for the amount
     * actually picked rather than the estimate.
     *
     * @param  string  $subtotalFinal  authoritative sum of order_items.final_line_total (R-03)
     */
    public function packed(string $subtotalFinal): static
    {
        return $this->state(function (array $attributes) use ($subtotalFinal) {
            $taxFinal = Money::percentageOf($subtotalFinal, '5');
            $totalFinal = Money::sub(
                Money::sum([$subtotalFinal, $attributes['delivery_fee'], $taxFinal]),
                $attributes['discount_total'],
            );

            return [
                'status' => OrderStatus::Packed,
                'payment_status' => PaymentStatus::Captured,
                'subtotal_final' => $subtotalFinal,
                'tax_final' => $taxFinal,
                'total_final' => $totalFinal,
                'captured_amount' => $totalFinal,
                'reconciliation_status' => ReconciliationStatus::Settled,
                'reconciliation_delta' => null,
                'reconciliation_reference' => 'exact_authorization',
                'reconciled_at' => now(),
                'fulfilled_at' => now(),
                'reservation_expires_at' => null,
            ];
        });
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => fake()->sentence(),
        ]);
    }

    public function forAddress(Address $address): static
    {
        return $this->state(fn (array $attributes) => [
            'delivery_address_id' => $address->id,
            'user_id' => $address->user_id,
            'delivery_address_snapshot' => $address->toSnapshot(),
        ]);
    }

    /**
     * @return array<string, string|null>
     */
    private function addressSnapshot(): array
    {
        return [
            'recipientName' => fake()->name(),
            'phone' => fake()->numerify('+1##########'),
            'line1' => fake()->streetAddress(),
            'line2' => null,
            'city' => fake()->city(),
            'region' => fake()->stateAbbr(),
            'postalCode' => fake()->postcode(),
            'countryCode' => 'US',
            'latitude' => (string) fake()->latitude(),
            'longitude' => (string) fake()->longitude(),
            'deliveryNotes' => null,
        ];
    }
}
