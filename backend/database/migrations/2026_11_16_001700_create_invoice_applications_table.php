<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Settles an issued invoice with part or all of a posted receipt (customer
     * invoices) or a supplier advance (supplier invoices). Each row posts one
     * journal entry that moves the amount from the advance account to the
     * party's own receivable/payable subaccount.
     */
    public function up(): void
    {
        Schema::create('invoice_applications', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->foreignId('supplier_payment_id')->nullable()->constrained('supplier_payments')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['payment_id']);
            $table->index(['supplier_payment_id']);
            $table->index(['invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_applications');
    }
};
