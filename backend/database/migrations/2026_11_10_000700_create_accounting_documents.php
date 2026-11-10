<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prompt 22 — Accounting Documents, Vouchers & Approval Workflow.
     *
     * Traceable financial source documents (receipt, payment, transfer,
     * adjustment, refund vouchers) that post through the existing
     * PostingService only when approved. Documents carry their own immutable
     * identifier separate from the journal reference.
     */
    public function up(): void
    {
        Schema::create('accounting_documents', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('type', 30); // receipt_voucher|payment_voucher|transfer_voucher|adjustment_voucher|refund_voucher
            $table->string('series', 10);
            $table->string('number', 40);
            $table->string('party_type', 30)->nullable(); // customer|supplier|employee
            $table->unsignedBigInteger('party_id')->nullable();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->restrictOnDelete();
            $table->string('cash_account_id')->nullable();
            $table->char('currency_code', 3);
            $table->decimal('fx_rate', 20, 10)->default(1);
            $table->date('fx_rate_date');
            $table->string('fx_rate_source', 50)->default('manual');
            $table->string('status', 20)->default('draft'); // draft|submitted|approved|posted|voided|rejected|cancelled
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->constrained('accounting_documents')->restrictOnDelete();
            $table->string('reversal_reason')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->string('memo')->nullable();
            $table->unique(['branch_id', 'series', 'number']);
            $table->index(['party_type', 'party_id', 'status']);
            $table->index(['branch_id', 'created_at']);
            $table->timestamps();
        });

        Schema::create('accounting_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accounting_document_id')->constrained('accounting_documents')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('description')->nullable();
            $table->bigInteger('debit_minor')->default(0);
            $table->bigInteger('credit_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->foreignId('tax_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('party_type', 30)->nullable();
            $table->unsignedBigInteger('party_id')->nullable();
            $table->timestamps();

            $table->index(['party_type', 'party_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_document_lines');
        Schema::dropIfExists('accounting_documents');
    }
};
