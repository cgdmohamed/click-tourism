<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Bank reconciliation (Prompt 23, TE-023).
 *
 * Additive migration: journal lines gain a ULID `public_id` so reconciliation
 * matches can reference book ledger lines without exposing internal ids.
 * Existing rows are backfilled; nothing is renamed or dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->ulid('public_id')->nullable()->unique()->after('id');
        });

        DB::table('journal_lines')
            ->whereNull('public_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('journal_lines')
                        ->where('id', $row->id)
                        ->update(['public_id' => (string) Str::ulid()]);
                }
            });

        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('filename');
            $table->string('file_hash', 64);
            $table->string('format', 10)->default('csv');
            $table->date('period_from');
            $table->date('period_to');
            $table->bigInteger('opening_balance_minor')->default(0);
            $table->bigInteger('closing_balance_minor')->default(0);
            $table->char('currency_code', 3);
            $table->unsignedInteger('line_count')->default(0);
            $table->json('mapping')->nullable();
            $table->timestamps();

            // Same file may never be imported twice for the same account.
            $table->unique(['cash_account_id', 'file_hash']);
            $table->index(['cash_account_id', 'period_from', 'period_to']);
        });

        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('bank_statement_id')->constrained('bank_statements')->cascadeOnDelete();
            // Denormalised for account-wide de-duplication and matching queries.
            $table->foreignId('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->string('line_hash', 64);
            $table->date('line_date');
            $table->date('value_date')->nullable();
            // Signed: positive = money in (deposit), negative = money out.
            $table->bigInteger('amount_minor');
            $table->bigInteger('balance_after_minor')->nullable();
            $table->string('bank_reference')->nullable();
            $table->string('description')->nullable();
            $table->string('party_name')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            // Duplicate statement lines (overlapping imports) are skipped.
            $table->unique(['cash_account_id', 'line_hash']);
            $table->index(['bank_statement_id', 'line_date']);
        });

        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('bank_statement_id')->nullable()->constrained('bank_statements')->restrictOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->char('currency_code', 3);
            $table->bigInteger('opening_book_balance_minor');
            $table->bigInteger('opening_bank_balance_minor');
            $table->bigInteger('closing_bank_balance_minor');
            $table->bigInteger('closing_book_balance_minor');
            $table->string('status', 20)->default('open');
            $table->json('adjustment_document_ids')->nullable();
            $table->json('snapshot')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['cash_account_id', 'status']);
            $table->index(['period_from', 'period_to']);
        });

        Schema::create('bank_reconciliation_matches', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('bank_reconciliation_id')->constrained('bank_reconciliations')->cascadeOnDelete();
            // Rows sharing a `match_group` form one (possibly many-to-many) match.
            $table->ulid('match_group');
            $table->foreignId('bank_statement_line_id')->constrained('bank_statement_lines')->restrictOnDelete();
            $table->foreignId('journal_line_id')->constrained('journal_lines')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->string('kind', 10)->default('manual');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['bank_statement_line_id', 'journal_line_id']);
            $table->index(['bank_reconciliation_id', 'match_group']);
            $table->index(['journal_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_matches');
        Schema::dropIfExists('bank_reconciliations');
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_statements');

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
