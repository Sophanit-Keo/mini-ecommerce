<?php

use App\Enums\SoldBy;
use App\Models\DeliverySlot;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\Money;

/**
 * Ports `db/verify.sql`: every figure in the seeded data must reconcile.
 *
 * The fixture is the one fully worked order from the spec — the reference example for the
 * whole estimated-versus-final model:
 *
 *                     estimated      final
 *       subtotal        23.81   ->   24.43     (picked weights came in heavier)
 *       tax @ 5%         1.19   ->    1.22
 *       delivery         3.99        3.99
 *       ---------------------------------
 *       total           28.99   ->   29.64
 *       authorised      31.89                  (estimate + 10% tolerance buffer)
 *       captured                    29.64      (fits inside the authorisation)
 */
beforeEach(function () {
    $this->seed();
});

test('the seeded order reproduces the specification worked example', function () {
    $order = Order::find(1);

    expect($order->subtotal_estimated)->toBe('23.81')
        ->and($order->tax_estimated)->toBe('1.19')
        ->and($order->delivery_fee)->toBe('3.99')
        ->and($order->total_estimated)->toBe('28.99')
        ->and($order->subtotal_final)->toBe('24.43')
        ->and($order->tax_final)->toBe('1.22')
        ->and($order->total_final)->toBe('29.64')
        ->and($order->authorized_amount)->toBe('31.89')
        ->and($order->captured_amount)->toBe('29.64');
});

test('order line totals sum to the order subtotals', function () {
    foreach (Order::with('items')->get() as $order) {
        expect(Money::sum($order->items->pluck('estimated_line_total')))
            ->toBe($order->subtotal_estimated);

        if ($order->hasFinalTotals()) {
            // Design-review finding R-03: `order_items.final_line_total` is the single
            // authoritative figure. Adding `order_item_substitutions.price_delta` on top of
            // it would double-count every substituted line.
            expect(Money::sum($order->items->pluck('final_line_total')))
                ->toBe($order->subtotal_final);
        }
    }
});

test('every total reconciles with its own components', function () {
    foreach (Order::all() as $order) {
        expect(Money::sub(
            Money::sum([$order->subtotal_estimated, $order->delivery_fee, $order->tax_estimated]),
            $order->discount_total,
        ))->toBe($order->total_estimated);

        if ($order->hasFinalTotals()) {
            expect(Money::sub(
                Money::sum([$order->subtotal_final, $order->delivery_fee, $order->tax_final]),
                $order->discount_total,
            ))->toBe($order->total_final);
        }
    }
});

test('weight lines price out at unit price times weight, half-up to two decimals', function () {
    $lines = OrderItem::where('sold_by', SoldBy::Weight)->get();

    expect($lines)->not->toBeEmpty();

    foreach ($lines as $line) {
        expect(Money::lineTotal($line->unit_price, $line->estimated_weight_kg))
            ->toBe($line->estimated_line_total);

        if ($line->picked_weight_kg !== null) {
            expect(Money::lineTotal($line->unit_price, $line->picked_weight_kg))
                ->toBe($line->final_line_total);
        }
    }
});

test('unit lines price out at unit price times quantity', function () {
    $lines = OrderItem::where('sold_by', SoldBy::Unit)->get();

    expect($lines)->not->toBeEmpty();

    foreach ($lines as $line) {
        expect(Money::lineTotal($line->unit_price, $line->ordered_quantity))
            ->toBe($line->estimated_line_total);
    }
});

test('the captured amount fits inside the authorisation buffer', function () {
    foreach (Order::whereNotNull('captured_amount')->get() as $order) {
        expect(Money::compare($order->captured_amount, $order->authorized_amount))
            ->toBeLessThanOrEqual(0);
    }
});

test('the final total exceeds the estimate when picked weights come in heavier', function () {
    // The behaviour a general e-commerce background gets wrong: this is normal, not a bug.
    $order = Order::find(1);

    expect(Money::compare($order->total_final, $order->total_estimated))->toBe(1);
});

test('effective_price resolves for every product', function () {
    // A NULL here means the catalogue price sort is silently broken again (R-01).
    expect(Product::whereNull('effective_price')->count())->toBe(0);
});

test('ordering by effective_price is monotonic across both pricing shapes', function () {
    $prices = Product::orderBy('effective_price')->pluck('effective_price')
        ->map(fn (string $price) => (float) $price)
        ->all();

    $sorted = $prices;
    sort($sorted);

    expect($prices)->toBe($sorted)
        ->and(Product::where('sold_by', SoldBy::Weight)->count())->toBeGreaterThan(0)
        ->and(Product::where('sold_by', SoldBy::Unit)->count())->toBeGreaterThan(0);
});

test('every product has exactly one primary image and one inventory row', function () {
    $products = Product::with(['images', 'inventory'])->get();

    expect($products)->toHaveCount(32);

    foreach ($products as $product) {
        expect($product->images->where('is_primary', true))->toHaveCount(1)
            ->and($product->inventory)->not->toBeNull();
    }
});

test('the generated availability column is consistent for every product', function () {
    foreach (Product::with('inventory')->get() as $product) {
        expect($product->inventory->quantity_available)->toBe(Money::sub(
            $product->inventory->quantity_on_hand,
            $product->inventory->quantity_reserved,
            Money::QUANTITY_SCALE,
        ));
    }
});

test('no slot is overbooked', function () {
    foreach (DeliverySlot::all() as $slot) {
        expect($slot->booked_count)->toBeLessThanOrEqual($slot->capacity);
    }
});

test('the status history forms a connected chain ending at the current status', function () {
    $order = Order::with('statusHistory')->find(1);
    $history = $order->statusHistory->sortBy('id')->values();

    expect($history->first()->from_status)->toBeNull()
        ->and($history->last()->to_status)->toBe($order->status);

    // skip() preserves keys, so $index is this entry's own position — the predecessor is one
    // behind it.
    foreach ($history->skip(1) as $index => $entry) {
        expect($entry->from_status)->toBe($history[$index - 1]->to_status);
    }
});
