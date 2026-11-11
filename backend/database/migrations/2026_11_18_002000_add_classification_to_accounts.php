<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit balance-sheet classification per account (Odoo / Zoho Books model).
 * Additive and nullable (null = unclassified). Existing rows are backfilled
 * ONCE from the former code-prefix rule so reports are identical after upgrade;
 * the column is the source of truth from then on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('classification', 30)->nullable()->after('type');
        });

        DB::table('accounts')->orderBy('id')->each(function ($account) {
            $classification = $this->classify((string) $account->type, (string) $account->code);
            if ($classification !== null) {
                DB::table('accounts')->where('id', $account->id)->update(['classification' => $classification]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('classification');
        });
    }

    /** Frozen copy of the rule at the time of this migration (not the live model). */
    private function classify(string $type, string $code): ?string
    {
        if ($type === 'equity') {
            return 'equity';
        }
        if ($type === 'liability') {
            return preg_match('/^2[1-4]/', $code) === 1 ? 'current_liability' : 'non_current_liability';
        }
        if ($type !== 'asset') {
            return null;
        }
        if (in_array($code, ['1150', '1151', '1152', '1153', '1154'], true)) {
            return 'prepayment';
        }
        if (str_starts_with($code, '11')) {
            return 'current_asset';
        }
        if (str_starts_with($code, '12') || str_starts_with($code, '13')) {
            return 'fixed_asset';
        }

        return 'non_current_asset';
    }
};
