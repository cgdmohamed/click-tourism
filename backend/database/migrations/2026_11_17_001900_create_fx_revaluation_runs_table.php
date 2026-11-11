<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks period-end FX revaluation per fiscal period and branch (DEC-008):
     * the revaluation entry, its automatic reversal and whether that reversal
     * is still pending (next period not open yet). Additive only.
     */
    public function up(): void
    {
        Schema::create('fx_revaluation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('revaluation_entry_id')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->unsignedInteger('attempt')->default(1);
            // posted (no auto reversal), reversal_pending, reversed
            $table->string('status', 30);
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['fiscal_period_id', 'branch_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_revaluation_runs');
    }
};
