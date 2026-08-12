<?php

use App\Enums\OrderStatus;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->otherCustomer = User::factory()->create();

    $this->actingAs($this->user, 'sanctum');
});

// ---------------------------------------------------------------------------
// The observer
// ---------------------------------------------------------------------------

test('cancelling an order records a notification for its owner', function () {
    $order = Order::factory()->for($this->user)->create(['status' => OrderStatus::Confirmed]);

    $this->postJson('/v1/orders/'.$order->public_id.'/cancel', ['cancellationReason' => 'Changed my mind'])
        ->assertOk();

    expect(Notification::count())->toBe(1);

    $notification = Notification::first();

    expect($notification->user_id)->toBe($this->user->id)
        ->and($notification->type)->toBe('order_status_changed')
        ->and($notification->data['orderId'])->toBe($order->public_id)
        ->and($notification->data['fromStatus'])->toBe('confirmed')
        ->and($notification->data['toStatus'])->toBe('cancelled')
        ->and($notification->data['message'])->toContain($order->order_number)
        ->and($notification->read_at)->toBeNull();
});

test('updating an order without changing status does not record a notification', function () {
    $order = Order::factory()->for($this->user)->create(['status' => OrderStatus::Confirmed]);

    $order->update(['customer_note' => 'Leave at the door']);

    expect(Notification::count())->toBe(0);
});

test('any code path that changes order status produces a notification, not just cancel', function () {
    $order = Order::factory()->for($this->user)->create(['status' => OrderStatus::Confirmed]);

    $order->update(['status' => OrderStatus::Picking]);

    expect(Notification::count())->toBe(1)
        ->and(Notification::first()->data['toStatus'])->toBe('picking');
});

// ---------------------------------------------------------------------------
// Listing
// ---------------------------------------------------------------------------

test('notifications are listed newest first', function () {
    $older = Notification::factory()->for($this->user)->create(['created_at' => now()->subHour()]);
    $newer = Notification::factory()->for($this->user)->create(['created_at' => now()]);

    $this->getJson('/v1/notifications')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $newer->public_id)
        ->assertJsonPath('data.1.id', $older->public_id);
});

test('the list contains only the caller own notifications', function () {
    Notification::factory()->for($this->user)->create();
    Notification::factory()->for($this->otherCustomer)->count(3)->create();

    $this->getJson('/v1/notifications')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('notifications can be filtered to unread only', function () {
    Notification::factory()->for($this->user)->read()->create();
    $unread = Notification::factory()->for($this->user)->create();

    $this->getJson('/v1/notifications?unreadOnly=true')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $unread->public_id);
});

test('notifications are paginated', function () {
    Notification::factory()->for($this->user)->count(3)->create();

    $this->getJson('/v1/notifications?perPage=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('page.currentPage', 1)
        ->assertJsonPath('page.lastPage', 2)
        ->assertJsonPath('page.total', 3);
});

test('notification page size is clamped to a safe maximum', function () {
    Notification::factory()->for($this->user)->count(101)->create();

    $this->getJson('/v1/notifications?perPage=1000000')
        ->assertOk()
        ->assertJsonCount(100, 'data')
        ->assertJsonPath('page.total', 101);
});

// ---------------------------------------------------------------------------
// Marking read
// ---------------------------------------------------------------------------

test('a notification is marked as read', function () {
    $notification = Notification::factory()->for($this->user)->create();

    $this->postJson('/v1/notifications/'.$notification->public_id.'/read')
        ->assertOk()
        ->assertJsonPath('readAt', fn ($value) => $value !== null);

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('marking an already-read notification as read again is not an error', function () {
    $notification = Notification::factory()->for($this->user)->read()->create();
    $originalReadAt = $notification->read_at;

    $this->postJson('/v1/notifications/'.$notification->public_id.'/read')
        ->assertOk();

    expect($notification->fresh()->read_at->eq($originalReadAt))->toBeTrue();
});

test('all unread notifications are marked read in one call', function () {
    Notification::factory()->for($this->user)->count(3)->create();
    Notification::factory()->for($this->user)->read()->create();

    $this->postJson('/v1/notifications/read-all')->assertNoContent();

    expect($this->user->notifications()->whereNull('read_at')->count())->toBe(0)
        ->and($this->user->notifications()->count())->toBe(4);
});

test('marking all read does not touch another customer notifications', function () {
    $theirs = Notification::factory()->for($this->otherCustomer)->create();

    $this->postJson('/v1/notifications/read-all')->assertNoContent();

    expect($theirs->fresh()->read_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// Ownership and auth
// ---------------------------------------------------------------------------

test('another customer notification is indistinguishable from one that does not exist', function () {
    $theirs = Notification::factory()->for($this->otherCustomer)->create();

    $missing = $this->postJson('/v1/notifications/'.Str::uuid7().'/read');
    $notMine = $this->postJson('/v1/notifications/'.$theirs->public_id.'/read');

    $missing->assertNotFound();
    $notMine->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/not-found');

    expect($theirs->fresh()->read_at)->toBeNull();
});

test('every notification operation requires authentication', function (string $method, string $path) {
    $this->app['auth']->forgetGuards();

    $this->json($method, $path)
        ->assertUnauthorized()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/unauthorized');
})->with([
    ['GET', '/v1/notifications'],
    ['POST', '/v1/notifications/0192f3a1-0000-7000-8002-000000000001/read'],
    ['POST', '/v1/notifications/read-all'],
]);
