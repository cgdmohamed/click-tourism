<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payment_applications', function (Blueprint $table) {
            $table->ulid('public_id')->nullable()->unique();
        });

        // Historical application rows were created without public identifiers.
        // Backfill them without changing amounts, source IDs or journal entries.
        DB::table('supplier_payment_applications')->orderBy('id')->chunkById(500, function ($applications) {
            foreach ($applications as $application) {
                DB::table('supplier_payment_applications')
                    ->where('id', $application->id)
                    ->update(['public_id' => (string) Str::ulid()]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payment_applications', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
