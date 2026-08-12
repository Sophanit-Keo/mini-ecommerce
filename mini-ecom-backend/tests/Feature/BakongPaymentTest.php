<?php

use App\Actions\Checkout\CreateCheckoutQuote;
use App\Enums\CartStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\DeliverySlot;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config([
        'bakong.enabled' => true,
        'bakong.api_token' => 'test-bakong-token',
        'bakong.account_id' => 'grocerly@nbcq',
        'bakong.merchant_name' => 'Grocerly',
        'bakong.merchant_city' => 'Phnom Penh',
        'bakong.profile_type' => 'individual',
        'bakong.currency' => 'USD',
        'bakong.payment_ttl_minutes' => 20,
    ]);

    $this->customer = User::factory()->create();
    $this->otherCustomer = User::factory()->create();
    $this->actingAs($this->customer, 'sanctum');
});

function bakongCart(User $user): Cart
{
    $product = Product::factory()->create(['price' => '5.00']);
    Inventory::factory()->for($product)->create(['quantity_on_hand' => 10]);
    $cart = Cart::factory()->for($user)->create(['currency' => 'USD']);
    CartItem::factory()->for($cart)->forProduct($product, 2)->create();

    return $cart;
}

/**
 * @return array<string, mixed>
 */
function bakongQuote(User $user, Cart $cart, Address $address, DeliverySlot $slot): array
{
    return app(CreateCheckoutQuote::class)->create(
        $user,
        $cart->load('items.product.inventory'),
        $address,
        $slot->fresh(),
        PaymentMethod::Bakong,
    );
}

/**
 * @return array<string, mixed>
 */
function bakongOrderPayload(Address $address, DeliverySlot $slot, array $quote): array
{
    return [
        'addressId' => $address->public_id,
        'deliverySlotId' => $slot->public_id,
        'paymentMethod' => 'bakong',
        'quoteToken' => $quote['quoteToken'],
        'idempotencyKey' => (string) Str::uuid7(),
    ];
}

test('Bakong checkout quotes are unavailable until the merchant configuration is complete', function () {
    config(['bakong.enabled' => false]);

    bakongCart($this->customer);
    $address = Address::factory()->for($this->customer)->create();
    $slot = DeliverySlot::factory()->create();

    $this->postJson('/v1/checkout/quote', [
        'addressId' => $address->public_id,
        'deliverySlotId' => $slot->public_id,
        'paymentMethod' => 'bakong',
    ])->assertStatus(503)
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/payment-unavailable');
});

test('a Bakong checkout creates a dynamic, server-bound payment attempt', function () {
    $cart = bakongCart($this->customer);
    $address = Address::factory()->for($this->customer)->create();
    $slot = DeliverySlot::factory()->create();
    $quote = bakongQuote($this->customer, $cart, $address, $slot);

    $orderResponse = $this->postJson('/v1/orders', bakongOrderPayload($address, $slot, $quote))
        ->assertCreated()
        ->assertJsonPath('payment.method', 'bakong')
        ->assertJsonPath('payment.status', 'pending');

    $order = Order::wherePublicId($orderResponse->json('id'))->firstOrFail();

    $first = $this->postJson('/v1/orders/'.$order->public_id.'/payments/bakong')
        ->assertCreated()
        ->assertJsonPath('provider', 'bakong')
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('amount', '13.99');

    $second = $this->postJson('/v1/orders/'.$order->public_id.'/payments/bakong')->assertCreated();

    expect($first->json('id'))->toBe($second->json('id'))
        ->and($first->json('khqrPayload'))->toStartWith('000201010212')
        ->and(PaymentAttempt::count())->toBe(1)
        ->and(PaymentAttempt::sole()->provider_reference)->toHaveLength(32);
});

test('only a confirmed Bakong MD5 lookup can authorize the order payment', function () {
    $cart = bakongCart($this->customer);
    $address = Address::factory()->for($this->customer)->create();
    $slot = DeliverySlot::factory()->create();
    $quote = bakongQuote($this->customer, $cart, $address, $slot);
    $created = $this->postJson('/v1/orders', bakongOrderPayload($address, $slot, $quote))->assertCreated();
    $order = Order::wherePublicId($created->json('id'))->firstOrFail();
    $this->postJson('/v1/orders/'.$order->public_id.'/payments/bakong')->assertCreated();

    Http::fake([
        'https://api-bakong.nbc.gov.kh/v1/check_transaction_by_md5' => Http::response([
            'responseCode' => 0,
            'data' => ['hash' => str_repeat('a', 64)],
        ]),
    ]);

    $this->postJson('/v1/orders/'.$order->public_id.'/payments/bakong/verify')
        ->assertOk()
        ->assertJsonPath('status', 'verified');

    $order->refresh();
    $attempt = PaymentAttempt::sole();

    expect($order->payment_status)->toBe(PaymentStatus::Authorized)
        ->and($order->authorized_amount)->toBe('13.99')
        ->and($order->reservation_expires_at)->toBeNull()
        ->and($attempt->status)->toBe(PaymentAttemptStatus::Verified)
        ->and($attempt->provider_transaction_hash)->toBe(str_repeat('a', 64));

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-bakong-token')
        && $request['md5'] === $attempt->provider_reference);

    $this->postJson('/v1/orders/'.$order->public_id.'/payments/bakong')
        ->assertCreated()
        ->assertJsonPath('id', $attempt->public_id)
        ->assertJsonPath('status', 'verified');

    expect(PaymentAttempt::count())->toBe(1);
});

test('a Bakong verification remains pending when the provider has no completed payment', function () {
    $cart = bakongCart($this->customer);
    $address = Address::factory()->for($this->customer)->create();
    $slot = DeliverySlot::factory()->create();
    $quote = bakongQuote($this->customer, $cart, $address, $slot);
    $created = $this->postJson('/v1/orders', bakongOrderPayload($address, $slot, $quote))->assertCreated();
    $order = Order::wherePublicId($created->json('id'))->firstOrFail();
    $this->postJson('/v1/orders/'.$order->public_id.'/payments/bakong')->assertCreated();

    Http::fake([
        'https://api-bakong.nbc.gov.kh/v1/check_transaction_by_md5' => Http::response([
            'responseCode' => 1,
            'responseMessage' => 'Transaction not found',
            'data' => null,
        ]),
    ]);

    $this->postJson('/v1/orders/'.$order->public_id.'/payments/bakong/verify')
        ->assertStatus(409)
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/payment-pending');

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Pending)
        ->and(PaymentAttempt::sole()->status)->toBe(PaymentAttemptStatus::Pending)
        ->and(PaymentAttempt::sole()->verification_count)->toBe(1);
});

test('a customer cannot start or verify another customer Bakong payment', function () {
    $cart = bakongCart($this->customer);
    $address = Address::factory()->for($this->customer)->create();
    $slot = DeliverySlot::factory()->create();
    $quote = bakongQuote($this->customer, $cart, $address, $slot);
    $created = $this->postJson('/v1/orders', bakongOrderPayload($address, $slot, $quote))->assertCreated();

    $this->actingAs($this->otherCustomer, 'sanctum')
        ->postJson('/v1/orders/'.$created->json('id').'/payments/bakong')
        ->assertNotFound();
});

test('an expired failed payment restores a live cart exactly once after product revalidation', function () {
    $product = Product::factory()->create(['price' => '7.50']);
    Inventory::factory()->for($product)->create(['quantity_on_hand' => 10]);
    $order = Order::factory()->for($this->customer)->cancelled()->create([
        'payment_method' => PaymentMethod::Bakong,
        'payment_status' => PaymentStatus::Failed,
        'currency' => 'USD',
    ]);
    OrderItem::factory()->for($order)->create([
        'product_id' => $product->id,
        'ordered_quantity' => '2.000',
    ]);

    $first = $this->postJson('/v1/orders/'.$order->public_id.'/restore-cart')
        ->assertOk()
        ->assertJsonPath('status', CartStatus::Active->value)
        ->assertJsonCount(1, 'items');
    $second = $this->postJson('/v1/orders/'.$order->public_id.'/restore-cart')->assertOk();

    expect($first->json('id'))->toBe($second->json('id'))
        ->and($order->fresh()->cart_restored_at)->not->toBeNull()
        ->and(CartItem::where('cart_id', Cart::sole()->id)->count())->toBe(1)
        ->and(CartItem::sole()->quantity)->toBe('2.000');
});
