<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `ix_products_category_price` optimizes the category-filtered browse path, but its leading
 * `category_id` means MySQL cannot use it for the equally common all-catalogue price sort. These
 * two narrow composite indexes let the optimizer scan active rows directly in price order, with
 * or without a `sold_by` filter, rather than filesorting the public catalogue on each request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index(['is_active', 'effective_price'], 'ix_products_active_price');
            $table->index(['is_active', 'sold_by', 'effective_price'], 'ix_products_active_sold_by_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('ix_products_active_sold_by_price');
            $table->dropIndex('ix_products_active_price');
        });
    }
};
