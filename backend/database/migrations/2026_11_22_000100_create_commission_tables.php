<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commission rules (versioned, effective-dated), commission accruals and
 * commission payments (settlement of the payable). Additive only: no existing
 * table is altered. Amounts are integer minor units in the base currency
 * (commissions are base-currency obligations); rates are basis points.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            // A rule is identified by code; every edit of an approved rule is a new version.
            $table->string('code', 40);
            $table->unsignedInteger('version')->default(1);
            $table->string('name_ar');
            $table->string('name_en');
            // draft|pending_approval|approved|rejected|retired
            $table->string('status', 20)->default('draft');
            $table->string('beneficiary_type', 10); // employee|agent|branch
            // employee.id | supplier.id (agent) | branch.id; null = booking owner (employee) / booking branch (branch).
            $table->unsignedBigInteger('beneficiary_id')->nullable();
            $table->string('basis', 20); // percent_revenue|percent_margin|fixed
            $table->unsignedInteger('rate_bp')->nullable(); // basis points, 10000 = 100%
            $table->bigInteger('fixed_minor')->nullable(); // base currency minor units
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('service_type', 20)->nullable();
            $table->boolean('allow_cross_branch')->default(false);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('commission_rules')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();

            $table->unique(['code', 'version']);
            $table->index(['status', 'effective_from']);
        });

        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('number', 40);
            $table->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('commission_rule_id')->constrained('commission_rules')->restrictOnDelete();
            // Snapshot of the rule as applied, so later rule edits never change an accrual.
            $table->string('rule_code', 40);
            $table->unsignedInteger('rule_version');
            $table->string('beneficiary_type', 10);
            $table->unsignedBigInteger('beneficiary_id');
            $table->string('basis', 20);
            $table->unsignedInteger('rate_bp')->nullable();
            $table->bigInteger('basis_amount_minor')->default(0);
            $table->bigInteger('calculated_minor');
            $table->bigInteger('override_minor')->nullable();
            $table->string('override_reason')->nullable();
            $table->foreignId('override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->bigInteger('amount_minor');
            $table->bigInteger('paid_minor')->default(0);
            $table->char('currency_code', 3);
            // accrued|approved|posted|paid|rejected|reversed|cancelled
            $table->string('status', 20)->default('accrued');
            // Unique while the accrual is live: prevents duplicate accruals per booking and beneficiary.
            $table->string('active_key', 80)->nullable()->unique();
            $table->date('accrued_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason')->nullable();
            // Set when a posted accrual must be reversed but the ledger refused (closed period, policy).
            $table->boolean('needs_reversal')->default(false);
            $table->timestamps();

            $table->index(['booking_id', 'status']);
            $table->index(['beneficiary_type', 'beneficiary_id']);
            $table->index(['branch_id', 'status']);
        });

        Schema::create('commission_payments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('number', 40);
            $table->foreignId('commission_id')->constrained('commissions')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->date('paid_on');
            $table->string('reference')->nullable();
            $table->string('status', 20)->default('posted');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['commission_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_payments');
        Schema::dropIfExists('commissions');
        Schema::dropIfExists('commission_rules');
    }
};
