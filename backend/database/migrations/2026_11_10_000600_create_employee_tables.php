<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prompt 21 — Employee Accounts, Advances & Custody.
     *
     * Employee master (distinct from login users) plus an employee financial
     * subledger. Every employee movement posts through PostingService to a
     * genuine posting-capable subaccount provisioned via PartyAccount
     * (advance / custody / payable_balance roles). Employee advances and
     * custody are tracked as employee subledger transactions with references,
     * reconcilable to the GL control accounts.
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('internal_code', 40)->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('category', 30)->default('staff'); // staff|executive|driver|other
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // optional linked login
            $table->string('phone', 30)->nullable();
            $table->string('email', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['branch_id']);
            $table->index(['category']);
            $table->index(['is_active']);
        });

        Schema::create('employee_transactions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('type', 30); // advance_request|advance_disbursed|advance_settled|custody_issued|custody_cleared|custody_returned|commission_paid|adjustment
            $table->string('reference')->nullable(); // invoice/receipt/payment/journal reference
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('source_id')->nullable(); // advance or custody record id
            $table->string('source_type', 30)->nullable(); // advance|custody
            $table->date('transaction_date');
            $table->char('currency_code', 3);
            $table->decimal('fx_rate', 20, 10)->default(1);
            $table->date('fx_rate_date');
            $table->bigInteger('amount_minor'); // signed: positive = employee owes company, negative = company owes employee
            $table->bigInteger('balance_minor'); // running employee balance after this transaction
            $table->string('memo')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'transaction_date']);
            $table->index(['branch_id']);
            $table->index(['type']);
        });

        Schema::create('employee_advances', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_minor'); // total advance amount
            $table->bigInteger('outstanding_minor'); // still owed by employee
            $table->string('status', 20)->default('requested'); // requested|approved|disbursed|settled|cancelled
            $table->foreignId('disbursement_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('cash_account_id')->nullable()->constrained('accounts')->restrictOnDelete(); // cash given to employee
            $table->date('request_date');
            $table->date('disbursed_date')->nullable();
            $table->date('settled_date')->nullable();
            $table->string('memo')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['branch_id']);
        });

        Schema::create('employee_custodies', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_minor'); // custody value issued
            $table->bigInteger('outstanding_minor'); // still outstanding
            $table->string('status', 20)->default('issued'); // issued|cleared|returned|closed
            $table->foreignId('issuance_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('cash_account_id')->nullable()->constrained('accounts')->restrictOnDelete(); // float given
            $table->string('item_description')->nullable(); // e.g. petty cash float, corporate card
            $table->date('issue_date');
            $table->date('returned_date')->nullable();
            $table->string('memo')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_custodies');
        Schema::dropIfExists('employee_advances');
        Schema::dropIfExists('employee_transactions');
        Schema::dropIfExists('employees');
    }
};
