<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ports `db/migrations/0005_orders.sql` — orders, lines, substitutions, status history.
 *
 * Every money field exists twice. `*_estimated` is computed at checkout from average
 * weights and authorises payment; `*_final` is computed after picking from actual scale
 * readings and captures it. `*_final` stays NULL until the order reaches `packed`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->binary('public_id', length: 16, fixed: true);
            $table->string('order_number', 20);
            $table->foreignId('user_id')
                ->constrained('users', indexName: 'fk_orders_user')->restrictOnDelete();
            $table->foreignId('delivery_address_id')->nullable()
                ->constrained('addresses', indexName: 'fk_orders_address')->nullOnDelete();

            // The address row can later be edited or deleted; the order must remember where it
            // was actually going.
            $table->json('delivery_address_snapshot');

            $table->foreignId('delivery_slot_id')->nullable()
                ->constrained('delivery_slots', indexName: 'fk_orders_slot')->restrictOnDelete();
            $table->enum('status', [
                'pending_payment', 'confirmed', 'picking', 'packed',
                'out_for_delivery', 'delivered', 'cancelled', 'refunded',
            ])->default('pending_payment');
            $table->enum('payment_status', ['pending', 'authorized', 'captured', 'failed', 'refunded'])
                ->default('pending');
            $table->enum('payment_method', ['card', 'cash_on_delivery', 'wallet']);
            $table->char('currency', 3)->default('USD');

            $table->decimal('subtotal_estimated', 10, 2);
            $table->decimal('delivery_fee', 10, 2)->default(0.00);
            $table->decimal('discount_total', 10, 2)->default(0.00);
            $table->decimal('tax_estimated', 10, 2)->default(0.00);
            $table->decimal('total_estimated', 10, 2);
            $table->decimal('subtotal_final', 10, 2)->nullable();
            $table->decimal('tax_final', 10, 2)->nullable();
            $table->decimal('total_final', 10, 2)->nullable();
            $table->decimal('authorized_amount', 10, 2)->nullable();
            $table->decimal('captured_amount', 10, 2)->nullable();

            $table->string('customer_note', 500)->nullable();
            $table->string('idempotency_key', 64);
            $table->dateTime('placed_at', 3)->useCurrent();
            $table->dateTime('confirmed_at', 3)->nullable();
            $table->dateTime('delivered_at', 3)->nullable();
            $table->dateTime('cancelled_at', 3)->nullable();
            $table->string('cancellation_reason', 280)->nullable();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->unique('public_id', 'uq_orders_public_id');
            $table->unique('order_number', 'uq_orders_number');

            // Checkout idempotency is enforced here, not in application code: "check if it
            // exists, then insert" loses the race under genuine concurrency, which is exactly
            // when a double-tapped checkout button matters.
            $table->unique('idempotency_key', 'uq_orders_idempotency_key');

            $table->index(['user_id', 'placed_at'], 'ix_orders_user_recent');
            $table->index(['status', 'placed_at'], 'ix_orders_status');
            $table->index(['delivery_slot_id', 'status'], 'ix_orders_slot');
        });

        DB::statement('ALTER TABLE orders ADD CONSTRAINT ck_orders_amounts_nonneg CHECK (
            subtotal_estimated >= 0 AND delivery_fee >= 0 AND discount_total >= 0
            AND tax_estimated >= 0 AND total_estimated >= 0
            AND (subtotal_final    IS NULL OR subtotal_final    >= 0)
            AND (tax_final         IS NULL OR tax_final         >= 0)
            AND (total_final       IS NULL OR total_final       >= 0)
            AND (authorized_amount IS NULL OR authorized_amount >= 0)
            AND (captured_amount   IS NULL OR captured_amount   >= 0)
        )');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT ck_orders_total_estimated CHECK (
            total_estimated = subtotal_estimated + delivery_fee + tax_estimated - discount_total
        )');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT ck_orders_final_together CHECK (
            (subtotal_final IS NULL AND tax_final IS NULL AND total_final IS NULL)
            OR
            (subtotal_final IS NOT NULL AND tax_final IS NOT NULL AND total_final IS NOT NULL)
        )');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT ck_orders_total_final CHECK (
            total_final IS NULL
            OR total_final = subtotal_final + delivery_fee + tax_final - discount_total
        )');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT ck_orders_cancelled CHECK (
            (status <> 'cancelled') OR (cancelled_at IS NOT NULL)
        )");

        // Lines snapshot the product: name, SKU, brand, pricing shape and unit price are copied
        // at checkout so a later catalogue edit cannot rewrite history. `product_id` is
        // nullable and SET NULL on delete for the same reason.
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')
                ->constrained('orders', indexName: 'fk_order_items_order')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()
                ->constrained('products', indexName: 'fk_order_items_product')->nullOnDelete();
            $table->string('product_name', 200);
            $table->string('product_sku', 64);
            $table->string('brand', 120)->nullable();
            $table->enum('sold_by', ['unit', 'weight']);
            $table->string('unit_label', 16);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('ordered_quantity', 10, 3);
            $table->decimal('estimated_weight_kg', 8, 3)->nullable();
            $table->decimal('estimated_line_total', 10, 2);
            $table->decimal('picked_quantity', 10, 3)->nullable();
            $table->decimal('picked_weight_kg', 8, 3)->nullable();
            $table->decimal('final_line_total', 10, 2)->nullable();
            $table->enum('substitution_preference', ['none', 'similar', 'contact_me'])->default('similar');
            $table->enum('status', ['pending', 'picked', 'substituted', 'unavailable'])->default('pending');
            $table->string('note', 280)->nullable();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->index('order_id', 'ix_order_items_order');
            $table->index('product_id', 'ix_order_items_product');
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT ck_order_items_quantity CHECK (ordered_quantity > 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT ck_order_items_amounts CHECK (
            unit_price >= 0 AND estimated_line_total >= 0
            AND (final_line_total IS NULL OR final_line_total >= 0)
        )');
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT ck_order_items_weight_shape CHECK (
            (sold_by = 'weight' AND estimated_weight_kg IS NOT NULL AND estimated_weight_kg > 0)
            OR
            (sold_by = 'unit'   AND estimated_weight_kg IS NULL)
        )");
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT ck_order_items_picked_shape CHECK (
            (status IN ('picked','substituted') AND final_line_total IS NOT NULL)
            OR
            (status IN ('pending','unavailable') AND final_line_total IS NULL)
        )");

        // Pure audit record of what was swapped and why. Per design-review finding R-03,
        // `order_items.final_line_total` is the single authoritative figure for pricing;
        // `price_delta` here is for display and must never be added to a total.
        Schema::create('order_item_substitutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')
                ->constrained('order_items', indexName: 'fk_substitutions_item')->cascadeOnDelete();
            $table->foreignId('substitute_product_id')->nullable()
                ->constrained('products', indexName: 'fk_substitutions_product')->nullOnDelete();
            $table->string('substitute_name', 200);
            $table->string('substitute_sku', 64);
            $table->decimal('substitute_unit_price', 10, 2);
            $table->decimal('substitute_quantity', 10, 3);
            $table->decimal('substitute_weight_kg', 8, 3)->nullable();
            $table->decimal('substitute_line_total', 10, 2);
            $table->decimal('price_delta', 10, 2);
            $table->string('reason', 280)->nullable();
            $table->enum('decided_by', ['picker', 'customer'])->default('picker');
            $table->boolean('customer_approved')->nullable();
            $table->foreignId('created_by')->nullable()
                ->constrained('users', indexName: 'fk_substitutions_user')->nullOnDelete();
            $table->dateTime('created_at', 3)->useCurrent();

            $table->index('order_item_id', 'ix_substitutions_item');
        });

        DB::statement('ALTER TABLE order_item_substitutions ADD CONSTRAINT ck_substitutions_amounts CHECK (
            substitute_unit_price >= 0 AND substitute_quantity > 0
            AND substitute_line_total >= 0
        )');

        Schema::create('order_status_history', function (Blueprint $table) {
            $statuses = [
                'pending_payment', 'confirmed', 'picking', 'packed',
                'out_for_delivery', 'delivered', 'cancelled', 'refunded',
            ];

            $table->id();
            $table->foreignId('order_id')
                ->constrained('orders', indexName: 'fk_status_history_order')->cascadeOnDelete();
            $table->enum('from_status', $statuses)->nullable();
            $table->enum('to_status', $statuses);
            $table->foreignId('changed_by')->nullable()
                ->constrained('users', indexName: 'fk_status_history_user')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->dateTime('created_at', 3)->useCurrent();

            $table->index(['order_id', 'created_at'], 'ix_order_status_history');
        });

        DB::statement('ALTER TABLE order_status_history ADD CONSTRAINT ck_status_history_transition CHECK (
            from_status IS NULL OR from_status <> to_status
        )');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_item_substitutions');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
