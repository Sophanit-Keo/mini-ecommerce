<?php

use App\Models\DeliverySlot;

// Delivery-slot discovery is public because a customer must choose a valid slot before starting
// checkout. The checkout transaction still locks and revalidates the selected slot.

test('customers can browse only future active delivery slots with remaining capacity', function () {
    $available = DeliverySlot::factory()->create([
        'slot_date' => now()->addDays(4)->toDateString(),
        'starts_at' => now()->addDays(4)->setTime(10, 0),
        'ends_at' => now()->addDays(4)->setTime(12, 0),
        'capacity' => 4,
        'booked_count' => 1,
        'fee' => 5.99,
    ]);
    DeliverySlot::factory()->inactive()->create([
        'slot_date' => now()->addDays(5)->toDateString(),
        'starts_at' => now()->addDays(5)->setTime(10, 0),
        'ends_at' => now()->addDays(5)->setTime(12, 0),
    ]);
    DeliverySlot::factory()->full()->create([
        'slot_date' => now()->addDays(6)->toDateString(),
        'starts_at' => now()->addDays(6)->setTime(10, 0),
        'ends_at' => now()->addDays(6)->setTime(12, 0),
    ]);
    DeliverySlot::factory()->create([
        'slot_date' => now()->subDay()->toDateString(),
        'starts_at' => now()->subDay()->setTime(10, 0),
        'ends_at' => now()->subDay()->setTime(12, 0),
    ]);

    $this->getJson('/v1/delivery-slots?from='.now()->toDateString().'&to='.now()->addDays(5)->toDateString())
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $available->public_id)
        ->assertJsonPath('data.0.fee', '5.99')
        ->assertJsonPath('data.0.capacity', 4)
        ->assertJsonPath('data.0.bookedCount', 1)
        ->assertJsonPath('data.0.remainingCapacity', 3);
});

test('delivery-slot discovery rejects inverted and oversized date ranges', function () {
    $this->getJson('/v1/delivery-slots?from=2026-08-20&to=2026-08-19')
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.field', 'to');

    $this->getJson('/v1/delivery-slots?from=2026-08-01&to=2026-09-15')
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.field', 'to');
});
