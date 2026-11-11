<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank reconciliation review: remember which statement lines each proposed
 * adjustment voucher covers ({document public id: [statement line public ids]})
 * so the same bank charge cannot be proposed (and later posted) twice.
 *
 * Additive and nullable; existing sessions keep working without it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_reconciliations', function (Blueprint $table) {
            $table->json('adjustment_line_map')->nullable()->after('adjustment_document_ids');
        });
    }

    public function down(): void
    {
        Schema::table('bank_reconciliations', function (Blueprint $table) {
            $table->dropColumn('adjustment_line_map');
        });
    }
};
