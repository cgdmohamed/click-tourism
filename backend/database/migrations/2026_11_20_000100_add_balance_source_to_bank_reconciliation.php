<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank reconciliation open items: remember where the statement / session bank
 * balances came from ({opening, closing}: explicit | statement | line_balance |
 * previous_session) so the UI can show it and approval can refuse unverified
 * balances. Additive and nullable; rows created before this migration keep
 * working (null = legacy, balances unverified).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statements', function (Blueprint $table) {
            $table->json('balance_source')->nullable()->after('closing_balance_minor');
        });

        Schema::table('bank_reconciliations', function (Blueprint $table) {
            $table->json('balance_source')->nullable()->after('closing_bank_balance_minor');
        });
    }

    public function down(): void
    {
        Schema::table('bank_reconciliations', function (Blueprint $table) {
            $table->dropColumn('balance_source');
        });

        Schema::table('bank_statements', function (Blueprint $table) {
            $table->dropColumn('balance_source');
        });
    }
};
