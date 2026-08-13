<?php

use App\Enums\InventoryAdjustmentReason;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\SubstitutionPreference;
use App\Models\DeliverySlot;
use App\Models\Inventory;
use App\Models\InventoryAdjustment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->customer = User::factory()->create();
    $this->actingAs($this->admin);
});

/** @return array{order: Order, item: OrderItem, product: Product, inventory: Inventory} */
function fulfillmentOrder(User $customer, ?Product $product = null, string $preference = 'similar'): array
{
    $product ??= Product::factory()->create(['price' => '2.00']);
    $inventory = Inventory::factory()->for($product)->create([
        'quantity_on_hand' => '10.000',
        'quantity_reserved' => '2.000',
    ]);
    $slot = DeliverySlot::factory()->create(['booked_count' => 1]);
    $order = Order::factory()->for($customer)->state([
        'delivery_slot_id' => $slot->id,
        'status' => OrderStatus::Picking,
        'payment_status' => PaymentStatus::Authorized,
        'payment_method' => PaymentMethod::Bakong,
        'subtotal_estimated' => '4.00',
        'delivery_fee' => '0.00',
        'discount_total' => '0.00',
        'tax_estimated' => '0.00',
        'total_estimated' => '4.00',
        'authorized_amount' => '4.00',
        'reservation_expires_at' => null,
        'resources_reserved_at' => now(),
    ])->create();
    $item = OrderItem::factory()->for($order)->create([
        'product_id' => $product->id,
        'product_name' => $product->name,
        'product_sku' => $product->sku,
        'brand' => $product->brand,
        'unit_price' => '2.00',
        'ordered_quantity' => '2.000',
        'estimated_line_total' => '4.00',
        'substitution_preference' => $preference,
    ]);

    return compact('order', 'item', 'product', 'inventory');
}

test('an admin picks and finalizes an authorized order with exact-once inventory consumption', function () {
    ['order' => $order, 'item' => $item, 'inventory' => $inventory] = fulfillmentOrder($this->customer);

    $this->postJson("/v1/admin/orders/{$order->public_id}/items/{$item->public_id}/pick", [
        'quantity' => '2.000',
    ])->assertOk()
        ->assertJsonPath('items.0.status', 'picked')
        ->assertJsonPath('items.0.finalLineTotal', '4.00');

    $this->postJson("/v1/admin/orders/{$order->public_id}/finalize")
        ->assertOk()
        ->assertJsonPath('status', 'packed')
        ->assertJsonPath('totalFinal', '4.00')
        ->assertJsonPath('paymentStatus', 'captured')
        ->assertJsonPath('reconciliation.status', 'settled')
        ->assertJsonPath('reconciliation.delta', null);

    $order->refresh();
    $inventory->refresh();

    expect($order->fulfilled_at)->not->toBeNull()
        ->and($inventory->quantity_on_hand)->toBe('8.000')
        ->and($inventory->quantity_reserved)->toBe('0.000')
        ->and(InventoryAdjustment::where('reference_id', $order->id)
            ->where('reason', InventoryAdjustmentReason::OrderFulfilled)->count())->toBe(1);

    // Repeating the terminal command cannot consume inventory a second time.
    $this->postJson("/v1/admin/orders/{$order->public_id}/finalize")
        ->assertConflict()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/invalid-status-transition');

    $this->postJson("/v1/admin/orders/{$order->public_id}/advance", ['action' => 'dispatch'])
        ->assertOk()
        ->assertJsonPath('status', 'out_for_delivery');
});

test('finalization rejects unresolved lines and non-admin users cannot issue fulfilment commands', function () {
    ['order' => $order, 'item' => $item] = fulfillmentOrder($this->customer);

    $this->postJson("/v1/admin/orders/{$order->public_id}/finalize")
        ->assertConflict()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/items-unresolved');

    $this->actingAs($this->customer)
        ->postJson("/v1/admin/orders/{$order->public_id}/items/{$item->public_id}/pick", ['quantity' => '2.000'])
        ->assertForbidden();
});

test('substitution honors customer approval and blocks dispatch until a payment difference is reconciled', function () {
    $original = Product::factory()->create(['price' => '2.00']);
    $substitute = Product::factory()->create(['price' => '3.00']);
    Inventory::factory()->for($substitute)->create([
        'quantity_on_hand' => '10.000',
        'quantity_reserved' => '0.000',
    ]);
    ['order' => $order, 'item' => $item, 'inventory' => $originalInventory] = fulfillmentOrder(
        $this->customer,
        $original,
        SubstitutionPreference::ContactMe->value,
    );

    $payload = ['productId' => $substitute->public_id, 'quantity' => '2.000', 'customerApproved' => false];
    $this->postJson("/v1/admin/orders/{$order->public_id}/items/{$item->public_id}/substitutions", $payload)
        ->assertConflict()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/substitution-approval-required');

    $this->postJson("/v1/admin/orders/{$order->public_id}/items/{$item->public_id}/substitutions", [
        ...$payload,
        'customerApproved' => true,
        'reason' => 'Requested brand unavailable',
    ])->assertOk()
        ->assertJsonPath('items.0.status', OrderItemStatus::Substituted->value)
        ->assertJsonPath('items.0.finalLineTotal', '6.00');

    $this->postJson("/v1/admin/orders/{$order->public_id}/finalize")
        ->assertOk()
        ->assertJsonPath('status', 'packed')
        ->assertJsonPath('totalFinal', '6.00')
        ->assertJsonPath('reconciliation.status', ReconciliationStatus::AmountDue->value)
        ->assertJsonPath('reconciliation.delta', '2.00');

    $originalInventory->refresh();
    $substituteInventory = Inventory::where('product_id', $substitute->id)->firstOrFail();
    expect($originalInventory->quantity_on_hand)->toBe('10.000')
        ->and($originalInventory->quantity_reserved)->toBe('0.000')
        ->and($substituteInventory->quantity_on_hand)->toBe('8.000')
        ->and($substituteInventory->quantity_reserved)->toBe('0.000');

    $this->postJson("/v1/admin/orders/{$order->public_id}/advance", ['action' => 'dispatch'])
        ->assertConflict()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/reconciliation-required');

    $this->postJson("/v1/admin/orders/{$order->public_id}/reconcile", ['reference' => 'BAKONG-ADJUST-0001'])
        ->assertOk()
        ->assertJsonPath('reconciliation.status', ReconciliationStatus::Settled->value)
        ->assertJsonPath('paymentStatus', PaymentStatus::Captured->value);

    $this->postJson("/v1/admin/orders/{$order->public_id}/advance", ['action' => 'dispatch'])
        ->assertOk()
        ->assertJsonPath('status', OrderStatus::OutForDelivery->value);
});

test('finalizing an unavailable line releases its reservation without consuming stock', function () {
    ['order' => $order, 'item' => $item, 'inventory' => $inventory] = fulfillmentOrder($this->customer);

    $this->postJson("/v1/admin/orders/{$order->public_id}/items/{$item->public_id}/unavailable", [
        'reason' => 'Item was unavailable on the shelf',
    ])->assertOk();

    $this->postJson("/v1/admin/orders/{$order->public_id}/finalize")
        ->assertOk()
        ->assertJsonPath('totalFinal', '0.00')
        ->assertJsonPath('reconciliation.status', ReconciliationStatus::RefundDue->value)
        ->assertJsonPath('reconciliation.delta', '-4.00');

    $inventory->refresh();
    expect($inventory->quantity_on_hand)->toBe('10.000')
        ->and($inventory->quantity_reserved)->toBe('0.000')
        ->and(InventoryAdjustment::where('reference_id', $order->id)
            ->where('reason', InventoryAdjustmentReason::OrderFulfilled)->count())->toBe(0)
        ->and(InventoryAdjustment::where('reference_id', $order->id)
            ->where('reason', InventoryAdjustmentReason::OrderReleased)->count())->toBe(1);
});

test('a customer preference of none requires an unavailable outcome rather than a substitute', function () {
    $substitute = Product::factory()->create(['price' => '3.00']);
    Inventory::factory()->for($substitute)->create();
    ['order' => $order, 'item' => $item] = fulfillmentOrder(
        $this->customer,
        null,
        SubstitutionPreference::None->value,
    );

    $this->postJson("/v1/admin/orders/{$order->public_id}/items/{$item->public_id}/substitutions", [
        'productId' => $substitute->public_id,
        'quantity' => '2.000',
        'customerApproved' => true,
    ])->assertConflict()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/substitution-refused');

    $this->postJson("/v1/admin/orders/{$order->public_id}/items/{$item->public_id}/unavailable", [
        'reason' => 'No acceptable replacement in stock',
    ])->assertOk()
        ->assertJsonPath('items.0.status', OrderItemStatus::Unavailable->value);
});
