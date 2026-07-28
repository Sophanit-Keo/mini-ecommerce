<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ports `db/migrations/0003_cart.sql` — guest and customer carts.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A cart belongs to either a signed-in user or an anonymous browser session
        // (`guest_token`), never neither — ck_carts_owner enforces that. Guest carts are merged
        // into the user's active cart on login and then marked 'merged'.
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->binary('public_id', length: 16, fixed: true);
            $table->foreignId('user_id')->nullable()
                ->constrained('users', indexName: 'fk_carts_user')->cascadeOnDelete();

            // VARCHAR, not CHAR: a CHAR column cannot feed a generated column, because CHAR
            // comparison semantics depend on the PAD_CHAR_TO_FULL_LENGTH sql_mode and the
            // expression is therefore not deterministic.
            $table->string('guest_token', 64)->nullable();

            $table->enum('status', ['active', 'converted', 'merged', 'abandoned'])->default('active');
            $table->char('currency', 3)->default('USD');
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();
            $table->dateTime('expires_at', 3)->nullable();

            // One active cart per user, in the database. Without it, two concurrent "create
            // cart" requests from two tabs silently produce two active carts and the customer
            // loses items depending on which one checkout happens to load.
            //
            // VIRTUAL rather than the spec's STORED, because carts.user_id carries a cascading
            // foreign key — see the note on addresses.default_for_user.
            $table->unsignedBigInteger('active_for_user')
                ->virtualAs("IF(status = 'active', user_id, NULL)");
            $table->string('active_for_guest', 64)
                ->storedAs("IF(status = 'active', guest_token, NULL)");

            $table->unique('public_id', 'uq_carts_public_id');
            $table->unique('active_for_user', 'uq_carts_active_user');
            $table->unique('active_for_guest', 'uq_carts_active_guest');
            $table->index(['status', 'expires_at'], 'ix_carts_expiry');
        });

        DB::statement('ALTER TABLE carts ADD CONSTRAINT ck_carts_owner CHECK (user_id IS NOT NULL OR guest_token IS NOT NULL)');

        // `quantity` is DECIMAL(10,3) and its meaning depends on the product's `sold_by`: a
        // count for unit products, kilograms for weight products. One column rather than two
        // because every read site needs the same value and a nullable pair invites
        // "which one is set?" bugs.
        //
        // `unit_price_snapshot` records the price the customer was shown when they added the
        // item. It is NOT what they are charged — checkout re-prices from the live catalogue —
        // but it is what lets the API say "this went up from $3.99 to $4.49" instead of
        // silently changing the total.
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')
                ->constrained('carts', indexName: 'fk_cart_items_cart')->cascadeOnDelete();
            $table->foreignId('product_id')
                ->constrained('products', indexName: 'fk_cart_items_product')->restrictOnDelete();
            $table->decimal('quantity', 10, 3);
            $table->decimal('unit_price_snapshot', 10, 2);
            $table->enum('substitution_preference', ['none', 'similar', 'contact_me'])->default('similar');
            $table->string('note', 280)->nullable();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            // Makes add-to-cart an idempotent upsert.
            $table->unique(['cart_id', 'product_id'], 'uq_cart_items_cart_product');
            $table->index('product_id', 'ix_cart_items_product');
        });

        DB::statement('ALTER TABLE cart_items ADD CONSTRAINT ck_cart_items_quantity CHECK (quantity > 0)');
        DB::statement('ALTER TABLE cart_items ADD CONSTRAINT ck_cart_items_price CHECK (unit_price_snapshot >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
