<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DEC-025 (owner decision 2026-11-14): a negative-margin booking only warns. An installation that
 * still holds the old default ('true') is switched to 'false'; the approval flow stays available
 * for anyone who turns the setting on again.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('group', 'accounting')->where('key', 'negative_margin_requires_approval')->where('value', 'true')->update(['value' => 'false']);
    }

    public function down(): void
    {
        DB::table('settings')->where('group', 'accounting')->where('key', 'negative_margin_requires_approval')->where('value', 'false')->update(['value' => 'true']);
    }
};
