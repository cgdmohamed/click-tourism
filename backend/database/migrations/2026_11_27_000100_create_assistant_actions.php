<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0023: actions the assistant prepares and the user confirms, and the marker that shows a
 * record was created through the assistant. Additive only.
 */
return new class extends Migration
{
    private const MARKED = ['leads', 'customers', 'suppliers', 'invoices', 'expenses', 'bookings'];

    public function up(): void
    {
        Schema::create('assistant_actions', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('tool', 60);
            $table->text('payload'); // encrypted: what the user is asked to confirm
            $table->string('status', 15)->default('pending'); // pending|confirmed|rejected|failed|expired
            $table->timestamp('expires_at');
            $table->string('entity', 20)->nullable();
            $table->string('entity_id', 26)->nullable();
            $table->string('error_code', 40)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->text('actions')->nullable(); // encrypted list of action public ids
        });

        foreach (self::MARKED as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('created_via', 20)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::MARKED as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('created_via');
            });
        }
        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->dropColumn('actions');
        });
        Schema::dropIfExists('assistant_actions');
    }
};
