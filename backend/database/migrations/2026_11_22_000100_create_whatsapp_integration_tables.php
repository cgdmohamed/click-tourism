<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive tables for the optional Meta / WhatsApp integrations. No existing
 * column is altered except two nullable diagnostics columns on webhook_events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('direction', 3); // in | out
            // Provider message id (wamid...). Unique so webhook replays are no-ops.
            $table->string('wa_message_id', 191)->nullable()->unique();
            // Client supplied key; makes the outbound enqueue idempotent.
            $table->string('idempotency_key', 128)->nullable()->unique();
            // Digits only (no "+"), e.g. 201000000000.
            $table->string('phone', 32)->index();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20)->default('text'); // text | template | other
            $table->text('body')->nullable();
            $table->string('template_name', 120)->nullable();
            $table->string('template_language', 20)->nullable();
            $table->json('template_components')->nullable();
            // received | queued | sent | delivered | read | failed
            $table->string('status', 20)->default('queued')->index();
            $table->string('error_code', 40)->nullable();
            $table->string('error_message', 255)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('status_updated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['lead_id', 'created_at']);
            $table->index(['phone', 'direction', 'created_at']);
        });

        // Explicit WhatsApp opt-in / opt-out per phone number. Integration-owned
        // so customers/leads are not altered. The latest row per phone wins.
        Schema::create('messaging_consents', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 20)->default('whatsapp');
            $table->string('phone', 32);
            $table->string('status', 10); // opt_in | opt_out
            $table->string('source', 40); // e.g. lead_form, in_person, inbound_stop, manual
            $table->string('note', 255)->nullable();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['channel', 'phone', 'recorded_at']);
        });

        Schema::table('webhook_events', function (Blueprint $table) {
            $table->timestamp('processed_at')->nullable();
            $table->string('last_error', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropColumn(['processed_at', 'last_error']);
        });
        Schema::dropIfExists('messaging_consents');
        Schema::dropIfExists('whatsapp_messages');
    }
};
