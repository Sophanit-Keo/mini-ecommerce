<?php

use App\Enums\CartStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\DeliverySlot;
use App\Models\Inventory;
use App\Models\InventoryAdjustment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ports `db/verify_constraints.sh`: proves the schema *rejects* invalid data.
 *
 * The reconciliation test checks that good data adds up. This checks the opposite — that
 * each constraint actually fires. A schema whose constraints are never tested is a schema
 * whose constraints might not exist, which is exactly what happens on MySQL below 8.0.16
 * and MariaDB below 10.2.1: they parse CHECK constraints and silently ignore them.
 *
 * Writes go through the query builder rather than Eloquent on purpose. The point is what the
 * database refuses, not what the application declines to send it.
 */

/** Attempt a write and assert the database refuses it, naming the constraint that fired. */
function expectRejection(callable $write, string $constraint): void
{
    expect($write)->toThrow(QueryException::class, $constraint);
}

// ---------------------------------------------------------------------------
// products — the pricing shape
// ---------------------------------------------------------------------------

test('a unit product cannot carry a negative price', function () {
    $product = Product::factory()->create();

    expectRejection(
        fn () => DB::table('products')->where('id', $product->id)->update(['price' => -1.00]),
        'ck_products_pricing_shape',
    );
});

test('a weight product cannot exist without an average weight', function () {
    $product = Product::factory()->weight()->create();

    expectRejection(
        fn () => DB::table('products')->where('id', $product->id)->update(['average_weight_kg' => null]),
        'ck_products_pricing_shape',
    );
});

test('a unit product cannot also carry a price per kilogram', function () {
    $product = Product::factory()->create();

    expectRejection(
        fn () => DB::table('products')->where('id', $product->id)->update(['price_per_kg' => 4.50]),
        'ck_products_pricing_shape',
    );
});

test('a weight product cannot also carry a flat price', function () {
    $product = Product::factory()->weight()->create();

    expectRejection(
        fn () => DB::table('products')->where('id', $product->id)->update(['price' => 4.50]),
        'ck_products_pricing_shape',
    );
});

test('the maximum order quantity cannot fall below the minimum', function () {
    $product = Product::factory()->create(['min_order_quantity' => 2.000]);

    expectRejection(
        fn () => DB::table('products')->where('id', $product->id)->update(['max_order_quantity' => 1.000]),
        'ck_products_qty_bounds',
    );
});

test('the weight tolerance cannot exceed one hundred percent', function () {
    $product = Product::factory()->weight()->create();

    expectRejection(
        fn () => DB::table('products')->where('id', $product->id)->update(['weight_tolerance_pct' => 101.00]),
        'ck_products_tolerance',
    );
});

test('a compare-at price cannot be negative', function () {
    $product = Product::factory()->create();

    expectRejection(
        fn () => DB::table('products')->where('id', $product->id)->update(['compare_at_price' => -0.01]),
        'ck_products_compare_at',
    );
});

// ---------------------------------------------------------------------------
// Uniqueness that applies only to live rows
// ---------------------------------------------------------------------------

test('two live products cannot share a SKU', function () {
    Product::factory()->create(['sku' => 'PRD-DUP-001']);

    expectRejection(
        fn () => Product::factory()->create(['sku' => 'PRD-DUP-001']),
        'uq_products_sku_active',
    );
});

test('a SKU is freed for reuse once the original is soft-deleted', function () {
    Product::factory()->create(['sku' => 'PRD-DUP-001'])->delete();

    $replacement = Product::factory()->create(['sku' => 'PRD-DUP-001']);

    expect($replacement->exists)->toBeTrue()
        ->and(Product::withTrashed()->where('sku', 'PRD-DUP-001')->count())->toBe(2);
});

test('two live users cannot share an email address', function () {
    User::factory()->create(['email' => 'dup@example.com']);

    expectRejection(
        fn () => User::factory()->create(['email' => 'dup@example.com']),
        'uq_users_email_active',
    );
});

test('an email is freed for re-registration once the account is soft-deleted', function () {
    User::factory()->create(['email' => 'dup@example.com'])->delete();

    expect(User::factory()->create(['email' => 'dup@example.com'])->exists)->toBeTrue();
});

test('a user cannot hold two default addresses', function () {
    $user = User::factory()->create();
    Address::factory()->for($user)->default()->create();

    expectRejection(
        fn () => Address::factory()->for($user)->default()->create(),
        'uq_addresses_default',
    );
});

test('a soft-deleted default address does not block a new one', function () {
    $user = User::factory()->create();
    Address::factory()->for($user)->default()->create()->delete();

    expect(Address::factory()->for($user)->default()->create()->exists)->toBeTrue();
});

test('an email must at least look like an address', function () {
    expectRejection(
        fn () => DB::table('users')->insert([
            'public_id' => Str::uuid7()->getBytes(),
            'email' => 'not-an-email',
            'password_hash' => 'x',
            'full_name' => 'Malformed',
        ]),
        'ck_users_email_shape',
    );
});

test('coordinates must be in range and must arrive as a pair', function (array $update, string $constraint) {
    $address = Address::factory()->create();

    expectRejection(
        fn () => DB::table('addresses')->where('id', $address->id)->update($update),
        $constraint,
    );
})->with([
    'latitude beyond the pole' => [['latitude' => 91.0], 'ck_addresses_lat'],
    'longitude past the date line' => [['longitude' => 181.0], 'ck_addresses_lng'],
    'half a coordinate pair' => [['latitude' => null], 'ck_addresses_geo_pair'],
]);

test('a refresh token cannot expire before it was issued', function () {
    // `issued_at` is a database default, so it only exists after a round trip.
    $token = RefreshToken::factory()->create()->refresh();

    expectRejection(
        fn () => DB::table('refresh_tokens')->where('id', $token->id)
            ->update(['expires_at' => $token->issued_at->copy()->subHour()]),
        'ck_refresh_expiry',
    );
});

// ---------------------------------------------------------------------------
// inventory — the oversell guarantees
// ---------------------------------------------------------------------------

test('more stock cannot be reserved than is on hand', function () {
    $inventory = Inventory::factory()->create(['quantity_on_hand' => 10.000, 'quantity_reserved' => 0.000]);

    expectRejection(
        fn () => DB::table('inventory')->where('product_id', $inventory->product_id)
            ->update(['quantity_reserved' => 10.001]),
        'ck_inventory_not_oversold',
    );
});

test('stock on hand cannot go negative', function () {
    $inventory = Inventory::factory()->create(['quantity_reserved' => 0.000]);

    // Three constraints overlap here and only one can fire first. Driving on-hand below zero
    // necessarily puts it under the (non-negative) reserved figure, so the oversell guard is
    // what refuses the write — `ck_inventory_on_hand` is the belt to its braces.
    expectRejection(
        fn () => DB::table('inventory')->where('product_id', $inventory->product_id)
            ->update(['quantity_on_hand' => -1.000]),
        'ck_inventory_not_oversold',
    );
});

test('reserved stock cannot go negative', function () {
    $inventory = Inventory::factory()->create();

    expectRejection(
        fn () => DB::table('inventory')->where('product_id', $inventory->product_id)
            ->update(['quantity_reserved' => -1.000]),
        'ck_inventory_reserved',
    );
});

test('a stock movement of zero is not a movement', function () {
    $product = Product::factory()->create();

    expectRejection(
        fn () => InventoryAdjustment::factory()->for($product)->create(['delta' => 0.000]),
        'ck_inv_adj_nonzero',
    );
});

// ---------------------------------------------------------------------------
// delivery slots — capacity
// ---------------------------------------------------------------------------

test('a slot cannot be booked beyond its capacity', function () {
    $slot = DeliverySlot::factory()->create(['capacity' => 5, 'booked_count' => 5]);

    expectRejection(
        fn () => DB::table('delivery_slots')->where('id', $slot->id)->update(['booked_count' => 6]),
        'ck_slots_capacity',
    );
});

test('a slot cannot end before it starts', function () {
    $slot = DeliverySlot::factory()->create();

    expectRejection(
        fn () => DB::table('delivery_slots')->where('id', $slot->id)
            ->update(['ends_at' => $slot->starts_at->copy()->subHour()]),
        'ck_slots_window',
    );
});

test('the atomic booking statement affects no rows once a slot is full', function () {
    $slot = DeliverySlot::factory()->full()->create();

    // This is the statement checkout runs. Zero affected rows is how "slot full" is detected,
    // without the read-write gap that a COUNT-then-INSERT sequence leaves open.
    $affected = DB::table('delivery_slots')
        ->where('id', $slot->id)
        ->whereColumn('booked_count', '<', 'capacity')
        ->increment('booked_count');

    expect($affected)->toBe(0);
});

// ---------------------------------------------------------------------------
// carts
// ---------------------------------------------------------------------------

test('a user cannot hold two active carts', function () {
    $user = User::factory()->create();
    Cart::factory()->for($user)->create();

    expectRejection(
        fn () => Cart::factory()->for($user)->create(),
        'uq_carts_active_user',
    );
});

test('a converted cart does not block a new active one', function () {
    $user = User::factory()->create();
    Cart::factory()->for($user)->status(CartStatus::Converted)->create();

    expect(Cart::factory()->for($user)->create()->exists)->toBeTrue();
});

test('a cart must belong to a user or a guest token', function () {
    expectRejection(
        fn () => DB::table('carts')->insert([
            'public_id' => Str::uuid7()->getBytes(),
            'user_id' => null,
            'guest_token' => null,
        ]),
        'ck_carts_owner',
    );
});

test('a cart line cannot have zero quantity', function () {
    expectRejection(
        fn () => CartItem::factory()->create(['quantity' => 0.000]),
        'ck_cart_items_quantity',
    );
});

test('a cart line cannot reference a cart that does not exist', function () {
    expectRejection(
        fn () => CartItem::factory()->create(['cart_id' => 999999]),
        'fk_cart_items_cart',
    );
});

test('the same product cannot appear twice in one cart', function () {
    $item = CartItem::factory()->create();

    expectRejection(
        fn () => CartItem::factory()->create([
            'cart_id' => $item->cart_id,
            'product_id' => $item->product_id,
        ]),
        'uq_cart_items_cart_product',
    );
});

// ---------------------------------------------------------------------------
// orders — money that has to add up
// ---------------------------------------------------------------------------

test('an estimated total that does not reconcile with its components is refused', function () {
    $order = Order::factory()->create();

    expectRejection(
        fn () => DB::table('orders')->where('id', $order->id)->update(['total_estimated' => 0.01]),
        'ck_orders_total_estimated',
    );
});

test('a final total that does not reconcile with its components is refused', function () {
    $order = Order::factory()->packed('40.00')->create();

    expectRejection(
        fn () => DB::table('orders')->where('id', $order->id)->update(['total_final' => 0.01]),
        'ck_orders_total_final',
    );
});

test('the double-submit guard rejects a repeated idempotency key', function () {
    $order = Order::factory()->create();

    expectRejection(
        fn () => Order::factory()->create(['idempotency_key' => $order->idempotency_key]),
        'uq_orders_idempotency_key',
    );
});

test('the final figures must be settled together or not at all', function () {
    $order = Order::factory()->create();

    expectRejection(
        fn () => DB::table('orders')->where('id', $order->id)->update(['subtotal_final' => 10.00]),
        'ck_orders_final_together',
    );
});

test('a cancelled order must record when it was cancelled', function () {
    $order = Order::factory()->create();

    expectRejection(
        fn () => DB::table('orders')->where('id', $order->id)
            ->update(['status' => OrderStatus::Cancelled->value]),
        'ck_orders_cancelled',
    );
});

test('an order past checkout must carry a delivery slot', function () {
    // Design-review finding R-04: without this, a confirmed order with no slot appears on no
    // picking manifest (ix_orders_slot never matches it) and is silently never fulfilled.
    $order = Order::factory()->create();

    expectRejection(
        fn () => DB::table('orders')->where('id', $order->id)->update(['delivery_slot_id' => null]),
        'ck_orders_slot_required',
    );
});

test('an order still awaiting payment may have no slot yet', function () {
    $order = Order::factory()->pendingPayment()->create(['delivery_slot_id' => null]);

    expect($order->delivery_slot_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// order items — the weight and picking shapes
// ---------------------------------------------------------------------------

test('a weight line must carry a weight estimate', function () {
    $item = OrderItem::factory()->weighed()->create();

    expectRejection(
        fn () => DB::table('order_items')->where('id', $item->id)->update(['estimated_weight_kg' => null]),
        'ck_order_items_weight_shape',
    );
});

test('a unit line must not carry a weight estimate', function () {
    $item = OrderItem::factory()->create();

    expectRejection(
        fn () => DB::table('order_items')->where('id', $item->id)->update(['estimated_weight_kg' => 0.500]),
        'ck_order_items_weight_shape',
    );
});

test('a line marked picked must be priced', function () {
    $item = OrderItem::factory()->create();

    expectRejection(
        fn () => DB::table('order_items')->where('id', $item->id)
            ->update(['status' => OrderItemStatus::Picked->value]),
        'ck_order_items_picked_shape',
    );
});

test('a line still pending must not already be priced', function () {
    $item = OrderItem::factory()->create();

    expectRejection(
        fn () => DB::table('order_items')->where('id', $item->id)->update(['final_line_total' => 5.00]),
        'ck_order_items_picked_shape',
    );
});

test('an unavailable line is not priced at zero — it is not priced at all', function () {
    $item = OrderItem::factory()->unavailable()->create();

    expect($item->final_line_total)->toBeNull();

    expectRejection(
        fn () => DB::table('order_items')->where('id', $item->id)->update(['final_line_total' => 0.00]),
        'ck_order_items_picked_shape',
    );
});

test('a line quantity must be positive', function () {
    expectRejection(
        fn () => OrderItem::factory()->create(['ordered_quantity' => 0.000]),
        'ck_order_items_quantity',
    );
});

// ---------------------------------------------------------------------------
// order status history
// ---------------------------------------------------------------------------

test('a status transition that changes nothing is not a transition', function () {
    expectRejection(
        fn () => OrderStatusHistory::factory()
            ->transition(OrderStatus::Confirmed, OrderStatus::Confirmed)
            ->create(),
        'ck_status_history_transition',
    );
});
