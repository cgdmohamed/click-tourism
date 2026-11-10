<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_obligations', function (Blueprint $table) {
            // Recognition is provided by the *issued invoice*, never a second
            // entry from the operational obligation.
            $table->foreignId('invoice_id')->nullable()->unique()
                ->constrained('invoices')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_obligations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
        });
    }
};
