<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A six-digit code has only one million possibilities. An IP rate limit helps against a single
 * attacker, but an attacker can distribute attempts across IPs. The counter belongs to the code
 * record itself so the limit follows the credential, not the network connection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_verification_codes', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempt_count')->default(0)->after('code_hash');
        });
    }

    public function down(): void
    {
        Schema::table('email_verification_codes', function (Blueprint $table) {
            $table->dropColumn('attempt_count');
        });
    }
};
