<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tour_packages', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('code', 50);
            $table->string('name', 200);
            $table->string('category', 30)->default('other');
            $table->string('execution_type', 20)->default('fixed');
            $table->text('description')->nullable();
            $table->string('destination', 200)->nullable();
            $table->char('currency_code', 3)->default('EGP');
            $table->bigInteger('base_price_minor')->default(0);
            $table->bigInteger('estimated_cost_minor')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['branch_id', 'code']);
        });

        Schema::create('tour_departures', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tour_package_id')->constrained('tour_packages')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamps();
            $table->index(['branch_id', 'starts_on', 'status']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('tour_departure_id')->nullable()->after('branch_id')
                ->constrained('tour_departures')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->dropConstrainedForeignId('tour_departure_id'));
        Schema::dropIfExists('tour_departures');
        Schema::dropIfExists('tour_packages');
    }
};
