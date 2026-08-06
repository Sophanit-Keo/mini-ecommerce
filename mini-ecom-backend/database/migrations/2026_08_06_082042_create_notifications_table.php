<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A first-party inbox for in-app notifications. Kept as an explicit model rather than
 * Laravel's built-in polymorphic `DatabaseNotification`, to stay consistent with the rest of
 * this codebase: explicit FKs, `HasPublicId` routing, and an explicit `#[Fillable]` list on
 * every model, instead of a generic `notifiable_type`/`notifiable_id` morph.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->binary('public_id', length: 16, fixed: true);
            $table->foreignId('user_id')
                ->constrained('users', indexName: 'fk_notifications_user')->cascadeOnDelete();
            $table->string('type', 60);
            $table->json('data');
            $table->dateTime('read_at', 3)->nullable();
            $table->dateTime('created_at', 3)->useCurrent();

            $table->unique('public_id', 'uq_notifications_public_id');

            // Newest-first inbox listing and the unread-count/mark-all-read query are the two
            // access patterns; both are scoped by user first.
            $table->index(['user_id', 'created_at'], 'ix_notifications_user_recent');
            $table->index(['user_id', 'read_at'], 'ix_notifications_user_unread');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
