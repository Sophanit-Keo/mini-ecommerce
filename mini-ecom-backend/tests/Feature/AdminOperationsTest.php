<?php

use App\Enums\InventoryAdjustmentReason;
use App\Models\AdminAuditEvent;
use App\Models\Category;
use App\Models\DeliverySlot;
use App\Models\Inventory;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->customer = User::factory()->create();
    $this->actingAs($this->admin, 'sanctum');
});

test('an admin creates a product with a dedicated inventory row and audit event', function () {
    $category = Category::factory()->create();

    $response = $this->postJson('/v1/admin/products', [
        'categoryId' => $category->public_id,
        'sku' => 'ADM-UNIT-001',
        'name' => 'Admin Unit Product',
        'slug' => 'admin-unit-product',
        'soldBy' => 'unit',
        'unitLabel' => 'ea',
        'price' => '4.25',
        'minOrderQuantity' => '1.000',
        'initialStock' => '12.000',
        'lowStockThreshold' => '2.000',
    ])->assertCreated()
        ->assertJsonPath('sku', 'ADM-UNIT-001')
        ->assertJsonPath('availableQuantity', '12.000');

    $product = Product::wherePublicId($response->json('id'))->firstOrFail();
    expect($product->inventory)->not->toBeNull()
        ->and($product->inventory->quantity_on_hand)->toBe('12.000')
        ->and(AdminAuditEvent::where('action', 'product.created')->count())->toBe(1);

    $this->actingAs($this->customer, 'sanctum')
        ->getJson('/v1/admin/products')
        ->assertForbidden();
});

test('admin product images maintain exactly one primary image and create audit records', function () {
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create();

    $first = $this->postJson('/v1/admin/products/'.$product->public_id.'/images', [
        'url' => 'https://cdn.example.test/product-one.jpg',
        'position' => 0,
    ])->assertCreated()->assertJsonPath('isPrimary', true);

    $second = $this->postJson('/v1/admin/products/'.$product->public_id.'/images', [
        'url' => 'https://cdn.example.test/product-two.jpg',
        'position' => 1,
        'isPrimary' => true,
    ])->assertCreated()->assertJsonPath('isPrimary', true);

    $this->postJson('/v1/admin/products/'.$product->public_id.'/images/'.$first->json('id').'/primary')
        ->assertOk()->assertJsonPath('id', $first->json('id'))->assertJsonPath('isPrimary', true);

    expect($product->images()->where('is_primary', true)->count())->toBe(1)
        ->and(AdminAuditEvent::whereIn('action', ['product_image.created', 'product_image.primary_changed'])->count())->toBe(3);

    $this->deleteJson('/v1/admin/products/'.$product->public_id.'/images/'.$first->json('id'))
        ->assertNoContent();
    expect($product->images()->where('is_primary', true)->count())->toBe(1);
});

test('inventory adjustments remain ledger-backed and cannot reduce on-hand below reservations', function () {
    $product = Product::factory()->create();
    $inventory = Inventory::factory()->for($product)->create([
        'quantity_on_hand' => '5.000',
        'quantity_reserved' => '3.000',
    ]);

    $this->postJson('/v1/admin/inventory/'.$product->public_id.'/adjustments', [
        'delta' => '-3.000',
        'reason' => InventoryAdjustmentReason::Shrinkage->value,
    ])->assertConflict()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/inventory-adjustment-would-oversell');

    $this->postJson('/v1/admin/inventory/'.$product->public_id.'/adjustments', [
        'delta' => '2.000',
        'reason' => InventoryAdjustmentReason::Restock->value,
        'note' => 'Morning delivery received',
    ])->assertOk()
        ->assertJsonPath('quantityOnHand', '7.000')
        ->assertJsonPath('quantityAvailable', '4.000');

    expect($inventory->fresh()->quantity_on_hand)->toBe('7.000')
        ->and(InventoryAdjustment::where('product_id', $product->id)->count())->toBe(1)
        ->and(AdminAuditEvent::where('action', 'inventory.adjusted')->count())->toBe(1);
});

test('admin delivery-slot administration preserves active booking capacity and window safety', function () {
    $slot = DeliverySlot::factory()->create(['capacity' => 3, 'booked_count' => 2]);

    $this->patchJson('/v1/admin/delivery-slots/'.$slot->public_id, ['capacity' => 1])
        ->assertConflict()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/slot-capacity-below-bookings');

    $this->patchJson('/v1/admin/delivery-slots/'.$slot->public_id, [
        'startsAt' => $slot->starts_at->copy()->addMinutes(15)->toIso8601String(),
        'endsAt' => $slot->ends_at->copy()->addMinutes(15)->toIso8601String(),
    ])
        ->assertConflict()
        ->assertJsonPath('type', 'https://api.grocerly.example/problems/slot-window-locked');

    $created = $this->postJson('/v1/admin/delivery-slots', [
        'startsAt' => now()->addDays(6)->setTime(9, 0)->toIso8601String(),
        'endsAt' => now()->addDays(6)->setTime(11, 0)->toIso8601String(),
        'capacity' => 6,
        'fee' => '3.50',
        'isActive' => true,
    ])->assertCreated()->assertJsonPath('capacity', 6);

    $this->patchJson('/v1/admin/delivery-slots/'.$created->json('id'), ['isActive' => false])
        ->assertOk()->assertJsonPath('remainingCapacity', 6);

    expect(AdminAuditEvent::whereIn('action', ['delivery_slot.created', 'delivery_slot.updated'])->count())->toBe(2);
});

test('admin category and audit listings are paginated and audit records are scoped to safe data', function () {
    $created = $this->postJson('/v1/admin/categories', [
        'name' => 'Admin Category',
        'slug' => 'admin-category',
        'position' => 1,
    ])->assertCreated();

    $this->patchJson('/v1/admin/categories/'.$created->json('id'), ['isActive' => false])
        ->assertOk()->assertJsonPath('name', 'Admin Category');

    $this->getJson('/v1/admin/categories?perPage=1')
        ->assertOk()->assertJsonPath('page.currentPage', 1)->assertJsonCount(1, 'data');

    $this->getJson('/v1/admin/audit-events?perPage=100&action=category.updated')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.action', 'category.updated')
        ->assertJsonMissingPath('data.0.before.password')
        ->assertJsonPath('page.total', 1);
});
