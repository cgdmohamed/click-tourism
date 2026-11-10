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
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->ulid('public_id')->nullable();
            $table->string('provider_name', 120)->nullable();
            $table->string('account_name', 160)->nullable();
            $table->string('account_reference', 80)->nullable();
            $table->string('iban', 34)->nullable();
            $table->text('instructions')->nullable();
        });

        DB::table('payment_methods')->whereNull('public_id')->orderBy('id')
            ->chunkById(100, function ($methods) {
                foreach ($methods as $method) {
                    DB::table('payment_methods')->where('id', $method->id)
                        ->update(['public_id' => (string) Str::ulid()]);
                }
            });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->unique('public_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropColumn([
                'public_id',
                'provider_name',
                'account_name',
                'account_reference',
                'iban',
                'instructions',
            ]);
        });
    }
};
