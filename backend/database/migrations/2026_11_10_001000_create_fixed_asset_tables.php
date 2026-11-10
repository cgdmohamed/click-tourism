<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prompt 25 — Fixed assets and depreciation subledger.
     *
     * The register is the source of truth for cost, useful life and residual
     * value; depreciation runs only ever produce journals through the existing
     * PostingService. A posted depreciation item is unique per asset and
     * period, which is the hard guard against duplicate or concurrent runs.
     */
    public function up(): void
    {
        Schema::create('fixed_asset_categories', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code', 40)->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->foreignId('asset_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('accumulated_depreciation_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('depreciation_expense_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('disposal_gain_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('disposal_loss_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->unsignedSmallInteger('default_useful_life_months')->nullable();
            $table->bigInteger('default_residual_value_minor')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code', 60)->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('fixed_asset_category_id')->constrained('fixed_asset_categories')->restrictOnDelete();
            $table->string('description');
            $table->string('location')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('purchase_date')->nullable();
            $table->date('capitalization_date');
            $table->date('in_service_date');
            $table->char('currency_code', 3);
            $table->decimal('fx_rate', 20, 10)->default(1);
            $table->date('fx_rate_date');
            $table->string('fx_rate_source', 50)->default('manual');
            $table->bigInteger('acquisition_value_minor');
            $table->bigInteger('acquisition_value_base_minor');
            $table->bigInteger('residual_value_minor')->default(0);
            $table->unsignedSmallInteger('useful_life_months');
            $table->string('depreciation_method', 30)->default('straight_line');
            $table->string('depreciation_convention', 30)->default('month_of_purchase');
            $table->date('depreciation_start_on');
            $table->string('status', 20)->default('draft'); // draft|active|fully_depreciated|disposed
            $table->bigInteger('accumulated_depreciation_minor')->default(0);
            $table->bigInteger('impairment_minor')->default(0);
            $table->string('last_depreciated_period', 7)->nullable();
            $table->date('disposed_on')->nullable();
            $table->string('disposal_type', 20)->nullable(); // sale|retirement|scrap
            $table->bigInteger('disposal_proceeds_minor')->default(0);
            $table->bigInteger('disposal_gain_loss_minor')->default(0);
            $table->foreignId('capitalization_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('disposal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->json('attachments')->nullable();
            $table->json('impairment_document_ids')->nullable();
            $table->json('applied_impairment_document_ids')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['fixed_asset_category_id', 'status']);
            $table->index(['cost_center_id', 'status']);
        });

        Schema::create('fixed_asset_depreciation_runs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('number', 40);
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->char('period', 7); // YYYY-MM
            $table->date('run_date');
            $table->string('status', 20)->default('draft'); // draft|posted|failed
            $table->unsignedInteger('assets_count')->default(0);
            $table->bigInteger('total_amount_minor')->default(0);
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->string('failure_reason')->nullable();
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'number']);
            $table->index(['branch_id', 'period', 'status']);
        });

        Schema::create('fixed_asset_depreciation_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('fixed_asset_depreciation_run_id')->constrained('fixed_asset_depreciation_runs')->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->restrictOnDelete();
            $table->char('period', 7);
            $table->bigInteger('amount_minor');
            $table->bigInteger('accumulated_before_minor');
            $table->bigInteger('accumulated_after_minor');
            $table->bigInteger('carrying_before_minor');
            $table->bigInteger('carrying_after_minor');
            $table->string('status', 20)->default('pending'); // pending|posted|failed
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            // The hard duplicate guard: one depreciation posting per asset period.
            $table->unique(['fixed_asset_id', 'period']);
            $table->index(['fixed_asset_depreciation_run_id', 'status']);
        });

        Schema::create('fixed_asset_transfers', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
            $table->foreignId('from_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('to_branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('from_cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->foreignId('to_cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->foreignId('from_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('to_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('transfer_date');
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['fixed_asset_id', 'transfer_date']);
            $table->index(['to_branch_id', 'transfer_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_transfers');
        Schema::dropIfExists('fixed_asset_depreciation_items');
        Schema::dropIfExists('fixed_asset_depreciation_runs');
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('fixed_asset_categories');
    }
};
