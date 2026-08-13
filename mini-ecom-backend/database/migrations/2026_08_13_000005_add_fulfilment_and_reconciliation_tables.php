<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // A non-zero delta between the authorized/paid checkout estimate and actual picked
            // basket cannot safely be hidden. It must be reconciled before dispatch.
            $table->enum('reconciliation_status', ['pending', 'settled', 'amount_due', 'refund_due', 'not_required'])
                ->default('pending')
                ->after('captured_amount');
            $table->decimal('reconciliation_delta', 10, 2)->nullable()->after('reconciliation_status');
            $table->string('reconciliation_reference', 120)->nullable()->after('reconciliation_delta');
            $table->dateTime('reconciled_at', 3)->nullable()->after('reconciliation_reference');

            // This is a durable exact-once marker for the on-hand/reserved inventory mutation.
            // It is separate from final totals so legacy/imported orders can be represented safely.
            $table->dateTime('fulfilled_at', 3)->nullable()->after('resources_reserved_at');
            $table->dateTime('reservation_released_at', 3)->nullable()->after('fulfilled_at');
        });

        DB::statement('ALTER TABLE orders ADD CONSTRAINT ck_orders_reconciliation_amount CHECK (
            reconciliation_delta IS NULL OR reconciliation_delta <> 0
        )');

        Schema::create('order_fulfillment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')
                ->constrained('orders', indexName: 'fk_fulfillment_events_order')->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()
                ->constrained('order_items', indexName: 'fk_fulfillment_events_item')->nullOnDelete();
            $table->enum('event_type', ['picked', 'substituted', 'unavailable', 'finalized', 'reconciled']);
            $table->json('data')->nullable();
            $table->foreignId('created_by')->nullable()
                ->constrained('users', indexName: 'fk_fulfillment_events_user')->nullOnDelete();
            $table->dateTime('created_at', 3)->useCurrent();

            $table->index(['order_id', 'created_at'], 'ix_fulfillment_events_order_created');
            $table->index('order_item_id', 'ix_fulfillment_events_item');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_fulfillment_events');
        DB::statement('ALTER TABLE orders DROP CHECK ck_orders_reconciliation_amount');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'reconciliation_status',
                'reconciliation_delta',
                'reconciliation_reference',
                'reconciled_at',
                'fulfilled_at',
                'reservation_released_at',
            ]);
        });
    }
};
