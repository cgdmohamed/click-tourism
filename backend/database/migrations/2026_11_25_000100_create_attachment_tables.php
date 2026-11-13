<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GAP-CORE-03 (ADR-0021): private document attachments and the reviewed output of AI extraction.
 * Files live on a private disk under random names; nothing here is reachable except through the
 * authenticated API. Additive: no existing table changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->string('attachable_type', 20);
            $table->unsignedBigInteger('attachable_id');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('category', 30);
            $table->string('original_name', 200);
            $table->string('stored_path', 200)->unique();
            $table->string('mime', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['attachable_type', 'attachable_id']);
            // The same file is stored once per record; the service enforces it under a row lock.
            $table->index(['attachable_type', 'attachable_id', 'sha256'], 'attachments_file_lookup');
        });

        Schema::create('attachment_extractions', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('attachment_id')->constrained('attachments')->cascadeOnDelete();
            $table->string('status', 20); // succeeded | failed | confirmed | rejected
            $table->string('category', 30);
            $table->string('model', 80)->nullable();
            // Encrypted at rest (the model casts them): proposed values can be personal data.
            $table->text('proposed')->nullable();
            $table->text('confirmed')->nullable();
            $table->string('error_code', 40)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['attachment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachment_extractions');
        Schema::dropIfExists('attachments');
    }
};
