<?php

use App\Models\Product;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->otherCustomer = User::factory()->create();

    $this->actingAs($this->user, 'sanctum');
});

// ---------------------------------------------------------------------------
// Adding and listing
// ---------------------------------------------------------------------------

test('a product is added to the wishlist and returned in camelCase', function () {
    $product = Product::factory()->create();

    $response = $this->postJson('/v1/wishlist/items', ['productId' => $product->public_id]);

    $response->assertCreated()
        ->assertJsonPath('productId', $product->public_id)
        ->assertJsonPath('product.id', $product->public_id);

    expect($response->json('id'))->toMatch('/^[0-9a-f-]{36}$/')
        ->and(WishlistItem::count())->toBe(1)
        ->and(WishlistItem::first()->user_id)->toBe($this->user->id);
});

test('the list contains only the caller own wishlist items, product eager loaded', function () {
    WishlistItem::factory()->for($this->user)->create();
    WishlistItem::factory()->for($this->otherCustomer)->count(3)->create();

    DB::enableQueryLog();

    $response = $this->getJson('/v1/wishlist')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // One additional fixed query refreshes account status in `account.active`; the wishlist
    // items and their relations remain eagerly loaded rather than scaling with row count.
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(6);

    DB::disableQueryLog();

    expect($response->json('data.0.product.name'))->not->toBeNull();
});

test('most recently added product is listed first', function () {
    $older = WishlistItem::factory()->for($this->user)->create(['created_at' => now()->subDay()]);
    $newer = WishlistItem::factory()->for($this->user)->create(['created_at' => now()]);

    $this->getJson('/v1/wishlist')
        ->assertOk()
        ->assertJsonPath('data.0.id', $newer->public_id)
        ->assertJsonPath('data.1.id', $older->public_id);
});

// ---------------------------------------------------------------------------
// Idempotent add
// ---------------------------------------------------------------------------

test('adding an already-wishlisted product is idempotent, not a conflict', function () {
    $product = Product::factory()->create();

    $this->postJson('/v1/wishlist/items', ['productId' => $product->public_id])->assertCreated();

    $second = $this->postJson('/v1/wishlist/items', ['productId' => $product->public_id]);

    $second->assertOk()
        ->assertJsonPath('productId', $product->public_id);

    expect(WishlistItem::count())->toBe(1);
});

test('adding a nonexistent product is a 404', function () {
    $this->postJson('/v1/wishlist/items', ['productId' => (string) Str::uuid7()])
        ->assertNotFound()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/not-found');
});

test('productId is required and must be a uuid', function () {
    $this->postJson('/v1/wishlist/items', ['productId' => 'not-a-uuid'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.field', 'productId');
});

// ---------------------------------------------------------------------------
// Ownership
// ---------------------------------------------------------------------------

test('another customer wishlist item is indistinguishable from one that does not exist', function () {
    $theirs = WishlistItem::factory()->for($this->otherCustomer)->create();

    $missing = $this->deleteJson('/v1/wishlist/items/'.Str::uuid7());
    $notMine = $this->deleteJson('/v1/wishlist/items/'.$theirs->public_id);

    $missing->assertNotFound();
    $notMine->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/not-found');

    expect($missing->json('detail'))->toBe($notMine->json('detail'));
});

test('deleting another customer wishlist item leaves it untouched', function () {
    $theirs = WishlistItem::factory()->for($this->otherCustomer)->create();

    $this->deleteJson('/v1/wishlist/items/'.$theirs->public_id)->assertNotFound();

    expect(WishlistItem::query()->whereKey($theirs->id)->exists())->toBeTrue();
});

test('a malformed wishlist item id is a 404 rather than an error', function () {
    $this->deleteJson('/v1/wishlist/items/not-a-uuid')->assertNotFound();
});

// ---------------------------------------------------------------------------
// Removal
// ---------------------------------------------------------------------------

test('a wishlist item is removed', function () {
    $item = WishlistItem::factory()->for($this->user)->create();

    $this->deleteJson('/v1/wishlist/items/'.$item->public_id)->assertNoContent();

    expect(WishlistItem::query()->whereKey($item->id)->exists())->toBeFalse();
});

test('removing a nonexistent wishlist item is a 404', function () {
    $this->deleteJson('/v1/wishlist/items/'.Str::uuid7())->assertNotFound();
});

// ---------------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------------

test('every wishlist operation requires authentication', function (string $method, string $path) {
    $this->app['auth']->forgetGuards();

    $this->json($method, $path, ['productId' => (string) Str::uuid7()])
        ->assertUnauthorized()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/unauthorized');
})->with([
    ['GET', '/v1/wishlist'],
    ['POST', '/v1/wishlist/items'],
    ['DELETE', '/v1/wishlist/items/0192f3a1-0000-7000-8002-000000000001'],
]);
