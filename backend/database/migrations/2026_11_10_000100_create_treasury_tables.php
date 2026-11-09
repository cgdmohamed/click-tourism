<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_accounts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('account_id')->unique()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind', 10);
            $table->string('name_ar');
            $table->string('name_en');
            $table->char('currency_code', 3);
            $table->string('bank_name')->nullable();
            $table->string('account_last4', 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('number', 40);
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignId('to_cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->date('transfer_date');
            $table->char('from_currency', 3);
            $table->bigInteger('from_amount_minor');
            $table->decimal('from_fx_rate', 20, 10)->default(1);
            $table->char('to_currency', 3);
            $table->bigInteger('to_amount_minor');
            $table->decimal('to_fx_rate', 20, 10)->default(1);
            $table->bigInteger('fee_minor')->default(0);
            $table->string('memo')->nullable();
            $table->string('status', 20)->default('posted');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'transfer_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfers');
        Schema::dropIfExists('cash_accounts');
    }
};
