<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ports `db/migrations/0002_catalog.sql` — categories, products, images, inventory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->binary('public_id', length: 16, fixed: true);
            $table->foreignId('parent_id')->nullable()
                ->constrained('categories', indexName: 'fk_categories_parent')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140);
            $table->string('description', 500)->nullable();
            $table->string('image_url', 500)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();
            $table->dateTime('deleted_at', 3)->nullable();

            $table->string('slug_active', 140)->storedAs('IF(deleted_at IS NULL, slug, NULL)');

            $table->unique('public_id', 'uq_categories_public_id');
            $table->unique('slug_active', 'uq_categories_slug_active');
            $table->index(['parent_id', 'is_active', 'position'], 'ix_categories_parent');
        });

        // A product is priced one of two ways and never both: `price` for unit products,
        // `price_per_kg` + `average_weight_kg` for weight products. ck_products_pricing_shape
        // makes the other shape's columns impossible to populate, so no read site ever has to
        // ask "which one is set?".
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->binary('public_id', length: 16, fixed: true);
            $table->foreignId('category_id')
                ->constrained('categories', indexName: 'fk_products_category')->restrictOnDelete();
            $table->string('sku', 64);
            $table->string('name', 200);
            $table->string('slug', 220);
            $table->string('brand', 120)->nullable();
            $table->text('description')->nullable();
            $table->enum('sold_by', ['unit', 'weight'])->default('unit');
            $table->string('unit_label', 16)->default('ea');
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('price_per_kg', 10, 2)->nullable();
            $table->decimal('compare_at_price', 10, 2)->nullable();
            $table->decimal('average_weight_kg', 8, 3)->nullable();
            $table->decimal('weight_tolerance_pct', 5, 2)->default(10.00);
            $table->decimal('min_order_quantity', 10, 3)->default(1.000);
            $table->decimal('max_order_quantity', 10, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();
            $table->dateTime('deleted_at', 3)->nullable();

            $table->string('sku_active', 64)->storedAs('IF(deleted_at IS NULL, sku, NULL)');
            $table->string('slug_active', 220)->storedAs('IF(deleted_at IS NULL, slug, NULL)');

            $table->unique('public_id', 'uq_products_public_id');
            $table->unique('sku_active', 'uq_products_sku_active');
            $table->unique('slug_active', 'uq_products_slug_active');
            $table->index(['category_id', 'is_active', 'price'], 'ix_products_category_active');
            $table->index(['is_active', 'created_at'], 'ix_products_active_created');
            $table->fullText(['name', 'brand', 'description'], 'ft_products_search');
        });

        DB::statement("ALTER TABLE products ADD CONSTRAINT ck_products_pricing_shape CHECK (
            (sold_by = 'unit'
               AND price IS NOT NULL AND price >= 0
               AND price_per_kg IS NULL AND average_weight_kg IS NULL)
            OR
            (sold_by = 'weight'
               AND price_per_kg IS NOT NULL AND price_per_kg >= 0
               AND average_weight_kg IS NOT NULL AND average_weight_kg > 0
               AND price IS NULL)
        )");
        DB::statement('ALTER TABLE products ADD CONSTRAINT ck_products_compare_at CHECK (compare_at_price IS NULL OR compare_at_price >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT ck_products_tolerance CHECK (weight_tolerance_pct >= 0 AND weight_tolerance_pct <= 100)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT ck_products_qty_bounds CHECK (
            min_order_quantity > 0
            AND (max_order_quantity IS NULL OR max_order_quantity >= min_order_quantity)
        )');

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')
                ->constrained('products', indexName: 'fk_product_images_product')->cascadeOnDelete();
            $table->string('url', 500);
            $table->string('alt_text', 200)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->dateTime('created_at', 3)->useCurrent();

            // VIRTUAL rather than the spec's STORED — see the note on addresses.default_for_user.
            $table->unsignedBigInteger('primary_for_product')
                ->virtualAs('IF(is_primary = 1, product_id, NULL)');

            $table->unique('primary_for_product', 'uq_product_images_primary');
            $table->unique(['product_id', 'position'], 'uq_product_images_position');
            $table->index(['product_id', 'position'], 'ix_product_images_product');
        });

        // Stock is continuous — kilograms, not counts — so every quantity is DECIMAL(10,3).
        // `quantity_available` is generated rather than maintained, which makes an oversell
        // structurally impossible rather than merely unlikely.
        Schema::create('inventory', function (Blueprint $table) {
            $table->foreignId('product_id')->primary()
                ->constrained('products', indexName: 'fk_inventory_product')->cascadeOnDelete();
            $table->decimal('quantity_on_hand', 10, 3)->default(0.000);
            $table->decimal('quantity_reserved', 10, 3)->default(0.000);
            $table->decimal('low_stock_threshold', 10, 3)->default(0.000);
            $table->dateTime('restock_expected_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->decimal('quantity_available', 10, 3)
                ->storedAs('quantity_on_hand - quantity_reserved');

            $table->index('quantity_available', 'ix_inventory_available');
        });

        DB::statement('ALTER TABLE inventory ADD CONSTRAINT ck_inventory_on_hand CHECK (quantity_on_hand >= 0)');
        DB::statement('ALTER TABLE inventory ADD CONSTRAINT ck_inventory_reserved CHECK (quantity_reserved >= 0)');
        DB::statement('ALTER TABLE inventory ADD CONSTRAINT ck_inventory_not_oversold CHECK (quantity_reserved <= quantity_on_hand)');

        Schema::create('inventory_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')
                ->constrained('products', indexName: 'fk_inv_adj_product')->cascadeOnDelete();
            $table->decimal('delta', 10, 3);
            $table->enum('reason', [
                'restock', 'shrinkage', 'correction',
                'order_reserved', 'order_released', 'order_fulfilled', 'return',
            ]);
            $table->string('reference_type', 40)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()
                ->constrained('users', indexName: 'fk_inv_adj_user')->nullOnDelete();
            $table->dateTime('created_at', 3)->useCurrent();

            $table->index(['product_id', 'created_at'], 'ix_inv_adj_product');
            $table->index(['reference_type', 'reference_id'], 'ix_inv_adj_ref');
        });

        DB::statement('ALTER TABLE inventory_adjustments ADD CONSTRAINT ck_inv_adj_nonzero CHECK (delta <> 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustments');
        Schema::dropIfExists('inventory');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
