<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** ADR-0029: a short unique code (C-0001, S-0001) so two parties with the same name can be told apart. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['customers' => 'C', 'suppliers' => 'S'] as $table => $prefix) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('code', 20)->nullable()->after('public_id');
            });

            $n = 0;
            DB::table($table)->orderBy('id')->select('id')->each(function ($row) use ($table, $prefix, &$n) {
                DB::table($table)->where('id', $row->id)->update(['code' => sprintf('%s-%04d', $prefix, ++$n)]);
            });

            Schema::table($table, function (Blueprint $t) {
                $t->unique('code');
            });
        }
    }

    public function down(): void
    {
        foreach (['customers', 'suppliers'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropUnique("{$table}_code_unique");
                $t->dropColumn('code');
            });
        }
    }
};
