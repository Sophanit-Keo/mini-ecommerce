<?php

use App\Enums\CartStatus;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// The schema leans on ten stored/virtual generated columns to make invariants structural
// rather than aspirational. This proves each one actually computes.

test('effective_price gives both pricing shapes a comparable shelf price', function () {
    $bagOfGrapes = Product::factory()->create(['price' => 5.49]);
    $looseAvocado = Product::factory()->weight()->create([
        'price_per_kg' => 8.95,
        'average_weight_kg' => 0.200,
    ]);

    expect($bagOfGrapes->refresh()->effective_price)->toBe('5.49')
        ->and($looseAvocado->refresh()->effective_price)->toBe('1.79');
});

test('cheapest-first ordering is correct across pricing shapes', function () {
    // Design-review finding R-01. Sorting on `price` ranked the $5.49 bag above the $1.79
    // avocado, because `price` is NULL for every weight-priced product.
    $expensive = Product::factory()->create(['name' => 'Grape Bag', 'price' => 5.49]);
    $cheap = Product::factory()->weight()->create([
        'name' => 'Loose Avocado',
        'price_per_kg' => 8.95,
        'average_weight_kg' => 0.200,
    ]);

    expect(Product::orderBy('effective_price')->pluck('name')->all())
        ->toBe([$cheap->name, $expensive->name]);
});

test('quantity_available is on hand minus reserved', function () {
    $inventory = Inventory::factory()->create([
        'quantity_on_hand' => 12.500,
        'quantity_reserved' => 2.100,
    ]);

    expect($inventory->refresh()->quantity_available)->toBe('10.400');
});

test('generated columns are not mass assignable', function () {
    // MySQL refuses writes to them outright, so they are kept out of every fillable list
    // rather than being allowed through and failing at the database.
    $product = Product::factory()->create();
    $product->fill(['effective_price' => '0.01', 'sku_active' => 'HIJACKED']);

    expect($product->isDirty('effective_price'))->toBeFalse()
        ->and($product->isDirty('sku_active'))->toBeFalse();
});

test('email_active collapses to null on soft delete', function () {
    $user = User::factory()->create(['email' => 'live@example.com']);

    expect(DB::table('users')->where('id', $user->id)->value('email_active'))->toBe('live@example.com');

    $user->delete();

    expect(DB::table('users')->where('id', $user->id)->value('email_active'))->toBeNull();
});

test('slug_active collapses to null on soft delete for categories and products', function () {
    $category = Category::factory()->create(['slug' => 'live-category']);
    $product = Product::factory()->create(['slug' => 'live-product', 'sku' => 'SKU-LIVE-1']);

    $category->delete();
    $product->delete();

    expect(DB::table('categories')->where('id', $category->id)->value('slug_active'))->toBeNull()
        ->and(DB::table('products')->where('id', $product->id)->value('slug_active'))->toBeNull()
        ->and(DB::table('products')->where('id', $product->id)->value('sku_active'))->toBeNull();
});

test('default_for_user holds the owner only while the address is default and live', function () {
    $address = Address::factory()->default()->create();
    $column = fn () => DB::table('addresses')->where('id', $address->id)->value('default_for_user');

    expect($column())->toEqual($address->user_id);

    $address->update(['is_default' => false]);
    expect($column())->toBeNull();

    $address->update(['is_default' => true]);
    expect($column())->toEqual($address->user_id);

    $address->delete();
    expect($column())->toBeNull();
});

test('active_for_user and active_for_guest hold the owner only while the cart is active', function () {
    $cart = Cart::factory()->create();
    $guestCart = Cart::factory()->guest('guest-token-abc')->create();

    $userColumn = fn () => DB::table('carts')->where('id', $cart->id)->value('active_for_user');
    $guestColumn = fn () => DB::table('carts')->where('id', $guestCart->id)->value('active_for_guest');

    expect($userColumn())->toEqual($cart->user_id)
        ->and($guestColumn())->toBe('guest-token-abc');

    $cart->update(['status' => CartStatus::Converted]);
    $guestCart->update(['status' => CartStatus::Merged]);

    expect($userColumn())->toBeNull()
        ->and($guestColumn())->toBeNull();
});

test('primary_for_product enforces one primary image per product', function () {
    $product = Product::factory()->create();
    ProductImage::factory()->for($product)->primary()->create();

    expect(fn () => ProductImage::factory()->for($product)->primary()->create(['position' => 1]))
        ->toThrow(QueryException::class, 'uq_product_images_primary');
});

test('a public id round-trips through BINARY(16) as a UUID string', function () {
    $user = User::factory()->create();

    // strlen, not mb_strlen — the column holds 16 raw bytes, not 16 characters.
    $stored = DB::table('users')->where('id', $user->id)->value('public_id');

    expect($user->public_id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/')
        ->and(strlen($stored))->toBe(16)
        ->and(bin2hex($stored))->toBe(str_replace('-', '', $user->public_id))
        ->and(User::wherePublicId($user->public_id)->first()->id)->toBe($user->id);
});

test('a malformed public id resolves to nothing rather than erroring', function () {
    User::factory()->create();

    expect(User::wherePublicId('not-a-uuid')->first())->toBeNull();
});
