<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** ADR-0025: background report exports. A file exists only for a job that succeeded. Additive. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('report', 40);
            $table->string('format', 5);
            $table->text('params'); // the report filters as sent (JSON)
            $table->string('status', 12)->default('queued'); // queued|running|succeeded|failed
            $table->string('file_path', 200)->nullable();
            $table->string('file_name', 200)->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_jobs');
    }
};
