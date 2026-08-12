<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bakong is a distinct, QR-based wallet flow. Keeping the value explicit prevents a
        // generic `wallet` branch from silently treating unrelated providers as Bakong.
        DB::statement("ALTER TABLE orders MODIFY payment_method ENUM('card', 'cash_on_delivery', 'wallet', 'bakong') NOT NULL");

        Schema::table('orders', function (Blueprint $table) {
            // Makes `/restore-cart` exact-once. Replays return the active cart rather than
            // adding the historical order lines a second time.
            $table->dateTime('cart_restored_at', 3)->nullable()->after('cancelled_at');
        });

        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->binary('public_id', length: 16, fixed: true);
            $table->foreignId('order_id')
                ->constrained('orders', indexName: 'fk_payment_attempts_order')
                ->cascadeOnDelete();
            $table->string('provider', 32);
            $table->enum('status', ['pending', 'verified', 'expired', 'failed'])->default('pending');

            // The MD5 is derived from the dynamic KHQR payload. It is the only provider lookup
            // key that the application accepts; a browser can never submit an arbitrary hash.
            $table->char('provider_reference', 32);
            $table->text('khqr_payload');
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3);
            $table->char('provider_transaction_hash', 64)->nullable();
            $table->json('provider_response')->nullable();
            $table->unsignedSmallInteger('verification_count')->default(0);
            $table->dateTime('expires_at', 3);
            $table->dateTime('verified_at', 3)->nullable();
            $table->dateTime('last_checked_at', 3)->nullable();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->unique('public_id', 'uq_payment_attempts_public_id');
            $table->unique(['provider', 'provider_reference'], 'uq_payment_attempts_provider_reference');
            $table->unique(['provider', 'provider_transaction_hash'], 'uq_payment_attempts_provider_transaction');
            $table->index(['order_id', 'status'], 'ix_payment_attempts_order_status');
            $table->index(['status', 'expires_at'], 'ix_payment_attempts_expiry');
        });

        DB::statement('ALTER TABLE payment_attempts ADD CONSTRAINT ck_payment_attempts_amount_nonneg CHECK (amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('cart_restored_at');
        });

        DB::statement("ALTER TABLE orders MODIFY payment_method ENUM('card', 'cash_on_delivery', 'wallet') NOT NULL");
    }
};
