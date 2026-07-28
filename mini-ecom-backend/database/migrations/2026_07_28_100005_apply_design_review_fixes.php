<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the three High findings left open in `docs/design-review.md`.
 *
 * R-03 needs no schema change: `order_items.final_line_total` is declared authoritative and
 * is the only column `subtotal_final` ever sums, with `order_item_substitutions` demoted to
 * a pure audit record. That invariant is enforced by test, not by a constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        // R-02 — abandoned checkouts hold stock reservations forever.
        //
        // Checkout reserves stock, then authorises payment. If authorisation fails, times out,
        // or the customer closes the tab mid-3DS, the order stays `pending_payment` and its
        // reservation is never released. A 20-minute payment outage strands hundreds of
        // reservations, the store shows popular items as out of stock while the shelves are
        // full, and no alert fires because every individual row is internally consistent.
        //
        // Set to placed_at + 30 minutes at checkout, cleared on transition to `confirmed`.
        // ReleaseExpiredReservations sweeps anything past expiry.
        Schema::table('orders', function (Blueprint $table) {
            $table->dateTime('reservation_expires_at', 3)->nullable()->after('idempotency_key');
            $table->index(['status', 'reservation_expires_at'], 'ix_orders_reservation_sweep');
        });

        // R-04 — an order could be confirmed with no delivery slot.
        //
        // The column is nullable so an order can exist before a slot is chosen, but nothing
        // required one by the time the order was confirmed and paid for. Such an order appears
        // on no picking manifest (ix_orders_slot never matches it) and is silently never
        // fulfilled.
        DB::statement("ALTER TABLE orders ADD CONSTRAINT ck_orders_slot_required CHECK (
            status IN ('pending_payment','cancelled') OR delivery_slot_id IS NOT NULL
        )");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT ck_orders_slot_required');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('ix_orders_reservation_sweep');
            $table->dropColumn('reservation_expires_at');
        });
    }
};
