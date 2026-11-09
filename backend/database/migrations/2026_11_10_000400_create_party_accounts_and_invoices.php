<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prompt 26 + 27: party subaccounts and the customer/supplier invoice lifecycle.
     *
     * Every customer/supplier may be provisioned a genuine posting-capable child
     * account in the chart of accounts (Prompt 27). Invoices (Prompt 26) post
     * through PostingService to those subaccounts, keeping one immutable ledger.
     */
    public function up(): void
    {
        Schema::create('party_accounts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('party_type', 20); // customer|supplier|employee
            $table->unsignedBigInteger('party_id');
            $table->string('role', 20); // receivable|payable|advance|custody|payable_balance
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->unique(['party_type', 'party_id', 'role']);
            $table->index(['party_type', 'party_id']);
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('type', 30); // customer_invoice|supplier_invoice|credit_note
            $table->string('series', 10);
            $table->string('number', 40);
            $table->string('party_type', 30)->nullable(); // customer|supplier
            $table->unsignedBigInteger('party_id')->nullable();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->restrictOnDelete();
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->char('currency_code', 3);
            $table->decimal('fx_rate', 20, 10)->default(1);
            $table->date('fx_rate_date');
            $table->string('fx_rate_source', 50)->default('manual');
            $table->string('status', 20)->default('draft'); // draft|submitted|approved|issued|voided
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('outstanding_minor')->default(0);
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('credit_note_of_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->string('idempotency_key')->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->string('memo')->nullable();
            $table->unique(['branch_id', 'series', 'number']);
            $table->index(['party_type', 'party_id', 'status']);
            $table->index(['branch_id', 'issue_date']);
            $table->timestamps();
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('description')->nullable();
            $table->bigInteger('debit_minor')->default(0);
            $table->bigInteger('credit_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->foreignId('tax_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('party_type', 30)->nullable();
            $table->unsignedBigInteger('party_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('party_accounts');
    }
};
