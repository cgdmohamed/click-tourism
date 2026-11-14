<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files a user attaches to one assistant message (ADR-0028): a receipt, a transfer slip, an invoice
 * photo. They are short-lived (hours), private to the user and never attached to a record; the
 * assistant reads them and prepares an action the user still has to confirm. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_uploads', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('mime', 60);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('stored_path', 255);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_uploads');
    }
};
