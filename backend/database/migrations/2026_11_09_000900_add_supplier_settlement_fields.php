<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier settlement (TE-031): traceable FX on supplier payments, request
 * idempotency, and per-obligation applications so one advance can settle
 * several obligations without mutating the original payment document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->decimal('fx_rate', 20, 10)->default(1)->after('currency_code');
            $table->date('fx_rate_date')->nullable()->after('fx_rate');
            $table->string('fx_rate_source', 50)->default('manual')->after('fx_rate_date');
            $table->string('idempotency_key')->nullable()->after('status');
            $table->foreignId('created_by')->nullable()->after('idempotency_key')->constrained('users')->nullOnDelete();
            $table->index(['branch_id', 'paid_at']);
        });

        Schema::table('supplier_obligations', function (Blueprint $table) {
            $table->index(['supplier_id', 'status']);
            // A supplier invoice must not be recorded twice. Nullable references
            // still allow several obligations without an invoice number.
            $table->unique(['supplier_id', 'invoice_reference']);
        });

        Schema::create('supplier_payment_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_obligation_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->timestamps();

            $table->index(['supplier_obligation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_applications');

        Schema::table('supplier_obligations', function (Blueprint $table) {
            $table->dropUnique(['supplier_id', 'invoice_reference']);
            $table->dropIndex(['supplier_id', 'status']);
        });

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropIndex(['branch_id', 'paid_at']);
            $table->dropColumn(['fx_rate', 'fx_rate_date', 'fx_rate_source', 'idempotency_key', 'created_by']);
        });
    }
};
