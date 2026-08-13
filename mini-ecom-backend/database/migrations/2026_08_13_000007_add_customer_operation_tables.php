<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable()->after('telegram_chat_id');
        });

        Schema::create('telegram_link_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', indexName: 'fk_tg_link_challenge_user')->cascadeOnDelete();
            $table->char('code_hash', 64);
            $table->dateTime('expires_at', 3);
            $table->dateTime('consumed_at', 3)->nullable();
            $table->dateTime('created_at', 3)->useCurrent();

            $table->unique('user_id', 'uq_tg_link_challenge_user');
            $table->index(['code_hash', 'expires_at'], 'ix_tg_link_challenge_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_link_challenges');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
    }
};
