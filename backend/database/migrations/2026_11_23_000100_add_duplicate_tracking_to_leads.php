<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GAP-CRM-04: a capture that matches an existing lead by normalized
     * phone/email inside the configured window is kept but flagged and
     * linked to the earlier lead. Additive and nullable, so existing leads
     * stay unlinked.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('duplicate_of_lead_id')->nullable()->after('converted_booking_id')
                ->constrained('leads')->nullOnDelete();
            $table->string('duplicate_reason', 20)->nullable()->after('duplicate_of_lead_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('duplicate_of_lead_id');
            $table->dropColumn('duplicate_reason');
        });
    }
};
