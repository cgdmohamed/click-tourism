<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Work order 01 — optional primary branch on the login user so
     * BranchScope can default to the user's own branch when no X-Branch-Id
     * header is sent. Additive and nullable: existing users keep the
     * lowest-id fallback until a primary branch is set.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('primary_branch_id')->nullable()->after('is_available_for_leads')
                ->constrained('branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('primary_branch_id');
        });
    }
};
