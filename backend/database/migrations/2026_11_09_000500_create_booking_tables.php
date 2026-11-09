<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('number', 40);
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->char('currency_code', 3);
            $table->decimal('fx_rate', 20, 10)->default(1);
            $table->date('fx_rate_date');
            $table->string('fx_rate_source', 50)->default('manual');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->unsignedSmallInteger('pax_adults')->default(1);
            $table->unsignedSmallInteger('pax_children')->default(0);
            $table->unsignedSmallInteger('pax_infants')->default(0);
            $table->bigInteger('total_sell_minor')->default(0);
            $table->bigInteger('total_cost_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->string('notes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->bigInteger('cancellation_fee_minor')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'number']);
            $table->index(['branch_id', 'status', 'start_date']);
            $table->index(['customer_id']);
        });

        Schema::create('booking_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('service_type', 20);
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->bigInteger('unit_cost_minor')->default(0);
            $table->bigInteger('unit_sell_minor')->default(0);
            $table->char('cost_currency', 3);
            $table->char('sell_currency', 3);
            $table->foreignId('tax_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('tax_rate_snapshot', 9, 4)->nullable();
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_sell_minor')->default(0);
            $table->bigInteger('total_cost_minor')->default(0);
            $table->timestamps();
        });

        Schema::create('booking_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_status_history');
        Schema::dropIfExists('booking_items');
        Schema::dropIfExists('bookings');
    }
};
