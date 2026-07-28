<?php

use App\Models\Address;
use App\Models\Order;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->otherCustomer = User::factory()->create();

    $this->actingAs($this->user, 'sanctum');
});

/**
 * @return array<string, mixed>
 */
function validAddress(array $overrides = []): array
{
    return [
        'label' => 'Home',
        'recipientName' => 'Alice Nguyen',
        'phone' => '+14155550142',
        'line1' => '1420 Sutter St',
        'line2' => 'Apt 3B',
        'city' => 'San Francisco',
        'region' => 'CA',
        'postalCode' => '94109',
        'countryCode' => 'US',
        'latitude' => 37.7871230,
        'longitude' => -122.4212340,
        'deliveryNotes' => 'Buzz 3B; leave with doorman if out.',
        ...$overrides,
    ];
}

// ---------------------------------------------------------------------------
// Creating and reading
// ---------------------------------------------------------------------------

test('an address is created and returned in camelCase', function () {
    $response = $this->postJson('/v1/addresses', validAddress());

    $response->assertCreated()
        ->assertJsonPath('recipientName', 'Alice Nguyen')
        ->assertJsonPath('postalCode', '94109')
        ->assertJsonPath('countryCode', 'US')
        ->assertJsonPath('isDefault', false)
        ->assertJsonPath('latitude', '37.7871230')
        ->assertJsonPath('longitude', '-122.4212340');

    expect($response->json('id'))->toMatch('/^[0-9a-f-]{36}$/')
        ->and(Address::count())->toBe(1)
        ->and(Address::first()->user_id)->toBe($this->user->id);
});

test('coordinates are strings, not JSON numbers', function () {
    // DECIMAL(10,7) does not survive a round trip through an IEEE-754 double intact.
    $response = $this->postJson('/v1/addresses', validAddress());

    expect($response->json('latitude'))->toBeString()
        ->and($response->json('longitude'))->toBeString();
});

test('an address may be created without coordinates', function () {
    $this->postJson('/v1/addresses', validAddress(['latitude' => null, 'longitude' => null]))
        ->assertCreated()
        ->assertJsonPath('latitude', null);
});

test('half a coordinate pair is rejected as a validation failure, not a database error', function () {
    $this->postJson('/v1/addresses', validAddress(['longitude' => null]))
        ->assertUnprocessable()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/validation-failed')
        ->assertJsonPath('errors.0.field', 'latitude');
});

test('required fields are enforced', function (string $field) {
    $this->postJson('/v1/addresses', validAddress([$field => null]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.field', $field);
})->with(['recipientName', 'phone', 'line1', 'city', 'countryCode']);

test('addresses are listed default first', function () {
    Address::factory()->for($this->user)->create(['label' => 'Office']);
    Address::factory()->for($this->user)->default()->create(['label' => 'Home']);

    $this->getJson('/v1/addresses')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.label', 'Home')
        ->assertJsonPath('data.0.isDefault', true);
});

test('the list contains only the caller own addresses', function () {
    Address::factory()->for($this->user)->create();
    Address::factory()->for($this->otherCustomer)->count(3)->create();

    $this->getJson('/v1/addresses')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ---------------------------------------------------------------------------
// Ownership
// ---------------------------------------------------------------------------

test('another customer address is indistinguishable from one that does not exist', function (string $method) {
    // 404, never 403. A 403 confirms the resource exists, which lets an attacker enumerate
    // valid ids by watching which ones answer differently.
    $theirs = Address::factory()->for($this->otherCustomer)->create();

    $missing = $this->json($method, '/v1/addresses/'.Str::uuid7());
    $notMine = $this->json($method, '/v1/addresses/'.$theirs->public_id, validAddress());

    $missing->assertNotFound();
    $notMine->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/not-found');

    expect($missing->json('detail'))->toBe($notMine->json('detail'));
})->with(['GET', 'PATCH', 'DELETE']);

test('deleting another customer address leaves it untouched', function () {
    $theirs = Address::factory()->for($this->otherCustomer)->create();

    $this->deleteJson('/v1/addresses/'.$theirs->public_id)->assertNotFound();

    expect($theirs->fresh()->deleted_at)->toBeNull();
});

test('a malformed address id is a 404 rather than an error', function () {
    $this->getJson('/v1/addresses/not-a-uuid')->assertNotFound();
});

test('every address operation requires authentication', function (string $method, string $path) {
    $this->app['auth']->forgetGuards();

    $this->json($method, $path, validAddress())
        ->assertUnauthorized()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/unauthorized');
})->with([
    ['GET', '/v1/addresses'],
    ['POST', '/v1/addresses'],
    ['GET', '/v1/addresses/0192f3a1-0000-7000-8002-000000000001'],
    ['PATCH', '/v1/addresses/0192f3a1-0000-7000-8002-000000000001'],
    ['DELETE', '/v1/addresses/0192f3a1-0000-7000-8002-000000000001'],
]);

// ---------------------------------------------------------------------------
// Updating, defaults and deletion
// ---------------------------------------------------------------------------

test('an address is updated in place', function () {
    $address = Address::factory()->for($this->user)->create();

    $this->patchJson('/v1/addresses/'.$address->public_id, ['city' => 'Oakland'])
        ->assertOk()
        ->assertJsonPath('city', 'Oakland')
        ->assertJsonPath('id', $address->public_id);

    expect($address->fresh()->city)->toBe('Oakland');
});

test('promoting an address to default demotes the previous one', function () {
    // uq_addresses_default enforces one default per user in the database, so the old flag has
    // to be cleared before the new one lands.
    $first = Address::factory()->for($this->user)->default()->create();
    $second = Address::factory()->for($this->user)->create();

    $this->patchJson('/v1/addresses/'.$second->public_id, ['isDefault' => true])
        ->assertOk()
        ->assertJsonPath('isDefault', true);

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($second->fresh()->is_default)->toBeTrue();
});

test('creating a default address demotes the previous default', function () {
    $existing = Address::factory()->for($this->user)->default()->create();

    $this->postJson('/v1/addresses', validAddress(['isDefault' => true]))->assertCreated();

    expect($existing->fresh()->is_default)->toBeFalse()
        ->and($this->user->addresses()->where('is_default', true)->count())->toBe(1);
});

test('one customer default does not conflict with another customer default', function () {
    Address::factory()->for($this->otherCustomer)->default()->create();

    $this->postJson('/v1/addresses', validAddress(['isDefault' => true]))->assertCreated();
});

test('deletion is soft', function () {
    $address = Address::factory()->for($this->user)->create();

    $this->deleteJson('/v1/addresses/'.$address->public_id)->assertNoContent();

    expect(Address::withTrashed()->count())->toBe(1)
        ->and(Address::count())->toBe(0);

    $this->getJson('/v1/addresses/'.$address->public_id)->assertNotFound();
});

test('editing an address never rewrites an order already placed to it', function () {
    // The order carries its own snapshot of the address as it stood at placement.
    $address = Address::factory()->for($this->user)->create(['city' => 'San Francisco']);
    $order = Order::factory()->forAddress($address)->create();

    $this->patchJson('/v1/addresses/'.$address->public_id, ['city' => 'Oakland'])->assertOk();

    expect($order->fresh()->delivery_address_snapshot['city'])->toBe('San Francisco')
        ->and($address->fresh()->city)->toBe('Oakland');
});

test('deleting an address leaves past orders intact', function () {
    $address = Address::factory()->for($this->user)->create();
    $order = Order::factory()->forAddress($address)->create();

    $this->deleteJson('/v1/addresses/'.$address->public_id)->assertNoContent();

    expect($order->fresh()->delivery_address_snapshot)->not->toBeNull();
});
