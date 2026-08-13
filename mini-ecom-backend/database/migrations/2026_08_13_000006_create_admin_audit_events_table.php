<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')
                ->constrained('users', indexName: 'fk_admin_audit_events_actor')->restrictOnDelete();
            $table->string('action', 80);
            $table->string('entity_type', 80);
            $table->binary('entity_public_id', length: 16)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->dateTime('created_at', 3)->useCurrent();

            $table->index(['entity_type', 'entity_public_id', 'created_at'], 'ix_admin_audit_entity');
            $table->index(['actor_id', 'created_at'], 'ix_admin_audit_actor');
            $table->index(['action', 'created_at'], 'ix_admin_audit_action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_events');
    }
};
