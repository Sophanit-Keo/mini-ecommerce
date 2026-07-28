<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ports `db/migrations/0001_core.sql` — identity, sessions, delivery addresses.
 *
 * `public_id` is a UUIDv7 supplied by the application and stored as BINARY(16). The
 * auto-increment `id` is never exposed: it leaks row counts and makes resources
 * trivially enumerable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->binary('public_id', length: 16, fixed: true);
            $table->string('email');
            $table->dateTime('email_verified_at', 3)->nullable();
            $table->string('password_hash');
            $table->string('full_name', 120);
            $table->string('phone', 32)->nullable();
            $table->enum('role', ['customer', 'admin'])->default('customer');
            $table->enum('status', ['active', 'suspended'])->default('active');
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();
            $table->dateTime('deleted_at', 3)->nullable();

            // Uniqueness must apply only to live rows. MySQL has no partial indexes, so the
            // portable equivalent is a generated column that goes NULL on soft-delete — NULLs
            // do not collide in a UNIQUE index, so a deleted user frees their address for
            // re-registration.
            $table->string('email_active')->storedAs('IF(deleted_at IS NULL, email, NULL)');

            $table->unique('public_id', 'uq_users_public_id');
            $table->unique('email_active', 'uq_users_email_active');
            $table->index(['role', 'status'], 'ix_users_role_status');
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT ck_users_email_shape CHECK (email LIKE '%_@_%')");

        // Only the SHA-256 of the token is stored, so a database leak does not hand over
        // usable sessions. `replaced_by_id` records rotation: reuse of an already rotated
        // token is the standard signal of a stolen refresh token, and lets the application
        // revoke the whole chain.
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', indexName: 'fk_refresh_user')->cascadeOnDelete();
            $table->char('token_hash', 64);
            $table->dateTime('issued_at', 3)->useCurrent();
            $table->dateTime('expires_at', 3);
            $table->dateTime('revoked_at', 3)->nullable();
            $table->foreignId('replaced_by_id')->nullable()
                ->constrained('refresh_tokens', indexName: 'fk_refresh_replaced_by')->nullOnDelete();
            $table->string('user_agent')->nullable();
            $table->binary('ip_address', length: 16)->nullable();

            $table->unique('token_hash', 'uq_refresh_token_hash');
            $table->index(['user_id', 'expires_at'], 'ix_refresh_user_active');
            $table->index('expires_at', 'ix_refresh_expires');
        });

        DB::statement('ALTER TABLE refresh_tokens ADD CONSTRAINT ck_refresh_expiry CHECK (expires_at > issued_at)');

        // Latitude/longitude are carried because grocery delivery is geofenced — the
        // serviceable-radius check needs coordinates, not a postcode string.
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->binary('public_id', length: 16, fixed: true);
            $table->foreignId('user_id')->constrained('users', indexName: 'fk_addresses_user')->cascadeOnDelete();
            $table->string('label', 40)->nullable();
            $table->string('recipient_name', 120);
            $table->string('phone', 32);
            $table->string('line1', 180);
            $table->string('line2', 180)->nullable();
            $table->string('city', 80);
            $table->string('region', 80)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->char('country_code', 2);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('delivery_notes', 500)->nullable();
            $table->boolean('is_default')->default(false);
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();
            $table->dateTime('deleted_at', 3)->nullable();

            // Same NULL-collapsing trick as users.email: holds user_id only while the row is
            // both default and live, so the UNIQUE index enforces "at most one default address
            // per user" in the database rather than trusting the application to clear the old flag.
            //
            // VIRTUAL, not STORED as in the spec's DDL. MySQL forbids ON DELETE CASCADE on the
            // base column of a *stored* generated column, so the spec's combination is rejected
            // outright (it was only ever verified against MariaDB — see finding R-16). A virtual
            // column carries an identical UNIQUE index and preserves the cascade.
            $table->unsignedBigInteger('default_for_user')
                ->virtualAs('IF(is_default = 1 AND deleted_at IS NULL, user_id, NULL)');

            $table->unique('public_id', 'uq_addresses_public_id');
            $table->unique('default_for_user', 'uq_addresses_default');
            $table->index(['user_id', 'deleted_at'], 'ix_addresses_user');
        });

        DB::statement('ALTER TABLE addresses ADD CONSTRAINT ck_addresses_lat CHECK (latitude IS NULL OR (latitude BETWEEN -90 AND 90))');
        DB::statement('ALTER TABLE addresses ADD CONSTRAINT ck_addresses_lng CHECK (longitude IS NULL OR (longitude BETWEEN -180 AND 180))');
        DB::statement('ALTER TABLE addresses ADD CONSTRAINT ck_addresses_geo_pair CHECK ((latitude IS NULL) = (longitude IS NULL))');

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('refresh_tokens');
        Schema::dropIfExists('users');
    }
};
