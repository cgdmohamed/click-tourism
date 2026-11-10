<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prompt 24 — Accruals, prepayments and period-end adjustments.
     *
     * A schedule is the approved business document; its events are the
     * individual recognition/reversal postings. Posted events keep an
     * immutable link to the journal entry they created, so the schedule can
     * never be edited out of step with the ledger.
     */
    public function up(): void
    {
        Schema::create('accounting_schedules', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('number', 40);
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('kind', 30); // prepaid_expense|deferred_revenue|accrued_expense|accrued_revenue
            $table->string('status', 20)->default('draft'); // draft|active|completed|cancelled
            $table->string('description');
            $table->string('reference', 100)->nullable();
            $table->char('currency_code', 3);
            $table->decimal('fx_rate', 20, 10)->default(1);
            $table->date('fx_rate_date');
            $table->string('fx_rate_source', 50)->default('manual');
            $table->bigInteger('total_minor');
            $table->unsignedSmallInteger('periods');
            $table->string('frequency', 20)->default('monthly');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->foreignId('source_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('target_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('reversal_policy', 20)->default('none'); // none|next_period
            $table->bigInteger('recognized_minor')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->json('adjustment_document_ids')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'number']);
            $table->index(['status', 'kind']);
            $table->index(['branch_id', 'ends_on']);
        });

        Schema::create('accounting_schedule_events', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('accounting_schedule_id')->constrained('accounting_schedules')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('kind', 20); // recognition|reversal
            $table->foreignId('reverses_event_id')->nullable()->constrained('accounting_schedule_events')->nullOnDelete();
            $table->string('status', 20)->default('pending'); // pending|posted|failed|cancelled
            $table->date('due_date');
            $table->bigInteger('amount_minor');
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->string('failure_reason')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['accounting_schedule_id', 'kind', 'sequence']);
            $table->index(['status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_schedule_events');
        Schema::dropIfExists('accounting_schedules');
    }
};
