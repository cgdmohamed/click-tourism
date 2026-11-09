<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('type', 20)->default('individual');
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('tax_id', 50)->nullable();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->bigInteger('credit_limit_minor')->default(0);
            $table->char('currency_code', 3)->default('EGP');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['phone']);
            $table->index(['email']);
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('category', 30)->default('other');
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->char('settlement_currency', 3)->default('EGP');
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tax_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('rate', 9, 4)->default(0);
            $table->boolean('is_inclusive')->default(false);
            $table->foreignId('sales_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('purchase_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_profiles');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('customers');
    }
};
