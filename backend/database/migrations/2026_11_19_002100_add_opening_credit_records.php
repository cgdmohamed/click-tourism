<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Credit-side opening balances (customer credit / supplier advance) become
 * ordinary unapplied receipts and supplier payments flagged `is_opening`, so
 * they can be applied to invoices. They moved no cash, therefore the cash
 * account becomes optional on these two tables; normal receipts and payments
 * keep requiring one in their services. Opening documents and records also
 * point back at the batch line they came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->boolean('is_opening')->default(false);
            $table->foreignId('opening_balance_line_id')->nullable()->constrained('opening_balance_lines')->nullOnDelete();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('cash_account_id')->nullable()->change();
        });

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->boolean('is_opening')->default(false);
            $table->foreignId('opening_balance_line_id')->nullable()->constrained('opening_balance_lines')->nullOnDelete();
        });
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->foreignId('cash_account_id')->nullable()->change();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('opening_balance_line_id')->nullable()->constrained('opening_balance_lines')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Opening records have no cash account and cannot be expressed in the
        // old schema; refuse instead of silently turning them into receipts.
        foreach (['payments', 'supplier_payments'] as $name) {
            if (DB::table($name)->where('is_opening', true)->exists()) {
                throw new RuntimeException("Cannot roll back: {$name} holds opening-balance credit records. Reverse those opening batches first.");
            }
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opening_balance_line_id');
        });

        foreach (['supplier_payments', 'payments'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('opening_balance_line_id');
                $table->dropColumn('is_opening');
            });
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('cash_account_id')->nullable(false)->change();
            });
        }
    }
};
