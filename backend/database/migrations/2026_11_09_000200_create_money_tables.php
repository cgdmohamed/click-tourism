<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->char('code', 3)->primary();
            $table->string('name');
            $table->string('symbol', 10)->nullable();
            $table->unsignedTinyInteger('exponent')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('fx_rates', function (Blueprint $table) {
            $table->id();
            $table->char('currency_code', 3);
            $table->decimal('rate', 20, 10);
            $table->date('rate_date');
            $table->string('source', 50)->default('manual');
            $table->timestamps();
            $table->foreign('currency_code')->references('code')->on('currencies')->cascadeOnDelete();
            $table->unique(['currency_code', 'rate_date', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rates');
        Schema::dropIfExists('currencies');
    }
};
