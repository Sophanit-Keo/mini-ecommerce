<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->customer = User::factory()->create();
});

test('an admin updates a product and the change is persisted', function () {
    $product = Product::factory()->create(['name' => 'Original Name']);

    $this->actingAs($this->admin, 'sanctum')
        ->patchJson('/v1/products/'.$product->public_id, ['name' => 'Updated Name'])
        ->assertOk()
        ->assertJsonPath('name', 'Updated Name');

    expect($product->fresh()->name)->toBe('Updated Name');
});

test('an admin can update pricing within the same sold_by shape', function () {
    $product = Product::factory()->create(['price' => 5.00]);

    $this->actingAs($this->admin, 'sanctum')
        ->patchJson('/v1/products/'.$product->public_id, ['price' => 7.50])
        ->assertOk()
        ->assertJsonPath('price', '7.50');
});

test('a non-admin authenticated customer is forbidden from updating a product', function () {
    $product = Product::factory()->create();

    $this->actingAs($this->customer, 'sanctum')
        ->patchJson('/v1/products/'.$product->public_id, ['name' => 'Hacked Name'])
        ->assertForbidden()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/forbidden');

    expect($product->fresh()->name)->not->toBe('Hacked Name');
});

test('updating a product requires authentication', function () {
    $product = Product::factory()->create();

    $this->patchJson('/v1/products/'.$product->public_id, ['name' => 'Anon Name'])
        ->assertUnauthorized()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/unauthorized');
});

test('updating a nonexistent product is a 404', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->patchJson('/v1/products/'.Str::uuid7(), ['name' => 'Ghost'])
        ->assertNotFound()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/not-found');
});

test('an invalid payload is rejected with 422', function () {
    $product = Product::factory()->create();

    $this->actingAs($this->admin, 'sanctum')
        ->patchJson('/v1/products/'.$product->public_id, ['price' => -5])
        ->assertUnprocessable()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/validation-failed');
});

test('setting a unit product to weight without weight columns violates the pricing shape', function () {
    $product = Product::factory()->create();

    $this->actingAs($this->admin, 'sanctum')
        ->patchJson('/v1/products/'.$product->public_id, ['soldBy' => 'weight'])
        ->assertUnprocessable()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/validation-failed');
});

test('an admin switches a product from unit to weight in one request', function () {
    $product = Product::factory()->create();

    $this->actingAs($this->admin, 'sanctum')
        ->patchJson('/v1/products/'.$product->public_id, [
            'soldBy' => 'weight',
            'price' => null,
            'pricePerKg' => 6.99,
            'averageWeightKg' => 0.500,
        ])
        ->assertOk();

    $product->refresh();

    expect($product->sold_by->value)->toBe('weight')
        ->and($product->price)->toBeNull()
        ->and((float) $product->price_per_kg)->toBe(6.99);
});

test('an admin deletes a product and it disappears from the catalogue', function () {
    $product = Product::factory()->create();

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson('/v1/products/'.$product->public_id)
        ->assertNoContent();

    expect(Product::find($product->id))->toBeNull()
        ->and(Product::withTrashed()->find($product->id))->not->toBeNull();

    $this->getJson('/v1/products/'.$product->public_id)->assertNotFound();
});

test('a non-admin authenticated customer is forbidden from deleting a product', function () {
    $product = Product::factory()->create();

    $this->actingAs($this->customer, 'sanctum')
        ->deleteJson('/v1/products/'.$product->public_id)
        ->assertForbidden();

    expect(Product::find($product->id))->not->toBeNull();
});

test('deleting a product requires authentication', function () {
    $product = Product::factory()->create();

    $this->deleteJson('/v1/products/'.$product->public_id)
        ->assertUnauthorized();
});

test('deleting a nonexistent product is a 404', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson('/v1/products/'.Str::uuid7())
        ->assertNotFound();
});

test('deleting an already-deleted product is a 404', function () {
    $product = Product::factory()->create();
    $product->delete();

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson('/v1/products/'.$product->public_id)
        ->assertNotFound();
});
