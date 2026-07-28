<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ports `db/migrations/0006_effective_price.sql` — design-review finding R-01.
 *
 * Sorting the catalogue by price was broken for weight-priced products, because
 * `products.price` is NULL for all of them: "cheapest first" ranked a $5.49 bag of grapes
 * above a $1.79 avocado. `effective_price` carries the comparable shelf price for both
 * pricing shapes, and the API exposes it as `ProductSummary.effectivePrice`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('effective_price', 10, 2)
                ->storedAs("IF(sold_by = 'unit', price, ROUND(price_per_kg * average_weight_kg, 2))")
                ->after('compare_at_price');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->index(['category_id', 'is_active', 'effective_price'], 'ix_products_category_price');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('ix_products_category_active');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index(['category_id', 'is_active', 'price'], 'ix_products_category_active');
            $table->dropIndex('ix_products_category_price');
            $table->dropColumn('effective_price');
        });
    }
};
