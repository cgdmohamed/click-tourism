<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** ADR-0026 (GAP-RPT-06): reports e-mailed on a schedule through the SMTP settings of the environment. Additive. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('name', 100)->nullable();
            $table->string('report', 40);
            $table->string('format', 5);
            $table->text('params'); // fixed filters (JSON); a period preset overrides from / to / as_of at run time
            $table->string('period', 20)->nullable();
            $table->string('frequency', 10); // daily|weekly|monthly
            $table->unsignedTinyInteger('day')->nullable(); // weekly: 1-7 (Mon-Sun); monthly: 1-28
            $table->text('recipients'); // JSON list of e-mail addresses
            $table->boolean('is_active')->default(true);
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 24)->nullable(); // sent|failed|mail_not_configured
            $table->string('last_error', 300)->nullable();
            $table->unsignedTinyInteger('consecutive_failures')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
