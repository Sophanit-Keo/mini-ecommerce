<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `reservation_expires_at` pre-dates inventory reservation accounting: legacy rows may consume
 * delivery capacity without having corresponding `quantity_reserved` values. This marker is set
 * only after the new atomic stock-and-slot reservation completes, so release paths never subtract
 * stock from an old unreserved order. Historical pending rows must be reconciled operationally.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dateTime('resources_reserved_at', 3)->nullable()->after('reservation_expires_at');
            $table->index(['status', 'resources_reserved_at'], 'ix_orders_resource_reservation');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('ix_orders_resource_reservation');
            $table->dropColumn('resources_reserved_at');
        });
    }
};
