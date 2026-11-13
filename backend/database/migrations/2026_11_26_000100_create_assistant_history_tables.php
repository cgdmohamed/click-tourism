<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0022: a user's own assistant conversations, kept so the chat page can list and reopen them.
 * Titles and message text are encrypted at rest (the models cast them); rows are deleted by the
 * user, by the retention setting (assistant:prune-history) or with the user. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->text('title');
            $table->timestamp('last_message_at')->index();
            $table->timestamps();

            $table->index(['user_id', 'branch_id', 'last_message_at'], 'assistant_conversations_listing');
        });

        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('assistant_conversations')->cascadeOnDelete();
            $table->string('role', 10); // user | assistant
            $table->text('content');
            $table->text('citations')->nullable();
            $table->boolean('truncated')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_conversations');
    }
};
