<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fixed-asset review (2026-11-11), additive only.
     *
     * - acquisition_journal_entry_id: a posted entry (supplier bill, expense,
     *   opening balance) that already debited the cost account. Capitalization
     *   then links to it instead of posting a second Dr cost / Cr offset.
     * - disposal_depreciation_minor: depreciation charged up to the disposal
     *   date inside the disposal entry (Odoo / Zoho depreciate to the sale date).
     */
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->foreignId('acquisition_journal_entry_id')->nullable()->after('disposal_journal_entry_id')
                ->constrained('journal_entries')->nullOnDelete();
            $table->bigInteger('disposal_depreciation_minor')->default(0)->after('disposal_gain_loss_minor');
        });
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acquisition_journal_entry_id');
            $table->dropColumn('disposal_depreciation_minor');
        });
    }
};
