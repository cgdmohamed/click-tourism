<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inter-branch fixed-asset transfers post one balanced journal per branch
     * (Odoo-style due-from / due-to). Additive only; existing transfers keep
     * null journals (they predate the posting and were never rewritten).
     */
    public function up(): void
    {
        Schema::table('fixed_asset_transfers', function (Blueprint $table) {
            $table->foreignId('source_journal_entry_id')->nullable()->after('reason')
                ->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('destination_journal_entry_id')->nullable()->after('source_journal_entry_id')
                ->constrained('journal_entries')->nullOnDelete();
            $table->bigInteger('cost_minor')->default(0)->after('destination_journal_entry_id');
            $table->bigInteger('accumulated_minor')->default(0)->after('cost_minor');
            $table->timestamp('reversed_at')->nullable()->after('accumulated_minor');
            $table->foreignId('reversed_by')->nullable()->after('reversed_at')
                ->constrained('users')->nullOnDelete();
            $table->string('reversal_reason')->nullable()->after('reversed_by');
        });
    }

    public function down(): void
    {
        Schema::table('fixed_asset_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_journal_entry_id');
            $table->dropConstrainedForeignId('destination_journal_entry_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['cost_minor', 'accumulated_minor', 'reversed_at', 'reversal_reason']);
        });
    }
};
