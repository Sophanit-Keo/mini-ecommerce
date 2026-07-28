<?php

namespace Database\Seeders;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SoldBy;
use App\Enums\SubstitutionPreference;
use App\Models\Address;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Support\Money;
use Database\Seeders\Concerns\SeedsPublicIds;
use Illuminate\Database\Seeder;

/**
 * The one fully worked order from `db/seed.sql` — the reference example for the entire
 * estimated-versus-final model, and the fixture `db/verify.sql` reconciles against.
 *
 *                     estimated      final
 *       subtotal        23.81   ->   24.43     (picked weights came in heavier)
 *       tax @ 5%         1.19   ->    1.22
 *       delivery         3.99        3.99
 *       ---------------------------------
 *       total           28.99   ->   29.64
 *       authorised      31.89                  (estimate + 10% tolerance buffer)
 *       captured                    29.64      (fits inside the authorisation)
 *
 * Every figure below is *derived* rather than transcribed, so the seeder demonstrates that
 * this implementation's arithmetic reproduces the spec's worked example rather than merely
 * restating its answers.
 */
class WorkedOrderSeeder extends Seeder
{
    use SeedsPublicIds;

    private const TAX_RATE = '5';

    private const DELIVERY_FEE = '3.99';

    private const TOLERANCE_PCT = '10';

    public function run(): void
    {
        $lines = $this->lines();

        $subtotalEstimated = Money::sum(array_column($lines, 'estimated_line_total'));
        $subtotalFinal = Money::sum(array_column($lines, 'final_line_total'));

        $taxEstimated = Money::percentageOf($subtotalEstimated, self::TAX_RATE);
        $taxFinal = Money::percentageOf($subtotalFinal, self::TAX_RATE);

        $totalEstimated = Money::sum([$subtotalEstimated, self::DELIVERY_FEE, $taxEstimated]);
        $totalFinal = Money::sum([$subtotalFinal, self::DELIVERY_FEE, $taxFinal]);

        // Authorise the estimate plus the weight tolerance, so a heavier-than-average pick
        // still fits inside the authorisation and needs no second customer interaction.
        $authorized = Money::add($totalEstimated, Money::percentageOf($totalEstimated, self::TOLERANCE_PCT));

        (new Order)->forceFill([
            'id' => 1,
            'public_id' => $this->publicId(7, 1),
            'order_number' => 'GRC-2026-000117',
            'user_id' => 1,
            'delivery_address_id' => 1,
            'delivery_address_snapshot' => Address::find(1)->toSnapshot(),
            'delivery_slot_id' => 1,
            'status' => OrderStatus::Delivered,
            'payment_status' => PaymentStatus::Captured,
            'payment_method' => PaymentMethod::Card,
            'currency' => 'USD',
            'subtotal_estimated' => $subtotalEstimated,
            'delivery_fee' => self::DELIVERY_FEE,
            'discount_total' => '0.00',
            'tax_estimated' => $taxEstimated,
            'total_estimated' => $totalEstimated,
            'subtotal_final' => $subtotalFinal,
            'tax_final' => $taxFinal,
            'total_final' => $totalFinal,
            'authorized_amount' => $authorized,
            'captured_amount' => $totalFinal,
            'customer_note' => 'Please pick greener bananas if you can.',
            'idempotency_key' => 'idem_01J8Z5X2Q7WNRT4K9C3M6V0BHF',
            'placed_at' => '2026-07-20 18:42:11',
            'confirmed_at' => '2026-07-20 18:42:14',
            'delivered_at' => '2026-07-21 10:12:38',
        ])->save();

        foreach ($lines as $line) {
            OrderItem::create(['order_id' => 1, ...$line]);
        }

        $this->seedStatusHistory($authorized, $totalFinal);
    }

    /**
     * The four lines, with both money figures derived from the unit price and the relevant
     * quantity. For a weighed line that quantity is the weight — estimated from the product's
     * average at checkout, and the actual scale reading once picked.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lines(): array
    {
        $rows = [
            [
                'product_id' => 13,
                'product_name' => 'Whole Milk, 1 Gallon',
                'product_sku' => 'PRD-MLK-013',
                'brand' => 'Meadow Farms',
                'sold_by' => SoldBy::Unit,
                'unit_label' => 'ea',
                'unit_price' => '4.29',
                'ordered_quantity' => '2.000',
                'estimated_weight_kg' => null,
                'picked_quantity' => '2.000',
                'picked_weight_kg' => null,
                'substitution_preference' => SubstitutionPreference::Similar,
            ],
            [
                'product_id' => 1,
                'product_name' => 'Bananas, Loose',
                'product_sku' => 'PRD-BAN-001',
                'brand' => 'Farm Fresh',
                'sold_by' => SoldBy::Weight,
                'unit_label' => 'kg',
                'unit_price' => '1.52',
                'ordered_quantity' => '1.200',
                'estimated_weight_kg' => '1.200',
                'picked_quantity' => '1.200',
                'picked_weight_kg' => '1.310',
                'substitution_preference' => SubstitutionPreference::Similar,
            ],
            [
                'product_id' => 19,
                'product_name' => 'Sourdough Loaf',
                'product_sku' => 'PRD-BRD-019',
                'brand' => 'Corner Bake',
                'sold_by' => SoldBy::Unit,
                'unit_label' => 'ea',
                'unit_price' => '5.49',
                'ordered_quantity' => '1.000',
                'estimated_weight_kg' => null,
                'picked_quantity' => '1.000',
                'picked_weight_kg' => null,
                'substitution_preference' => SubstitutionPreference::None,
            ],
            [
                'product_id' => 23,
                'product_name' => 'Chicken Breast Fillets',
                'product_sku' => 'PRD-CHK-023',
                'brand' => 'Blue Barn',
                'sold_by' => SoldBy::Weight,
                'unit_label' => 'ea',
                'unit_price' => '9.90',
                'ordered_quantity' => '1.000',
                'estimated_weight_kg' => '0.800',
                'picked_quantity' => '1.000',
                'picked_weight_kg' => '0.845',
                'substitution_preference' => SubstitutionPreference::ContactMe,
            ],
        ];

        return array_map(function (array $row): array {
            $estimatedQuantity = $row['estimated_weight_kg'] ?? $row['ordered_quantity'];
            $pickedQuantity = $row['picked_weight_kg'] ?? $row['picked_quantity'];

            return [
                ...$row,
                'estimated_line_total' => Money::lineTotal($row['unit_price'], $estimatedQuantity),
                'final_line_total' => Money::lineTotal($row['unit_price'], $pickedQuantity),
                'status' => OrderItemStatus::Picked,
            ];
        }, $rows);
    }

    private function seedStatusHistory(string $authorized, string $captured): void
    {
        $transitions = [
            [null, OrderStatus::PendingPayment, 1, 'Order placed', '2026-07-20 18:42:11'],
            [OrderStatus::PendingPayment, OrderStatus::Confirmed, null, "Card authorised for {$authorized}", '2026-07-20 18:42:14'],
            [OrderStatus::Confirmed, OrderStatus::Picking, 3, 'Assigned to picker', '2026-07-21 08:05:00'],
            [OrderStatus::Picking, OrderStatus::Packed, 3, 'All 4 lines picked, none substituted', '2026-07-21 08:51:20'],
            [OrderStatus::Packed, OrderStatus::OutForDelivery, 3, 'Loaded on van 4', '2026-07-21 09:02:00'],
            [OrderStatus::OutForDelivery, OrderStatus::Delivered, 3, "Handed to customer; captured {$captured}", '2026-07-21 10:12:38'],
        ];

        foreach ($transitions as [$from, $to, $changedBy, $note, $at]) {
            (new OrderStatusHistory)->forceFill([
                'order_id' => 1,
                'from_status' => $from,
                'to_status' => $to,
                'changed_by' => $changedBy,
                'note' => $note,
                'created_at' => $at,
            ])->save();
        }
    }
}
