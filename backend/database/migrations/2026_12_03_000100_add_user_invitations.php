<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** ADR-0032: invite a new user by e-mail instead of the administrator typing a password. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('invite_sent_at')->nullable()->after('last_login_at');
        });

        // Invitation links live longer than reset links, so they have their own token table (same shape as the framework's).
        Schema::create('user_invite_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invite_tokens');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('invite_sent_at');
        });
    }
};
