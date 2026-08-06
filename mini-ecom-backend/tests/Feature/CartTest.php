<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->otherCustomer = User::factory()->create();

    $this->actingAs($this->user, 'sanctum');
});

function inStockProduct(array $overrides = []): Product
{
    $product = Product::factory()->create($overrides);
    Inventory::factory()->for($product)->create(['quantity_on_hand' => 50]);

    return $product;
}

// ---------------------------------------------------------------------------
// Reading / lazy creation
// ---------------------------------------------------------------------------

test('GET cart lazily creates an active cart for the user', function () {
    expect(Cart::count())->toBe(0);

    $this->getJson('/v1/cart')
        ->assertOk()
        ->assertJsonPath('status', 'active')
        ->assertJsonCount(0, 'items');

    expect(Cart::count())->toBe(1)
        ->and(Cart::first()->user_id)->toBe($this->user->id);
});

test('GET cart is idempotent and reuses the existing active cart', function () {
    $this->getJson('/v1/cart')->assertOk();
    $this->getJson('/v1/cart')->assertOk();

    expect(Cart::count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Adding items
// ---------------------------------------------------------------------------

test('adding a product to the cart creates a line with a price snapshot', function () {
    $product = inStockProduct(['price' => 4.49]);

    $response = $this->postJson('/v1/cart/items', ['productId' => $product->public_id, 'quantity' => 2]);

    $response->assertCreated()
        ->assertJsonPath('quantity', '2.000')
        ->assertJsonPath('unitPriceSnapshot', '4.49')
        ->assertJsonPath('lineTotal', '8.98');

    expect(CartItem::count())->toBe(1);
});

test('adding the same product twice increases quantity instead of duplicating the row', function () {
    $product = inStockProduct();

    $this->postJson('/v1/cart/items', ['productId' => $product->public_id, 'quantity' => 2])->assertCreated();
    $this->postJson('/v1/cart/items', ['productId' => $product->public_id, 'quantity' => 3])->assertCreated();

    expect(CartItem::count())->toBe(1)
        ->and(CartItem::first()->quantity)->toEqual('5.000');
});

test('adding an unknown or inactive product is not found', function () {
    $inactive = Product::factory()->inactive()->create();

    $this->postJson('/v1/cart/items', ['productId' => $inactive->public_id, 'quantity' => 1])
        ->assertNotFound();

    $this->postJson('/v1/cart/items', ['productId' => (string) Str::uuid7(), 'quantity' => 1])
        ->assertNotFound();
});

test('quantity outside the product order bounds is rejected', function () {
    $product = inStockProduct(['min_order_quantity' => 1, 'max_order_quantity' => 5]);

    $this->postJson('/v1/cart/items', ['productId' => $product->public_id, 'quantity' => 10])
        ->assertUnprocessable()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/quantity-out-of-range');
});

test('quantity beyond available stock is rejected', function () {
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity_on_hand' => 2]);

    $this->postJson('/v1/cart/items', ['productId' => $product->public_id, 'quantity' => 10])
        ->assertUnprocessable()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/insufficient-stock');
});

// ---------------------------------------------------------------------------
// Updating / removing items, and IDOR
// ---------------------------------------------------------------------------

test('a cart item quantity and note are updated', function () {
    $product = inStockProduct();
    $this->postJson('/v1/cart/items', ['productId' => $product->public_id, 'quantity' => 1])->assertCreated();
    $itemId = $this->getJson('/v1/cart')->json('items.0.id');

    $this->patchJson('/v1/cart/items/'.$itemId, ['quantity' => 4, 'note' => 'Ripe please'])
        ->assertOk()
        ->assertJsonPath('quantity', '4.000')
        ->assertJsonPath('note', 'Ripe please');
});

test('a cart item is removed', function () {
    $product = inStockProduct();
    $this->postJson('/v1/cart/items', ['productId' => $product->public_id, 'quantity' => 1])->assertCreated();
    $itemId = $this->getJson('/v1/cart')->json('items.0.id');

    $this->deleteJson('/v1/cart/items/'.$itemId)->assertNoContent();

    expect(CartItem::count())->toBe(0);
});

test('another customer cart item is indistinguishable from one that does not exist', function (string $method) {
    $theirCart = Cart::factory()->for($this->otherCustomer)->create();
    $theirItem = CartItem::factory()->for($theirCart)->create();

    $this->json($method, '/v1/cart/items/'.$theirItem->public_id, ['quantity' => 1])
        ->assertNotFound();
})->with(['PATCH', 'DELETE']);

test('cart operations require authentication', function () {
    $this->app['auth']->forgetGuards();

    $this->getJson('/v1/cart')->assertUnauthorized();
});
