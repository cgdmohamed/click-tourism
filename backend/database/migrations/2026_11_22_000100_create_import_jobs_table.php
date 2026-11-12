<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Work order 13 — generic import framework. Additive: one new table that
     * tracks every upload, its dry-run preview, and the final outcome.
     */
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('entity', 40);
            $table->unsignedSmallInteger('template_version');
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // previewed = dry run finished, waiting for confirmation.
            $table->string('status', 20)->default('queued');
            // dry_run validates only; commit writes.
            $table->string('mode', 10)->default('dry_run');
            $table->string('duplicate_policy', 10)->default('skip');
            $table->string('on_error', 10)->default('skip');
            $table->json('options')->nullable();

            $table->string('original_name', 255);
            $table->string('file_format', 8);
            $table->unsignedBigInteger('file_size');
            $table->string('file_sha256', 64);
            $table->string('file_path')->nullable();
            $table->string('error_report_path')->nullable();

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('preview')->nullable();
            $table->json('errors')->nullable();
            $table->json('warnings')->nullable();

            $table->string('failure_code', 40)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['entity', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_jobs');
    }
};
