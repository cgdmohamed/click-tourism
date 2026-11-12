<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            // Audit rows were previously global. The branch is inferred from the
            // audited entity or the active branch so operators can filter the
            // trail without weakening the append-only table.
            $table->foreignId('branch_id')->nullable()->after('actor_id')->constrained('branches')->nullOnDelete();
            $table->index(['branch_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'created_at']);
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
