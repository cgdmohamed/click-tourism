<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prompt 20 — Cost Centers & Analytical Accounting.
     *
     * Cost center is an analytical dimension on posted journal lines, NOT a GL
     * account. A separate allocations table keeps the existing `journal_lines`
     * schema untouched (backward compatible) while allowing a line to be split
     * among several centers.
     */
    public function up(): void
    {
        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code', 40)->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->foreignId('parent_id')->nullable()->constrained('cost_centers')->restrictOnDelete();
            $table->string('status', 20)->default('active'); // active|archived
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['parent_id']);
            $table->index(['status', 'is_active']);
        });

        Schema::create('cost_center_allocations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('journal_line_id')->constrained('journal_lines')->cascadeOnDelete();
            $table->foreignId('cost_center_id')->constrained('cost_centers')->restrictOnDelete();
            $table->enum('allocation_type', ['exact', 'percent'])->default('exact');
            $table->bigInteger('amount_minor')->default(0);
            $table->decimal('percent', 5, 2)->nullable(); // used when allocation_type = percent
            $table->index(['journal_line_id']);
            $table->index(['cost_center_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_center_allocations');
        Schema::dropIfExists('cost_centers');
    }
};
