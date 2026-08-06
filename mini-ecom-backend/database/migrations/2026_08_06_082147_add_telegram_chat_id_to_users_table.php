<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An admin registers their Telegram chat here so the bot knows where to deliver order
 * notifications and where a `callback_query` claiming to act on an order is allowed to have
 * come from. Only admins are expected to ever set this, so a nullable column on `users` is
 * simpler than a dedicated link table for a single 1:1 relationship.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_chat_id', 32)->nullable()->after('phone');
            $table->unique('telegram_chat_id', 'uq_users_telegram_chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('uq_users_telegram_chat_id');
            $table->dropColumn('telegram_chat_id');
        });
    }
};
