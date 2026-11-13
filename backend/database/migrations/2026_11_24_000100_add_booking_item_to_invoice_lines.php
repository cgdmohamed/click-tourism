<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DEC-002: a supplier invoice line may name the booking service it pays for, so a booking can
 * show the recognized cost per service. Nullable and additive; existing invoices are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->foreignId('booking_item_id')->nullable()->after('account_id')->constrained('booking_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booking_item_id');
        });
    }
};
