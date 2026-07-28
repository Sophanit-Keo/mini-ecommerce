<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ports `db/migrations/0004_delivery.sql` — bookable delivery windows.
 *
 * Capacity is denormalised onto the slot as `booked_count` rather than derived with
 * COUNT(*) over orders. The reason is concurrency, not speed: a counter column can be
 * incremented and bounds-checked in one atomic statement,
 *
 *     UPDATE delivery_slots
 *        SET booked_count = booked_count + 1
 *      WHERE id = ? AND booked_count < capacity;
 *
 * and zero affected rows means "slot full". A COUNT(*)-then-INSERT sequence has a
 * read-write gap: two checkouts both count 19 of 20, both insert, and the slot is
 * overbooked.
 *
 * ck_slots_capacity is the backstop. Even if application code forgets the
 * `booked_count < capacity` predicate, the database rejects the write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_slots', function (Blueprint $table) {
            $table->id();
            $table->binary('public_id', length: 16, fixed: true);
            $table->date('slot_date');
            $table->dateTime('starts_at', 3);
            $table->dateTime('ends_at', 3);
            $table->unsignedInteger('capacity');
            $table->unsignedInteger('booked_count')->default(0);
            $table->decimal('fee', 10, 2)->default(0.00);
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->unique('public_id', 'uq_delivery_slots_public_id');
            $table->unique(['starts_at', 'ends_at'], 'uq_delivery_slots_window');
            $table->index(['slot_date', 'is_active', 'starts_at'], 'ix_delivery_slots_browse');
        });

        DB::statement('ALTER TABLE delivery_slots ADD CONSTRAINT ck_slots_window CHECK (ends_at > starts_at)');
        DB::statement('ALTER TABLE delivery_slots ADD CONSTRAINT ck_slots_capacity CHECK (booked_count <= capacity)');
        DB::statement('ALTER TABLE delivery_slots ADD CONSTRAINT ck_slots_fee CHECK (fee >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_slots');
    }
};
