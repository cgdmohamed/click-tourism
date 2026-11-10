<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_available_for_leads')->default(true)->after('is_active');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->string('service_category', 40)->nullable()->after('external_id');
            $table->unsignedSmallInteger('reassignment_attempts')->default(0)->after('last_activity_at');
            $table->timestamp('escalated_at')->nullable()->after('reassignment_attempts');
            $table->index(['status', 'escalated_at'], 'leads_reassignment_queue_index');
            $table->index('service_category', 'leads_service_category_index');
        });

        Schema::table('assignment_rules', function (Blueprint $table) {
            $table->string('service_category', 40)->nullable()->after('source_id');
            $table->foreignId('target_user_id')->nullable()->after('strategy')->constrained('users')->nullOnDelete();
            $table->foreignId('last_assigned_user_id')->nullable()->after('target_user_id')->constrained('users')->nullOnDelete();
            $table->index('service_category', 'assignment_rules_service_category_index');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->string('margin_approval_status', 20)->nullable()->after('cancellation_fee_minor');
            $table->text('margin_approval_reason')->nullable()->after('margin_approval_status');
            $table->foreignId('margin_requested_by')->nullable()->after('margin_approval_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('margin_requested_at')->nullable()->after('margin_requested_by');
            $table->foreignId('margin_approved_by')->nullable()->after('margin_requested_at')->constrained('users')->nullOnDelete();
            $table->timestamp('margin_approved_at')->nullable()->after('margin_approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('margin_approved_by');
            $table->dropConstrainedForeignId('margin_requested_by');
            $table->dropColumn(['margin_approval_status', 'margin_approval_reason', 'margin_requested_at', 'margin_approved_at']);
        });

        Schema::table('assignment_rules', function (Blueprint $table) {
            $table->dropIndex('assignment_rules_service_category_index');
            $table->dropConstrainedForeignId('last_assigned_user_id');
            $table->dropConstrainedForeignId('target_user_id');
            $table->dropColumn('service_category');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_reassignment_queue_index');
            $table->dropIndex('leads_service_category_index');
            $table->dropColumn(['service_category', 'reassignment_attempts', 'escalated_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_available_for_leads');
        });
    }
};
