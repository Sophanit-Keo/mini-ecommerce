<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An idempotency key identifies a retried request made by one customer, not a globally shared
 * resource. The old global unique index let one user (or an attacker) claim a guessed UUID and
 * make another user's checkout with the same key fail. It also made the exception recovery path
 * look for an order under the second user and return a misleading 404.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('uq_orders_idempotency_key');
            $table->unique(['user_id', 'idempotency_key'], 'uq_orders_user_idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('uq_orders_user_idempotency_key');
            $table->unique('idempotency_key', 'uq_orders_idempotency_key');
        });
    }
};
