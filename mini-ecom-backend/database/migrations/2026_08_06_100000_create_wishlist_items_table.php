<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A saved-products list per user — no quantities, no checkout integration. Unlike
 * `order_items`, which snapshot a product so history survives deletion, a wishlist is a
 * live pointer to the catalogue: if the product goes away, there is nothing meaningful left
 * to wish for, so the row cascades away with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->binary('public_id', length: 16, fixed: true);
            $table->foreignId('user_id')
                ->constrained('users', indexName: 'fk_wishlist_items_user')->cascadeOnDelete();
            $table->foreignId('product_id')
                ->constrained('products', indexName: 'fk_wishlist_items_product')->cascadeOnDelete();
            $table->dateTime('created_at', 3)->useCurrent();

            // Makes add-to-wishlist an idempotent operation: re-adding an already-saved
            // product returns the existing row instead of producing a duplicate.
            $table->unique('public_id', 'uq_wishlist_items_public_id');
            $table->unique(['user_id', 'product_id'], 'uq_wishlist_items_user_product');
            $table->index('product_id', 'ix_wishlist_items_product');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_items');
    }
};
