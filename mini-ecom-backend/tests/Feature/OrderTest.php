<?php

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\DeliverySlot;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->otherCustomer = User::factory()->create();

    $this->actingAs($this->user, 'sanctum');
});

/**
 * A cart with one line, belonging to the caller, ready to check out.
 */
function cartWithOneItem(User $user, array $productOverrides = []): Cart
{
    $product = Product::factory()->create($productOverrides);
    Inventory::factory()->for($product)->create(['quantity_on_hand' => 50]);

    $cart = Cart::factory()->for($user)->create();
    CartItem::factory()->for($cart)->forProduct($product, 2)->create();

    return $cart;
}

function checkoutPayload(array $overrides = []): array
{
    return [
        'addressId' => $overrides['addressId'] ?? null,
        'deliverySlotId' => $overrides['deliverySlotId'] ?? null,
        'paymentMethod' => 'card',
        'customerNote' => 'Leave at the door',
        'idempotencyKey' => (string) Str::uuid7(),
        ...$overrides,
    ];
}

// ---------------------------------------------------------------------------
// Checkout
// ---------------------------------------------------------------------------

test('checkout creates an order from the active cart, snapshots the address and clears the cart', function () {
    $cart = cartWithOneItem($this->user, ['price' => 5.00]);
    $address = Address::factory()->for($this->user)->create(['city' => 'San Francisco']);
    $slot = DeliverySlot::factory()->create(['fee' => 3.99]);

    $response = $this->postJson('/v1/orders', checkoutPayload([
        'addressId' => $address->public_id,
        'deliverySlotId' => $slot->public_id,
    ]));

    $response->assertCreated()
        ->assertJsonPath('status', 'pending_payment')
        ->assertJsonPath('paymentStatus', 'pending')
        ->assertJsonPath('deliveryAddressSnapshot.city', 'San Francisco')
        ->assertJsonPath('subtotalEstimated', '10.00')
        ->assertJsonPath('deliveryFee', '3.99')
        ->assertJsonPath('totalEstimated', '13.99')
        ->assertJsonCount(1, 'items')
        ->assertJsonCount(1, 'statusHistory')
        ->assertJsonPath('statusHistory.0.toStatus', 'pending_payment');

    expect($cart->fresh()->status)->toBe(CartStatus::Converted)
        ->and($slot->fresh()->booked_count)->toBe(1)
        ->and(Order::count())->toBe(1);
});

test('checkout is idempotent on the idempotency key', function () {
    $cart = cartWithOneItem($this->user);
    $address = Address::factory()->for($this->user)->create();
    $slot = DeliverySlot::factory()->create();

    $payload = checkoutPayload(['addressId' => $address->public_id, 'deliverySlotId' => $slot->public_id]);

    $first = $this->postJson('/v1/orders', $payload)->assertCreated();
    $second = $this->postJson('/v1/orders', $payload)->assertCreated();

    expect($second->json('id'))->toBe($first->json('id'))
        ->and(Order::count())->toBe(1)
        ->and($slot->fresh()->booked_count)->toBe(1);
});

test('checkout rejects an empty cart', function () {
    Cart::factory()->for($this->user)->create();
    $address = Address::factory()->for($this->user)->create();
    $slot = DeliverySlot::factory()->create();

    $this->postJson('/v1/orders', checkoutPayload([
        'addressId' => $address->public_id,
        'deliverySlotId' => $slot->public_id,
    ]))->assertStatus(400);
});

test('checkout rejects a full delivery slot', function () {
    cartWithOneItem($this->user);
    $address = Address::factory()->for($this->user)->create();
    $slot = DeliverySlot::factory()->full()->create();

    $this->postJson('/v1/orders', checkoutPayload([
        'addressId' => $address->public_id,
        'deliverySlotId' => $slot->public_id,
    ]))->assertStatus(409)
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/slot-unavailable');
});

test('checkout rejects an inactive delivery slot', function () {
    cartWithOneItem($this->user);
    $address = Address::factory()->for($this->user)->create();
    $slot = DeliverySlot::factory()->inactive()->create();

    $this->postJson('/v1/orders', checkoutPayload([
        'addressId' => $address->public_id,
        'deliverySlotId' => $slot->public_id,
    ]))->assertStatus(409);
});

test('checkout rejects another customer address', function () {
    cartWithOneItem($this->user);
    $theirAddress = Address::factory()->for($this->otherCustomer)->create();
    $slot = DeliverySlot::factory()->create();

    $this->postJson('/v1/orders', checkoutPayload([
        'addressId' => $theirAddress->public_id,
        'deliverySlotId' => $slot->public_id,
    ]))->assertNotFound();
});

test('checkout requires a valid payment method', function () {
    cartWithOneItem($this->user);
    $address = Address::factory()->for($this->user)->create();
    $slot = DeliverySlot::factory()->create();

    $this->postJson('/v1/orders', checkoutPayload([
        'addressId' => $address->public_id,
        'deliverySlotId' => $slot->public_id,
        'paymentMethod' => 'bitcoin',
    ]))->assertUnprocessable();
});

// ---------------------------------------------------------------------------
// Listing and detail, and IDOR
// ---------------------------------------------------------------------------

test('the order list contains only the caller own orders, newest first', function () {
    Order::factory()->for($this->user)->create(['placed_at' => now()->subDay()]);
    $newest = Order::factory()->for($this->user)->create(['placed_at' => now()]);
    Order::factory()->for($this->otherCustomer)->count(2)->create();

    $this->getJson('/v1/orders')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $newest->public_id);
});

test('order detail eager-loads items and status history without N+1', function () {
    $order = Order::factory()->for($this->user)->create();
    OrderItem::factory()->for($order)->count(3)->create();
    OrderStatusHistory::factory()->for($order)->create();

    DB::enableQueryLog();
    $this->getJson('/v1/orders/'.$order->public_id)
        ->assertOk()
        ->assertJsonCount(3, 'items')
        ->assertJsonCount(1, 'statusHistory');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(3);
});

test('another customer order is indistinguishable from one that does not exist', function () {
    $theirs = Order::factory()->for($this->otherCustomer)->create();

    $this->getJson('/v1/orders/'.$theirs->public_id)->assertNotFound();
});

// ---------------------------------------------------------------------------
// Cancellation
// ---------------------------------------------------------------------------

test('a pending payment order is cancelled by its owner', function () {
    $order = Order::factory()->for($this->user)->pendingPayment()->create();

    $this->postJson('/v1/orders/'.$order->public_id.'/cancel', ['cancellationReason' => 'Changed my mind'])
        ->assertOk()
        ->assertJsonPath('status', 'cancelled')
        ->assertJsonPath('cancellationReason', 'Changed my mind');

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->fresh()->statusHistory()->latest('id')->first()->to_status)->toBe(OrderStatus::Cancelled);
});

test('an already delivered order cannot be cancelled', function () {
    $order = Order::factory()->for($this->user)->create(['status' => OrderStatus::Delivered]);

    $this->postJson('/v1/orders/'.$order->public_id.'/cancel', ['cancellationReason' => 'Too late'])
        ->assertStatus(409)
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/invalid-status-transition');
});

test('an already cancelled order cannot be cancelled again', function () {
    $order = Order::factory()->for($this->user)->cancelled()->create();

    $this->postJson('/v1/orders/'.$order->public_id.'/cancel', ['cancellationReason' => 'Again'])
        ->assertStatus(409);
});

test('cancelling another customer order is not found', function () {
    $theirs = Order::factory()->for($this->otherCustomer)->pendingPayment()->create();

    $this->postJson('/v1/orders/'.$theirs->public_id.'/cancel', ['cancellationReason' => 'Nope'])
        ->assertNotFound();
});
