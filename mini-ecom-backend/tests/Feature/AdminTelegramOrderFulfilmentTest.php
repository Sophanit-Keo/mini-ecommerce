<?php

use App\Actions\Checkout\CreateCheckoutQuote;
use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\DeliverySlot;
use App\Models\Inventory;
use App\Models\InventoryAdjustment;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['services.telegram.bot_token' => 'test-bot-token']);

    $this->admin = User::factory()->admin()->create();
    $this->customer = User::factory()->create();
});

/**
 * A cart with one line, belonging to the given user, ready to check out.
 */
function fulfilmentCartWithOneItem(User $user): Cart
{
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity_on_hand' => 50]);

    $cart = Cart::factory()->for($user)->create();
    CartItem::factory()->for($cart)->forProduct($product, 2)->create();

    return $cart;
}

/**
 * @return array<string, mixed>
 */
function fulfilmentCheckoutPayload(array $overrides = []): array
{
    return [
        'addressId' => $overrides['addressId'] ?? null,
        'deliverySlotId' => $overrides['deliverySlotId'] ?? null,
        'paymentMethod' => 'card',
        'quoteToken' => 'test-quote-token',
        'customerNote' => null,
        'idempotencyKey' => (string) Str::uuid7(),
        ...$overrides,
    ];
}

function fulfilmentQuotedCheckoutPayload(User $user, Address $address, DeliverySlot $slot, array $overrides = []): array
{
    $paymentMethod = PaymentMethod::from($overrides['paymentMethod'] ?? 'card');
    $cart = $user->carts()->where('status', CartStatus::Active)->with('items.product.inventory')->firstOrFail();
    $quote = app(CreateCheckoutQuote::class)->create($user, $cart, $address, $slot, $paymentMethod);

    return fulfilmentCheckoutPayload([
        'addressId' => $address->public_id,
        'deliverySlotId' => $slot->public_id,
        'paymentMethod' => $paymentMethod->value,
        'quoteToken' => $quote['quoteToken'],
        ...$overrides,
    ]);
}

// ---------------------------------------------------------------------------
// Linking a Telegram chat
// ---------------------------------------------------------------------------

test('an admin links their telegram chat id', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/v1/admin/telegram/link', ['chatId' => '123456789'])
        ->assertOk()
        ->assertJsonPath('telegramChatId', '123456789');

    expect($this->admin->fresh()->telegram_chat_id)->toBe('123456789');
});

test('a non-admin cannot link a telegram chat id', function () {
    $this->actingAs($this->customer, 'sanctum')
        ->postJson('/v1/admin/telegram/link', ['chatId' => '123456789'])
        ->assertForbidden()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/forbidden');
});

test('linking a telegram chat id requires authentication', function () {
    $this->postJson('/v1/admin/telegram/link', ['chatId' => '123456789'])
        ->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// JSON API order advancement
// ---------------------------------------------------------------------------

test('an admin cannot confirm a card order with pending payment', function () {
    $order = Order::factory()->pendingPayment()->create();

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/v1/admin/orders/'.$order->public_id.'/advance', ['action' => 'confirm'])
        ->assertStatus(409)
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/payment-not-authorized')
        ->assertJsonPath('paymentStatus', 'pending');

    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

test('an admin confirms an authorized card order', function () {
    $order = Order::factory()->pendingPayment()->create(['payment_status' => 'authorized']);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/v1/admin/orders/'.$order->public_id.'/advance', ['action' => 'confirm'])
        ->assertOk()
        ->assertJsonPath('status', 'confirmed');

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed)
        ->and($order->fresh()->confirmed_at)->not->toBeNull()
        ->and($order->fresh()->statusHistory()->latest('id')->first()->changed_by)->toBe($this->admin->id);
});

test('an admin can confirm a cash-on-delivery order without processor authorization', function () {
    $order = Order::factory()->pendingPayment()->create(['payment_method' => 'cash_on_delivery']);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/v1/admin/orders/'.$order->public_id.'/advance', ['action' => 'confirm'])
        ->assertOk()
        ->assertJsonPath('status', 'confirmed');

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
});

test('an admin steps an order through prepare, deliver and complete', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed]);

    $this->actingAs($this->admin, 'sanctum');

    $this->postJson('/v1/admin/orders/'.$order->public_id.'/advance', ['action' => 'prepare'])
        ->assertOk()->assertJsonPath('status', 'picking');

    $this->postJson('/v1/admin/orders/'.$order->public_id.'/advance', ['action' => 'deliver'])
        ->assertOk()->assertJsonPath('status', 'out_for_delivery');

    $this->postJson('/v1/admin/orders/'.$order->public_id.'/advance', ['action' => 'complete'])
        ->assertOk()->assertJsonPath('status', 'delivered');

    expect($order->fresh()->delivered_at)->not->toBeNull();
});

test('an admin rejects an order with a reason', function () {
    $order = Order::factory()->pendingPayment()->create();

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/v1/admin/orders/'.$order->public_id.'/advance', [
            'action' => 'reject',
            'reason' => 'Out of stock',
        ])->assertOk()->assertJsonPath('status', 'cancelled');

    expect($order->fresh()->cancellation_reason)->toBe('Out of stock');
});

test('admin rejection releases a checked-out order resources in the same transaction', function () {
    $cart = fulfilmentCartWithOneItem($this->customer);
    $address = Address::factory()->for($this->customer)->create();
    $slot = DeliverySlot::factory()->create();

    $this->actingAs($this->customer, 'sanctum')
        ->postJson('/v1/orders', fulfilmentQuotedCheckoutPayload($this->customer, $address, $slot))
        ->assertCreated();

    $order = Order::sole();
    $inventory = Inventory::findOrFail($cart->items()->value('product_id'));

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/v1/admin/orders/'.$order->public_id.'/advance', [
            'action' => 'reject',
            'reason' => 'Item recalled',
        ])
        ->assertOk()
        ->assertJsonPath('status', 'cancelled');

    expect($slot->fresh()->booked_count)->toBe(0)
        ->and($inventory->fresh()->quantity_reserved)->toBe('0.000')
        ->and($order->fresh()->reservation_expires_at)->toBeNull()
        ->and(InventoryAdjustment::where('reference_id', $order->id)->where('reason', 'order_released')->count())->toBe(1);
});

test('an illegal jump is rejected as a conflict', function () {
    $order = Order::factory()->pendingPayment()->create();

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/v1/admin/orders/'.$order->public_id.'/advance', ['action' => 'deliver'])
        ->assertStatus(409)
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/invalid-status-transition');
});

test('a non-admin cannot advance an order', function () {
    $order = Order::factory()->pendingPayment()->create();

    $this->actingAs($this->customer, 'sanctum')
        ->postJson('/v1/admin/orders/'.$order->public_id.'/advance', ['action' => 'confirm'])
        ->assertForbidden();
});

test('advancing an order requires authentication', function () {
    $order = Order::factory()->pendingPayment()->create();

    $this->postJson('/v1/admin/orders/'.$order->public_id.'/advance', ['action' => 'confirm'])
        ->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// Telegram webhook
// ---------------------------------------------------------------------------

function telegramCallback(string $chatId, string $data, string $callbackId = 'cbq-1'): array
{
    return [
        'callback_query' => [
            'id' => $callbackId,
            'from' => ['id' => (int) $chatId],
            'data' => $data,
        ],
    ];
}

test('the webhook rejects a request with a missing secret header', function () {
    config(['services.telegram.webhook_secret' => 'expected-secret']);
    $order = Order::factory()->pendingPayment()->create();

    $this->postJson('/v1/telegram/webhook', telegramCallback('1', "order:{$order->public_id}:confirm"))
        ->assertForbidden();
});

test('the webhook rejects a request with the wrong secret header', function () {
    config(['services.telegram.webhook_secret' => 'expected-secret']);
    $order = Order::factory()->pendingPayment()->create();

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'wrong-secret')
        ->postJson('/v1/telegram/webhook', telegramCallback('1', "order:{$order->public_id}:confirm"))
        ->assertForbidden();
});

test('the webhook advances an authorized order for a linked admin', function () {
    Http::fake();
    config(['services.telegram.webhook_secret' => 'expected-secret']);
    $this->admin->update(['telegram_chat_id' => '555']);
    $order = Order::factory()->pendingPayment()->create(['payment_status' => 'authorized']);

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'expected-secret')
        ->postJson('/v1/telegram/webhook', telegramCallback('555', "order:{$order->public_id}:confirm"))
        ->assertNoContent();

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed)
        ->and($order->fresh()->statusHistory()->latest('id')->first()->changed_by)->toBe($this->admin->id);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'answerCallbackQuery'));
});

test('the webhook ignores a callback from an unlinked chat id', function () {
    Http::fake();
    config(['services.telegram.webhook_secret' => 'expected-secret']);
    $order = Order::factory()->pendingPayment()->create();

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'expected-secret')
        ->postJson('/v1/telegram/webhook', telegramCallback('999999', "order:{$order->public_id}:confirm"))
        ->assertNoContent();

    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

// ---------------------------------------------------------------------------
// New-order notification
// ---------------------------------------------------------------------------

test('placing an order notifies linked admins on telegram', function () {
    Http::fake();
    $this->admin->update(['telegram_chat_id' => '777']);

    fulfilmentCartWithOneItem($this->customer);
    $address = Address::factory()->for($this->customer)->create();
    $slot = DeliverySlot::factory()->create();

    $this->actingAs($this->customer, 'sanctum')
        ->postJson('/v1/orders', fulfilmentQuotedCheckoutPayload($this->customer, $address, $slot))->assertCreated();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
        && $request->data()['chat_id'] === '777');
});

test('placing an order still succeeds if telegram is unreachable', function () {
    Http::fake(['*' => Http::response(status: 500)]);
    $this->admin->update(['telegram_chat_id' => '777']);

    fulfilmentCartWithOneItem($this->customer);
    $address = Address::factory()->for($this->customer)->create();
    $slot = DeliverySlot::factory()->create();

    $this->actingAs($this->customer, 'sanctum')
        ->postJson('/v1/orders', fulfilmentQuotedCheckoutPayload($this->customer, $address, $slot))->assertCreated();

    expect(Order::count())->toBe(1);
});
