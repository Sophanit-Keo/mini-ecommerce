<?php

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\DeliverySlot;
use App\Models\Inventory;
use App\Models\InventoryAdjustment;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;

test('expired unpaid checkout reservations release stock and capacity exactly once', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $inventory = Inventory::factory()->for($product)->create(['quantity_on_hand' => 10]);
    $cart = Cart::factory()->for($user)->create();
    CartItem::factory()->for($cart)->forProduct($product, 2)->create();
    $address = Address::factory()->for($user)->create();
    $slot = DeliverySlot::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/v1/orders', [
            'addressId' => $address->public_id,
            'deliverySlotId' => $slot->public_id,
            'paymentMethod' => 'card',
            'idempotencyKey' => (string) Str::uuid7(),
        ])
        ->assertCreated();

    $order = Order::sole();
    $order->update(['reservation_expires_at' => now()->subMinute()]);

    $this->artisan('orders:release-expired-reservations')
        ->expectsOutput('Released 1 expired reservation(s).')
        ->assertExitCode(0);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Failed)
        ->and($order->fresh()->reservation_expires_at)->toBeNull()
        ->and($cart->fresh()->status)->toBe(CartStatus::Converted)
        ->and($slot->fresh()->booked_count)->toBe(0)
        ->and($inventory->fresh()->quantity_reserved)->toBe('0.000')
        ->and(InventoryAdjustment::where('reference_id', $order->id)->where('reason', 'order_released')->count())->toBe(1);

    // An overlapping/retried sweep is a no-op after the durable release marker is cleared.
    $this->artisan('orders:release-expired-reservations')
        ->expectsOutput('Released 0 expired reservation(s).')
        ->assertExitCode(0);

    expect($slot->fresh()->booked_count)->toBe(0)
        ->and($inventory->fresh()->quantity_reserved)->toBe('0.000')
        ->and(InventoryAdjustment::where('reference_id', $order->id)->where('reason', 'order_released')->count())->toBe(1);
});
