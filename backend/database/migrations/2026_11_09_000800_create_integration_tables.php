<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('external_event_id', 120);
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('received');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
            $table->unique(['provider', 'external_event_id']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('key', 128);
            $table->string('method', 10);
            $table->string('path', 255);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->string('resource_type', 60)->nullable();
            $table->string('resource_id', 60)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['actor_id', 'key']);
        });

        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->ulid('event_id')->unique();
            $table->string('type', 100);
            $table->unsignedSmallInteger('version')->default(1);
            $table->json('payload');
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
            $table->index(['published_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('webhook_events');
    }
};
