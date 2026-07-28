<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Closes a drift between `db/schema.sql` and `docs/openapi.yaml`.
 *
 * The API addresses product images, cart lines and order lines by `public_id` — the
 * `ProductImage.id`, `CartItemId` and `OrderItemId` components are all typed as `PublicId`,
 * and `/admin/orders/{orderId}/items/{itemId}/pick` takes one in the path. The DDL gives
 * none of those three tables such a column, so an implementation following the schema alone
 * would have to serialise the auto-increment key, which §2 of `api-design.md` forbids
 * precisely because it leaks business volume and makes rows enumerable.
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $tables = ['product_images', 'cart_items', 'order_items'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->binary('public_id', length: 16, fixed: true)->nullable()->after('id');
            });

            // Backfill before the column is made unique and non-null, so the migration is
            // safe to run against a database that already holds rows.
            foreach (DB::table($table)->select('id')->orderBy('id')->cursor() as $row) {
                DB::table($table)->where('id', $row->id)->update([
                    'public_id' => Str::uuid7()->getBytes(),
                ]);
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->binary('public_id', length: 16, fixed: true)->nullable(false)->change();
                $blueprint->unique('public_id', "uq_{$table}_public_id");
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropUnique("uq_{$table}_public_id");
                $blueprint->dropColumn('public_id');
            });
        }
    }
};
